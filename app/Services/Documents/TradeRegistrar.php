<?php

namespace App\Services\Documents;

use App\Models\Contact;
use App\Models\DocumentType;
use App\Models\FixedAsset;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Tax;
use App\Models\Warehouse;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerEntry;
use App\Services\Inventory\InventoryService;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra los documentos con items (ventas y compras): recalcula los valores en el servidor con las
 * mismas reglas del formulario, guarda el documento, mueve inventario, actualiza saldos y contabiliza.
 */
class TradeRegistrar
{
    private const SALES = ['FV', 'NC', 'ND', 'CT', 'RM'];

    /** Documentos que no contabilizan. */
    private const NO_LEDGER = ['CT', 'RM', 'OC'];

    private ?int $warehouseId = null;

    public function __construct(
        private readonly Numbering $numbering,
        private readonly AccountResolver $accounts,
        private readonly InventoryService $inventory,
    ) {}

    /** @param array<string, mixed> $form */
    public function register(array $form, array $data, array $fallbacks = []): SalesDocument|PurchaseDocument
    {
        $kind = $form['doc'];
        $purchase = ! in_array($kind, self::SALES, true);
        $this->numbering->assertOpen($data['date']);
        $type = $this->numbering->type($data['document_type'] ?? null, $kind, $fallbacks);
        $contact = Contact::query()->findOrFail($data['contact_id']);
        $this->warehouseId = ! empty($data['warehouse_id'])
            ? (int) Warehouse::query()->where('active', true)->whereKey((int) $data['warehouse_id'])->value('id') ?: null
            : null;
        $calc = $this->calculate($data, $purchase);
        if (! $purchase) {
            if (! empty($data['principal'])) {
                $data['principal'] = Mandate::principal($data['principal']);
            }
            if (! empty($data['external_invoice'])) {
                $data['external_invoice'] = Mandate::externalInvoice($data['external_invoice']);
                $data['related_name'] = $data['related_name'] ?? $data['external_invoice']['numero'];
            }
            Mandate::assertReady($kind, $type, $calc['lines'], $data);
        }
        $related = $this->related($form, $data, $purchase);

        $payments = $this->payments($form, $data, $calc['total'], $purchase, $contact);

        return DB::transaction(function () use ($kind, $purchase, $type, $contact, $calc, $related, $payments, $data) {
            [$number, $name] = $this->numbering->take($type, $purchase ? 'purchase_documents' : 'sales_documents');
            $credit = round(collect($payments)->where('credit', true)->sum('value'), 2);
            $dueDate = collect($payments)->where('credit', true)->max('due_date') ?? $data['date'];

            $doc = $purchase
                ? $this->createPurchase($kind, $type, $number, $name, $contact, $calc, $related, $data, $credit, $dueDate)
                : $this->createSale($kind, $type, $number, $name, $contact, $calc, $related, $data, $credit, $dueDate);

            $this->saveItems($doc, $calc, $purchase, $data);
            $this->savePayments($doc, $payments, $purchase);
            $this->applyToRelated($kind, $related, $credit, $doc);
            $costs = $this->moveInventory($kind, $doc, $calc, $data['date']);

            if (! in_array($kind, self::NO_LEDGER, true)) {
                $this->post($kind, $doc, $contact, $calc, $payments, $costs, $purchase);
            }

            return $doc;
        });
    }

    /**
     * Base y total de una linea: el total con impuesto se redondea una sola vez y el impuesto es la diferencia,
     * asi un precio con IVA incluido (base a 6 decimales) da exactamente el precio de lista por la cantidad.
     *
     * @return array{float, float}
     */
    public static function split(float $net, float $rate, bool $included): array
    {
        return $included
            ? [round($net / (1 + $rate), 2), round($net, 2)]
            : [round($net, 2), round($net * (1 + $rate), 2)];
    }

