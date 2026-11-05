<?php

namespace App\Services\Documents;

use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\FrequentConcept;
use App\Models\PaymentMethod;
use App\Models\PurchaseDocument;
use App\Models\Receipt;
use App\Models\SalesDocument;
use App\Models\Tax;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerEntry;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Recibos de caja (RC) y recibos de pago / egresos (RP): abonan documentos, anticipos u otros conceptos. */
class ReceiptRegistrar
{
    public function __construct(private readonly Numbering $numbering, private readonly AccountResolver $accounts) {}

    public function cash(array $data): Receipt
    {
        $method = PaymentMethod::query()->find($data['payment_method_id'] ?? 0)
            ?? throw ValidationException::withMessages(['payment_method_id' => 'Selecciona la forma de pago']);
        $mode = $data['mode'] ?? 'deuda';

        return $this->register('RC', $data, $this->accounts->paymentMethod($method), $method->name, $method->id, match ($mode) {
            'anticipo' => 'AdvancePayment',
            'otro' => 'Other',
            default => 'DebtPayment',
        }, [
            'documents' => SalesDocument::class,
            'counter' => 'customers',
            'advance' => 'customer_advances',
            'other' => 'other_income',
        ]);
    }

    public function payment(array $data, array $fallbacks): Receipt
    {
        if (! empty($data['payment_method_id'])) {
            $method = PaymentMethod::query()->find($data['payment_method_id'])
                ?? throw ValidationException::withMessages(['payment_method_id' => 'Selecciona de donde sale el dinero']);
            [$account, $name, $methodId] = [$this->accounts->paymentMethod($method), $method->name, $method->id];
        } else {
            $bank = BankAccount::query()->find($data['bank_id'] ?? 0)
                ?? throw ValidationException::withMessages(['payment_method_id' => 'Selecciona de donde sale el dinero']);
            [$account, $name, $methodId] = [$bank->account_id ?: $this->accounts->default('cash'), $bank->name, null];
        }
        $data['mode'] = match ($data['mode'] ?? '') {
            'Anticipo', 'Anticipo a proveedor', 'anticipo' => 'anticipo',
            'Otros pagos', 'otro' => 'otro',
            'Avanzado (Impuestos, descuentos y ajustes)', 'Avanzado', 'avanzado' => 'avanzado',
            default => 'deuda',
        };

        return $this->register('CE', $data, $account, $name, $methodId, match ($data['mode']) {
            'anticipo' => 'AdvancePayment',
            'otro' => 'Other',
            'avanzado' => 'Advanced',
            default => 'DebtPayment',
        }, [
            'documents' => PurchaseDocument::class,
            'counter' => 'suppliers',
            'advance' => 'supplier_advances',
            'other' => 'other_expenses',
        ], $fallbacks);
    }

    /** @param array{documents:class-string, counter:string, advance:string, other:string} $cfg */
    private function register(string $kind, array $data, int $moneyAccount, string $moneyName, ?int $methodId, string $receiptType, array $cfg, array $fallbacks = []): Receipt
    {
        $this->numbering->assertOpen($data['date']);
        $type = $this->numbering->type($data['document_type'] ?? null, $kind, $fallbacks);
        $contact = Contact::query()->findOrFail($data['contact_id']);
        $value = round((float) ($data['value'] ?? 0), 2);
        $incoming = $kind === 'RC';

        return DB::transaction(function () use ($kind, $data, $type, $contact, $value, $moneyAccount, $moneyName, $methodId, $receiptType, $cfg, $incoming) {
            $lines = [];
            if ($data['mode'] === 'deuda') {
                foreach (collect($data['items'] ?? [])->filter(fn ($i) => ! empty($i['document_id']) && ((float) ($i['value'] ?? 0) > 0 || ! empty($i['taxes']) || ! empty($i['discounts']))) as $i) {
                    $doc = $cfg['documents']::query()->where('contact_id', $contact->id)->lockForUpdate()->find($i['document_id'])
                        ?? throw ValidationException::withMessages(['items' => 'El documento no pertenece al tercero']);
                    $pay = round((float) $i['value'], 2);
                    $adjustments = $this->adjustments($i, $incoming);
                    $settled = round($pay + array_sum(array_column($adjustments, 'value')), 2);
                    if ($settled - (float) $doc->balance > 0.009) {
                        throw ValidationException::withMessages(['items' => "El valor aplicado a {$doc->name} supera su saldo"]);
                    }
                    $doc->forceFill(['balance' => round((float) $doc->balance - $settled, 2)])->save();
                    $lines[] = ['doc' => $doc, 'account_id' => $this->accounts->default($cfg['counter']), 'value' => $pay, 'adjustments' => $adjustments, 'description' => 'Abono'];
                }
                if (! $lines) {
                    throw ValidationException::withMessages(['items' => 'Selecciona al menos un documento a pagar']);
                }
            }
            if ($data['mode'] === 'avanzado') {
                foreach ($data['lines'] ?? [] as $l) {
                    $net = round((float) ($l['debit'] ?? 0) - (float) ($l['credit'] ?? 0), 2);
                    if (empty($l['account_id']) || $net == 0) {
                        continue;
                    }
                    $doc = null;
                    if (! empty($l['document_id'])) {
                        $doc = $cfg['documents']::query()->where('contact_id', $contact->id)->lockForUpdate()->find($l['document_id'])
                            ?? throw ValidationException::withMessages(['items' => 'El documento del detalle no pertenece al tercero']);
                        $settled = $incoming ? -$net : $net;
                        if ($settled - (float) $doc->balance > 0.009) {
                            throw ValidationException::withMessages(['items' => "El valor aplicado a {$doc->name} supera su saldo"]);
                        }
                        $doc->forceFill(['balance' => round((float) $doc->balance - $settled, 2)])->save();
                    }
                    $lines[] = ['doc' => $doc, 'account_id' => (int) $l['account_id'], 'description' => trim((string) ($l['description'] ?? '')) ?: null,
                        'value' => $incoming ? -$net : $net, 'adjustments' => []];
                }
                $value = round(array_sum(array_column($lines, 'value')), 2);
                if (! $lines || $value <= 0) {
                    throw ValidationException::withMessages(['items' => 'Agrega las líneas contables; el total '.($incoming ? 'recibido' : 'pagado').' debe ser mayor a cero']);
                }
            }
            $applied = round(array_sum(array_column($lines, 'value')), 2);
            $rest = round($value - $applied, 2);
            $currencies = collect($lines)->map(fn ($l) => $l['doc'] ? strtoupper((string) ($l['doc']->currency ?: 'COP')) : null)->filter()->unique()->values();
            if ($currencies->count() > 1) {
                throw ValidationException::withMessages(['items' => 'Un recibo no puede mezclar documentos en distintas monedas.']);
            }
            $rate = 1.0;
            if ($currencies->count() === 1 && $currencies[0] !== 'COP') {
                $rate = (float) collect($lines)->first(fn ($l) => $l['doc'])['doc']->exchange_rate;
                if ($rate <= 0) {
                    throw ValidationException::withMessages(['items' => "El documento en {$currencies[0]} no tiene tasa de cambio a pesos."]);
                }
            }
            $fx = $rate !== 1.0 ? fn (float $v) => round($v * $rate, 2) : fn (float $v) => $v;
            if ($rest < -0.009) {
                throw ValidationException::withMessages(['value' => 'El valor es menor que lo aplicado a los documentos']);
            }
            if ($rest > 0) {
                $other = $data['mode'] === 'otro';
                $concept = $other ? FrequentConcept::query()->where('active', true)->find($data['concept_id'] ?? 0) : null;
                if ($other && ! $concept) {
                    throw ValidationException::withMessages(['concept_id' => 'Selecciona el concepto']);
                }
                $lines[] = ['doc' => null, 'account_id' => $concept?->account_id ?: $this->accounts->default($other ? $cfg['other'] : $cfg['advance']),
                    'description' => $concept?->name ?? ($other ? 'Otros' : 'Anticipo'), 'value' => $rest, 'adjustments' => []];
            }

            [$number, $name] = $this->numbering->take($type, 'receipts');
            $receipt = Receipt::create([
                'company_id' => CurrentCompany::id(), 'source' => 'erp', 'type' => $incoming ? 'RC' : 'RP', 'receipt_type' => $receiptType,
                'document_type_id' => $type->id, 'number' => $number, 'name' => $name, 'date' => $data['date'], 'contact_id' => $contact->id,
                'payment_method_id' => $methodId, 'payment_name' => $moneyName, 'total' => $value, 'observations' => $data['observations'] ?? null,
                'status' => 'issued', 'created_by' => Auth::id(),
            ]);

            $entry = new LedgerEntry($this->accounts, $contact);
            $incoming ? $entry->debit($moneyAccount, $fx($value)) : $entry->credit($moneyAccount, $fx($value));
            foreach ($lines as $l) {
                $d = $l['doc'];
                $adjusted = round(array_sum(array_column($l['adjustments'], 'value')), 2);
                $item = $receipt->items()->create([
                    'due_prefix' => $d ? preg_replace('/-\d+$/', '', $d->name) : null, 'due_number' => $d?->number, 'due_quote' => $d ? 1 : 0,
                    'due_date' => $d?->due_date ?? $d?->date, 'sales_document_id' => $incoming ? $d?->id : null, 'purchase_document_id' => $incoming ? null : $d?->id,
                    'account_id' => $l['account_id'], 'description' => $l['description'], 'value' => $l['value'], 'adjustments' => $adjusted,
                ]);
                foreach ($l['adjustments'] as $a) {
                    DB::table('receipt_item_adjustments')->insert(['receipt_item_id' => $item->id] + $a);
                    $incoming ? $entry->debit($a['account_id'], $fx((float) $a['value'])) : $entry->credit($a['account_id'], $fx((float) $a['value']));
                }
                $settled = round($l['value'] + $adjusted, 2);
                ($incoming xor $settled < 0) ? $entry->credit($l['account_id'], $fx(abs($settled))) : $entry->debit($l['account_id'], $fx(abs($settled)));
            }
            $entry->post($data['date'], $name, Numbering::LEDGER_PREFIX[$kind] ?? $kind);

            return $receipt;
        });
    }