    /** Calculo de lineas, impuestos y retenciones (Trade.vue: lineCalc / totals). */
    public function calculate(array $data, bool $purchase): array
    {
        $checks = (array) ($data['checks'] ?? []);
        $percent = ! $purchase || ! empty($checks['Descuento en porcentaje']);
        $included = ! empty($checks['IVA / Impoconsumo incluido']) || ! empty($checks['Impuestos cargo incluidos']);
        $items = collect($data['items'] ?? [])->filter(function ($i) {
            if ((float) ($i['quantity'] ?? 0) <= 0) {
                return false;
            }

            return ($i['product_id'] ?? $i['account_id'] ?? $i['fixed_asset_id'] ?? null)
                || ! empty($i['free'])
                || in_array($i['role'] ?? '', ['mandante', 'mandatario'], true);
        })->values();
        $taxIds = $items->pluck('tax_id')->merge($items->pluck('withholding_id'))->merge(array_values((array) ($data['summary_retentions'] ?? [])))->filter()->unique();
        $taxes = Tax::query()->whereIn('id', $taxIds)->get()->keyBy('id');
        $products = Product::query()->with('accountGroup')->whereIn('id', $items->pluck('product_id')->filter())->get()->keyBy('id');
        $assets = FixedAsset::query()->with('group')->whereIn('id', $items->pluck('fixed_asset_id')->filter())->get()->keyBy('id');

        $lines = [];
        foreach ($items as $i => $item) {
            $qty = (float) $item['quantity'];
            $price = (float) ($item['price'] ?? 0);
            $gross = $qty * $price;
            $disc = $percent ? $gross * (float) ($item['discount'] ?? 0) / 100 : (float) ($item['discount'] ?? 0);
            $zz = ($item['tax_code'] ?? null) === 'ZZ';
            if ($zz && ! empty($item['tax_id'])) {
                throw ValidationException::withMessages(['items' => 'Una línea con impuesto ZZ no lleva impuesto_id.']);
            }
            if ($price < 0 && ! $zz) {
                throw ValidationException::withMessages(['items' => 'El precio negativo solo se permite en una línea con impuesto ZZ.']);
            }
            $charge = $zz ? null : $taxes->get($item['tax_id'] ?? 0);
            $rate = $charge ? (float) $charge->percentage / 100 : 0;
            [$base, $total] = self::split($gross - $disc, $rate, $included && ! $zz);
            $wh = $zz ? null : $taxes->get($item['withholding_id'] ?? 0);
            $product = $products->get($item['product_id'] ?? 0);
            $type = ! empty($item['product_id']) ? 'Product' : (! empty($item['fixed_asset_id']) ? 'FixedAsset' : (! empty($item['account_id']) ? 'Account' : 'Free'));
            $description = trim((string) ($item['description'] ?? '')) ?: ($product?->name ?? '');
            if ($type === 'Free' && $description === '') {
                throw ValidationException::withMessages(['items' => 'La línea libre necesita descripción, cantidad y precio.']);
            }
            $lines[] = [
                'position' => $i + 1,
                'type' => $type,
                'role' => in_array($item['role'] ?? '', ['mandante', 'mandatario'], true) ? $item['role'] : null,
                'tax_code' => $zz ? 'ZZ' : null,
                'code' => isset($item['code']) ? mb_substr((string) $item['code'], 0, 40) : null,
                'product' => $product,
                'account_id' => ! empty($item['account_id']) ? (int) $item['account_id'] : null,
                'asset' => $assets->get($item['fixed_asset_id'] ?? 0),
                'description' => $description,
                'quantity' => $qty,
                'price' => $price,
                'discount' => round($disc, 2),
                'discount_base' => round($included ? $disc / (1 + $rate) : $disc, 2),
                'base' => $base,
                'charge' => $charge,
                'tax' => round($total - $base, 2),
                'withholding' => $wh,
                'withholding_value' => $wh ? round($base * Tax::rate($wh->type, $wh->percentage), 2) : 0.0,
            ];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['items' => 'Debes agregar al menos un ítem con cantidad']);
        }

        $subtotal = round(array_sum(array_column($lines, 'base')), 2);
        $iva = round(array_sum(array_map(fn ($l) => $l['charge']?->type === 'IVA' ? $l['tax'] : 0, $lines)), 2);
        $summary = [];
        foreach ((array) ($data['summary_retentions'] ?? []) as $kind => $id) {
            $t = $id ? $taxes->get($id) : null;
            if (! $t) {
                continue;
            }
            $base = $t->type === 'ReteIVA' ? $iva : $subtotal;
            $summary[] = ['tax' => $t, 'base' => $base, 'value' => round($base * Tax::rate($t->type, $t->percentage), 2), 'self' => $t->type === 'Autorretencion'];
        }
        $taxTotal = round(array_sum(array_column($lines, 'tax')), 2);
        $customerWithholdings = array_sum(array_map(fn ($s) => $s['self'] ? 0 : $s['value'], $summary));
        $withholdingTotal = round(array_sum(array_column($lines, 'withholding_value')) + $customerWithholdings, 2);