    /**
     * Impuestos (base x tarifa) y descuentos o ajustes de la ventana "Agregar impuesto o descuento" de cada documento.
     * @return list<array{kind:string, tax_id:?int, frequent_concept_id:?int, account_id:int, name:string, base:float, value:float}>
     */
    private function adjustments(array $item, bool $incoming): array
    {
        $rows = [];
        foreach ($item['taxes'] ?? [] as $t) {
            $tax = Tax::query()->whereIn('type', Tax::WITHHOLDINGS)->find($t['tax_id'] ?? 0);
            $value = round((float) ($t['value'] ?? 0), 2);
            if (! $tax || $value <= 0) {
                continue;
            }
            $account = $incoming ? $tax->sales_account_id : $tax->purchases_account_id;
            $rows[] = ['kind' => 'tax', 'tax_id' => $tax->id, 'frequent_concept_id' => null, 'account_id' => $account ?: throw ValidationException::withMessages(['items' => "El impuesto {$tax->name} no tiene cuenta contable"]),
                'name' => $tax->name, 'base' => round((float) ($t['base'] ?? 0), 2), 'value' => $value];
        }
        foreach ($item['discounts'] ?? [] as $d) {
            $concept = FrequentConcept::query()->find($d['concept_id'] ?? 0);
            $value = round((float) ($d['value'] ?? 0), 2);
            if (! $concept || $value <= 0) {
                continue;
            }
            $rows[] = ['kind' => 'discount', 'tax_id' => null, 'frequent_concept_id' => $concept->id, 'account_id' => $concept->account_id, 'name' => $concept->name, 'base' => 0, 'value' => $value];
        }

        return $rows;
    }
}