        return [
            'lines' => $lines,
            'summary' => $summary,
            'subtotal' => $subtotal,
            'discount' => round(array_sum(array_column($lines, 'discount')), 2),
            'tax_total' => $taxTotal,
            'withholding_total' => $withholdingTotal,
            'total' => round($subtotal + $taxTotal - $withholdingTotal, 2),
        ];
    }

    private function related(array $form, array $data, bool $purchase): ?Model
    {
        if (empty($form['related']) || empty($data['related_id'])) {
            return null;
        }

        return ($purchase ? PurchaseDocument::query() : SalesDocument::query())->lockForUpdate()->find((int) $data['related_id']);
    }

    /** @return list<array{method:?PaymentMethod, account_id:int, name:string, value:float, credit:bool, due_date:?string}> */
    private function payments(array $form, array $data, float $total, bool $purchase, Contact $contact): array
    {
        if (($data['payment_form'] ?? null) === 'contado') {
            return [['method' => null, 'account_id' => Mandate::id('bridge'), 'name' => 'Contado', 'value' => $total, 'credit' => false, 'due_date' => substr((string) $data['date'], 0, 10)]];
        }
        if (empty($form['payments'])) {
            return [];
        }
        $days = (int) $contact->credit_days ?: 30;
        $due = function (?string $given) use ($data, $days): string {
            if ($given && strtotime($given) && $given >= $data['date']) {
                return substr($given, 0, 10);
            }

            return now()->parse($data['date'])->addDays($days)->toDateString();
        };
        if (! empty($data['credit'])) {
            return [['method' => null, 'account_id' => $this->accounts->default($purchase ? 'suppliers' : 'customers'), 'name' => 'Crédito', 'value' => $total, 'credit' => true, 'due_date' => $due($data['due_date'] ?? null)]];
        }
        $rows = collect($data['payments'] ?? [])->filter(fn ($p) => ! empty($p['method_id']) && (float) ($p['value'] ?? 0) > 0);
        $methods = PaymentMethod::query()->whereIn('id', $rows->pluck('method_id'))->get()->keyBy('id');
        $out = [];
        foreach ($rows as $p) {
            $m = $methods->get($p['method_id']) ?? throw ValidationException::withMessages(['payments' => 'Forma de pago no válida']);
            $account = $this->accounts->paymentMethod($m);
            $code = $this->accounts->code($account);
            $out[] = [
                'method' => $m, 'account_id' => $account, 'name' => $m->name, 'value' => round((float) $p['value'], 2),
                'credit' => str_starts_with($code, $purchase ? '22' : '1305'),
                'due_date' => $m->due_date ? $due($p['due_date'] ?? null) : null,
            ];
        }
        $diff = round($total - array_sum(array_column($out, 'value')), 2);
        if ($out && abs($diff) > 1) {
            throw ValidationException::withMessages(['items' => 'El total de las formas de pago debe ser igual al total del documento']);
        }
        if ($out && $diff != 0) {
            $out[count($out) - 1]['value'] = round($out[count($out) - 1]['value'] + $diff, 2);
        }

        return $out;
    }

    private function createSale(string $kind, DocumentType $type, int $number, string $name, Contact $contact, array $calc, ?Model $related, array $data, float $credit, string $dueDate): SalesDocument
    {
        if (! $related && ! empty($data['source_id']) && in_array($kind, ['FV', 'RM'], true)) {
            $related = SalesDocument::query()->whereIn('type', $kind === 'FV' ? ['CT', 'RM'] : ['CT'])->where('status', '!=', 'annulled')->find((int) $data['source_id']);
        }

        $doc = SalesDocument::create([
            'company_id' => CurrentCompany::id(), 'source' => 'erp', 'type' => $kind, 'document_type_id' => $type->id, 'prefix' => $type->prefix,
            'number' => $number, 'name' => $name, 'date' => $data['date'], 'due_date' => $dueDate, 'contact_id' => $contact->id,
            'contact_person_id' => $this->person($contact, $data['person_id'] ?? null),
            'price_list_id' => ! empty($data['price_list_id']) ? (int) $data['price_list_id'] : $contact->price_list_id,
            'seller_id' => $data['seller_id'] ?? null, 'cost_center_id' => $data['cost_center_id'] ?? null,
            'related_document_id' => $related?->id, 'related_name' => $related?->name ?? ($data['related_name'] ?? null), 'reason' => $data['reason'] ?? null,
            'external_reference' => $data['external_reference'] ?? null, 'request_hash' => $data['request_hash'] ?? null,
            'payment_form' => ($data['payment_form'] ?? null) === 'contado' ? 'contado' : null,
            'principal' => $data['principal'] ?? null, 'external_invoice' => $data['external_invoice'] ?? null,
            'currency' => 'COP', 'exchange_rate' => 1,
            'subtotal' => $calc['subtotal'], 'discount' => $calc['discount'], 'tax_total' => $calc['tax_total'],
            'withholding_total' => $calc['withholding_total'], 'total' => $calc['total'],
            'balance' => $kind === 'NC' || ! in_array($kind, ['FV', 'ND'], true) ? 0 : $credit,
            'observations' => trim(implode("\n", array_filter([$data['header_text'] ?? null, $data['observations'] ?? null, $data['footer_text'] ?? null]))) ?: null,
            'status' => 'issued', 'dian_status' => $type->isElectronic() ? 'Draft' : null, 'mail_status' => 'not_sent', 'created_by' => Auth::id(),
        ]);

        if (in_array($kind, ['FV', 'RM'], true) && $related instanceof SalesDocument && $related->type === 'CT' && $related->status !== 'approved') {
            $related->forceFill(['status' => 'approved'])->save();
        }

        return $doc;
    }

    private function createPurchase(string $kind, DocumentType $type, int $number, string $name, Contact $contact, array $calc, ?Model $related, array $data, float $credit, string $dueDate): PurchaseDocument
    {
        if (! $contact->is_supplier) {
            $contact->update(['is_supplier' => true]);
        }

        return PurchaseDocument::create([
            'company_id' => CurrentCompany::id(), 'source' => 'erp', 'type' => $kind, 'document_type_id' => $type->id,
            'number' => $number, 'name' => $name, 'date' => $data['date'], 'due_date' => $dueDate, 'contact_id' => $contact->id,
            'supplier_prefix' => $data['supplier_prefix'] ?? null, 'supplier_number' => $data['supplier_number'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null, 'related_document_id' => $related?->id, 'related_name' => $related?->name,
            'currency' => 'COP', 'subtotal' => $calc['subtotal'], 'discount' => $calc['discount'], 'tax_total' => $calc['tax_total'],
            'withholding_total' => $calc['withholding_total'], 'total' => $calc['total'],
            'balance' => in_array($kind, ['FC', 'DS'], true) ? $credit : 0,
            'observations' => $data['observations'] ?? null, 'status' => 'issued',
            'dian_status' => $type->isElectronic() ? 'Draft' : null, 'created_by' => Auth::id(),
        ]);
    }

    private function saveItems(Model $doc, array $calc, bool $purchase, array $data): void
    {
        $needsWarehouse = collect($calc['lines'])->contains(fn ($l) => $l['product']);
        $warehouse = $needsWarehouse ? $this->warehouse() : null;
        if ($needsWarehouse && ! $warehouse) {
            throw ValidationException::withMessages(['items' => 'No hay una bodega activa. Crea la bodega Principal antes de guardar un producto inventariable.']);
        }
        foreach ($calc['lines'] as $l) {
            $common = [
                'position' => $l['position'], 'product_id' => $l['product']?->id,
                'code' => $l['product']?->code ?? ($l['asset']?->code ?? ($l['account_id'] ? $this->accounts->code($l['account_id']) : ($l['code'] ?: ($l['type'] === 'Free' ? 'LIBRE' : null)))),
                'description' => $l['description'], 'warehouse_id' => $l['product'] ? $warehouse : null,
                'quantity' => $l['quantity'], 'price' => $l['price'], 'discount' => $l['discount'], 'subtotal' => $l['base'],
                'tax_total' => $l['tax'], 'total' => round($l['base'] + $l['tax'], 2), 'tax_percentage' => $l['charge']?->percentage,
            ];
            if ($purchase) {
                $item = $doc->items()->create($common + [
                    'item_type' => $l['type'], 'account_id' => $l['type'] === 'Account' ? $l['account_id'] : ($l['type'] === 'FixedAsset' ? $l['asset']?->group?->purchase_account_id : null),
                ]);
                $taxTable = 'purchase_document_item_taxes';
                $fk = 'purchase_document_item_id';
            } else {
                $item = $doc->items()->create($common + [
                    'seller_id' => $data['seller_id'] ?? null, 'unit_cost' => (float) ($l['product']?->average_cost ?? 0),
                    'role' => $l['role'] ?? null, 'tax_code' => $l['tax_code'] ?? null,
                ]);
                $taxTable = 'sales_document_item_taxes';
                $fk = 'sales_document_item_id';
            }
            foreach (array_filter([[$l['charge'], $l['tax']], [$l['withholding'], $l['withholding_value']]], fn ($t) => $t[0]) as [$tax, $value]) {
                DB::table($taxTable)->insert([$fk => $item->id, 'tax_id' => $tax->id, 'name' => $tax->name, 'type' => $tax->type, 'percentage' => $tax->percentage, 'value' => $value]);
            }
        }
        foreach ($calc['summary'] as $s) {
            DB::table($purchase ? 'purchase_document_retentions' : 'sales_document_retentions')->insert([
                ($purchase ? 'purchase_document_id' : 'sales_document_id') => $doc->id,
                'tax_id' => $s['tax']->id, 'name' => $s['tax']->name, 'type' => $s['tax']->type, 'percentage' => $s['tax']->percentage,
                'base' => round($s['base'], 2), 'value' => $s['value'],
            ]);
        }
    }

    private function savePayments(Model $doc, array $payments, bool $purchase): void
    {
        foreach ($payments as $p) {
            DB::table($purchase ? 'purchase_document_payments' : 'sales_document_payments')->insert([
                ($purchase ? 'purchase_document_id' : 'sales_document_id') => $doc->id,
                'payment_method_id' => $p['method']?->id, 'name' => $p['name'], 'value' => $p['value'], 'due_date' => $p['due_date'],
            ]);
        }
    }

    /** NC y nota de ajuste de compra cruzan su parte a credito contra el saldo del documento relacionado. */
    private function applyToRelated(string $kind, ?Model $related, float $credit, Model $doc): void
    {
        if (! $related || ! in_array($kind, ['NC', 'NA'], true) || $credit <= 0) {
            return;
        }
        $applied = round(min($credit, (float) $related->balance), 2);
        if ($applied > 0) {
            $related->forceFill(['balance' => round((float) $related->balance - $applied, 2)])->save();
            $doc->forceFill(['related_applied' => $applied])->save();
        }
    }

    /** Mueve el kardex y devuelve el costo por linea para la contabilizacion. */
    private function moveInventory(string $kind, Model $doc, array $calc, string $date): array
    {
        $sign = match ($kind) {
            'FV', 'RM', 'NA' => -1,
            'NC', 'FC', 'DS' => 1,
            default => 0,
        };
        $costs = [];
        if ($sign === 0) {
            return $costs;
        }
        $concept = DocumentType::TYPES[$kind] ?? $kind;
        foreach ($calc['lines'] as $idx => $l) {
            if (($l['role'] ?? null) === 'mandante' || ($l['type'] ?? null) === 'Free') {
                continue;
            }
            $p = $l['product'];
            if ($p?->type === 'Combo') {
                $costs[$idx] = $this->moveCombo($p, $sign * $l['quantity'], $concept, $date, $doc, $kind);

                continue;
            }
            if (! $p || ! $p->stock_control) {
                continue;
            }
            $unitCost = in_array($kind, ['FC', 'DS'], true) ? $l['base'] / $l['quantity'] : null;
            if ($kind === 'NC') {
                $unitCost = (float) $p->average_cost;
            }
            $m = $this->inventory->move($p, $this->warehouse(), $sign * $l['quantity'], $concept, $date, $unitCost, $doc, $doc->name);
            $costs[$idx] = abs((float) ($m?->total_cost ?? 0));
        }

        return $costs;
    }

    /** Un combo mueve el inventario de cada producto que lo compone; devuelve el costo total. */
    private function moveCombo(Product $combo, float $quantity, string $concept, string $date, Model $doc, string $kind): float
    {
        $cost = 0.0;
        foreach ($combo->comboItems()->with('product')->get() as $c) {
            if (! $c->product?->stock_control) {
                continue;
            }
            $qty = $quantity * (float) $c->quantity;
            $unitCost = $qty > 0 ? (float) $c->product->average_cost : null;
            $m = $this->inventory->move($c->product, $this->warehouse(), $qty, $concept, $date, $unitCost, $doc, $doc->name);
            $cost += abs((float) ($m?->total_cost ?? 0));
        }

        return round($cost, 2);
    }

    private function post(string $kind, Model $doc, Contact $contact, array $calc, array $payments, array $costs, bool $purchase): void
    {
        $entry = new LedgerEntry($this->accounts, $contact);
        $reverse = in_array($kind, ['NC', 'NA'], true);
        // $side true = debito en el documento normal (FV para ventas, FC para compras)
        $put = fn (int $account, float $value, bool $debit) => ($debit xor $reverse) ? $entry->debit($account, $value) : $entry->credit($account, $value);
        $taxAccount = fn (Tax $tax, bool $forPurchases) => $reverse
            ? $this->accounts->taxReturn($tax, $forPurchases)
            : $this->accounts->tax($tax, $forPurchases);

        foreach ($payments as $p) {
            $put($p['account_id'], $p['value'], ! $purchase);
        }
        foreach ($calc['lines'] as $idx => $l) {
            $group = $l['product']?->accountGroup;
            if ($purchase) {
                $account = match ($l['type']) {
                    'Product' => $this->accounts->group($group, 'inventory'),
                    'FixedAsset' => $l['asset']?->group?->purchase_account_id ?: $this->accounts->default('other_expenses'),
                    default => $l['account_id'],
                };
                $put($account, $l['base'], true);
                if ($l['charge']) {
                    $put($taxAccount($l['charge'], true), $l['tax'], true);
                }
                if ($l['withholding']) {
                    $put($taxAccount($l['withholding'], true), $l['withholding_value'], false);
                }

                continue;
            }
            if (($l['tax_code'] ?? null) === 'ZZ') {
                $put(Mandate::id('adjustment'), $l['base'], false);

                continue;
            }
            if (($l['role'] ?? null) === 'mandante') {
                $put(Mandate::id('liability'), $l['base'] + $l['discount_base'], false);
                if ($l['discount_base'] > 0) {
                    $put($this->accounts->default('sales_discounts'), $l['discount_base'], true);
                }
                if ($l['charge']) {
                    $put(Mandate::id('vat'), $l['tax'], false);
                }
                if ($l['withholding']) {
                    $put($taxAccount($l['withholding'], false), $l['withholding_value'], true);
                }

                continue;
            }
            $income = $l['account_id'] ?? ($kind === 'NC' ? $this->accounts->group($group, 'return') : $this->accounts->group($group, 'income'));
            $put($income, $l['base'] + $l['discount_base'], false);
            if ($l['discount_base'] > 0) {
                $put($this->accounts->default('sales_discounts'), $l['discount_base'], true);
            }
            if ($l['charge']) {
                $put($taxAccount($l['charge'], false), $l['tax'], false);
            }
            if ($l['withholding']) {
                $put($taxAccount($l['withholding'], false), $l['withholding_value'], true);
            }
            if (! empty($costs[$idx])) {
                $put($this->accounts->group($group, 'cost'), $costs[$idx], true);
                $put($this->accounts->group($group, 'inventory'), $costs[$idx], false);
            }
        }
        foreach ($calc['summary'] as $s) {
            if ($s['self']) {
                // Autorretencion: anticipo (debito) contra la retencion por pagar (credito); no cambia la cartera del cliente.
                $put($taxAccount($s['tax'], false), $s['value'], true);
                $put($taxAccount($s['tax'], true), $s['value'], false);

                continue;
            }
            $put($taxAccount($s['tax'], $purchase), $s['value'], ! $purchase);
        }

        $entry->post($doc->date, $doc->name, $kind);
    }

    private function warehouse(): int
    {
        return $this->warehouseId ??= (int) (Warehouse::query()->where('active', true)->orderByDesc('is_default')->orderBy('id')->value('id') ?: 0);
    }

    private function person(Contact $contact, mixed $id): ?int
    {
        return $id ? $contact->people()->whereKey((int) $id)->value('id') : null;
    }
}
