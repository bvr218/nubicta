<?php

namespace App\Services\Documents;

use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\Receipt;
use App\Models\SalesDocument;
use App\Models\Tax;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Ubica cualquier documento por /documentos/{tipo}/{id} y arma su detalle y los datos para editarlo. */
class DocumentLocator
{
    public const KINDS = ['ventas' => SalesDocument::class, 'compras' => PurchaseDocument::class, 'recibos' => Receipt::class, 'comprobantes' => JournalEntry::class];

    private const ITEM_TYPE = ['Product' => 'Producto', 'Account' => 'Cuenta contable', 'FixedAsset' => 'Activo fijo'];

    public static function find(string $kind, int $id): Model
    {
        $class = self::KINDS[$kind] ?? abort(404);

        return $class::query()->findOrFail($id);
    }

    public static function kind(Model $doc): string
    {
        return array_search($doc::class, self::KINDS, true);
    }

    /** Modulo de permisos (ventas, compras, bancos, contabilidad, inventario). */
    public static function module(Model $doc): string
    {
        return match (true) {
            $doc instanceof SalesDocument => 'ventas',
            $doc instanceof PurchaseDocument => 'compras',
            $doc instanceof Receipt => $doc->type === 'RC' ? 'ventas' : 'compras',
            $doc instanceof JournalEntry => match (true) {
                str_starts_with((string) $doc->source_type, 'inventory_') => 'inventario',
                str_starts_with((string) $doc->source_type, 'payroll') => 'nomina',
                $doc->source_type === 'money_transfer' => 'bancos',
                default => 'contabilidad',
            },
        };
    }

    public static function url(Model $doc): string
    {
        return '/documentos/'.self::kind($doc).'/'.$doc->id;
    }

    /**
     * Enlaces a los documentos a partir del nombre con que quedaron en el libro auxiliar (FV-2-70349, CC-1-15...).
     * @param  iterable<string>  $names
     * @return array<string, string> nombre => url
     */
    public static function urlsByName(int $companyId, iterable $names): array
    {
        $names = collect($names)->filter()->unique()->values();
        $urls = [];
        foreach (['ventas' => 'sales_documents', 'compras' => 'purchase_documents', 'recibos' => 'receipts', 'comprobantes' => 'journal_entries'] as $kind => $table) {
            $pending = $names->reject(fn ($n) => isset($urls[$n]));
            if ($pending->isEmpty()) {
                break;
            }
            foreach ($pending->chunk(500) as $chunk) {
                DB::table($table)->where('company_id', $companyId)->whereIn('name', $chunk->all())->pluck('id', 'name')
                    ->each(function ($id, $name) use (&$urls, $kind) {
                        $urls[$name] ??= "/documentos/$kind/$id";
                    });
            }
        }

        return $urls;
    }

    public static function detail(Model $doc): array
    {
        $contact = $doc->contact_id ? DB::table('contacts')->where('id', $doc->contact_id)->first(['identification', 'dv', 'name', 'address', 'mobile as phone', 'email']) : null;
        $header = [
            'id' => $doc->id, 'kind' => self::kind($doc), 'name' => $doc->name, 'type' => $doc->type ?? 'CC',
            'source' => $doc->source ?? 'erp', 'status' => $doc->status,
            'date' => substr((string) $doc->date, 0, 10), 'due_date' => isset($doc->due_date) ? substr((string) $doc->due_date, 0, 10) : null,
            'contact' => $contact ? ['identification' => $contact->identification, 'dv' => $contact->dv, 'name' => $contact->name, 'address' => $contact->address, 'phone' => $contact->phone, 'email' => $contact->email] : null,
            'observations' => $doc->observations,
            'type_name' => ($typeRow = $doc->document_type_id ? DB::table('document_types')->where('id', $doc->document_type_id)->first(['title', 'name', 'print_format']) : null) ? ($typeRow->title ?: $typeRow->name) : null,
            'print_format' => $typeRow?->print_format,
            'annulled_at' => $doc->annulled_at ? substr((string) $doc->annulled_at, 0, 16) : null,
            'attachments' => \App\Models\Attachment::listFor($doc),
        ];

        $body = match (true) {
            $doc instanceof SalesDocument, $doc instanceof PurchaseDocument => self::trade($doc),
            $doc instanceof Receipt => self::receipt($doc),
            $doc instanceof JournalEntry => self::journal($doc),
        };

        $ledger = DB::table('ledger_lines as l')->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.company_id', $doc->company_id)->where('l.document', $doc->name)
            ->where(fn ($q) => $q->where('l.debit', '!=', 0)->orWhere('l.credit', '!=', 0))
            ->orderBy('l.id')->get(['l.account_code', 'a.name as account', 'l.identification', 'l.contact_name', 'l.document_type', 'l.debit', 'l.credit'])
            ->map(fn ($l) => ['account' => trim("{$l->account_code} {$l->account}"), 'contact' => trim("{$l->identification} {$l->contact_name}"), 'description' => $l->document_type, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit]);

        $movements = InventoryMovement::query()->with(['product:id,code,name', 'warehouse:id,name'])->where('document', $doc->name)
            ->where('company_id', $doc->company_id)->orderBy('id')->get()
            ->map(fn ($m) => ['date' => substr((string) $m->date, 0, 10), 'product' => trim(($m->product?->code ?? '').' '.($m->product?->name ?? '')), 'warehouse' => $m->warehouse?->name,
                'concept' => $m->concept, 'quantity' => (float) $m->quantity, 'unit_cost' => (float) $m->unit_cost, 'balance' => (float) $m->balance_quantity]);

        $reverser = app(DocumentReverser::class);
        $seeLedger = (bool) auth()->user()?->can('contabilidad.ver');
        $seeCosts = (bool) auth()->user()?->can('inventario.ver_costos');
        if (! $seeCosts) {
            $movements = $movements->map(fn ($m) => [...$m, 'unit_cost' => null]);
        }

        return $header + $body + [
            'ledger' => $seeLedger ? $ledger : [], 'show_ledger' => $seeLedger, 'show_costs' => $seeCosts, 'movements' => $movements,
            'blocker' => $reverser->blocker($doc),
            'editable' => DocumentReverser::formKey($doc) !== null && $reverser->blocker($doc, true) === null,
            'copy_url' => ($key = DocumentReverser::formKey($doc)) ? '/'.$key.'?copiar='.self::kind($doc).':'.$doc->id : null,
            'favorite' => $doc instanceof JournalEntry ? (bool) $doc->favorite : null,
            'mailable' => $doc instanceof SalesDocument,
            'mail_status' => $doc instanceof SalesDocument ? $doc->mail_status : null,
            'module' => self::module($doc),
        ];
    }

    private static function trade(SalesDocument|PurchaseDocument $doc): array
    {
        $sales = $doc instanceof SalesDocument;
        $taxTable = $sales ? 'sales_document_item_taxes' : 'purchase_document_item_taxes';
        $fk = $sales ? 'sales_document_item_id' : 'purchase_document_item_id';
        $items = $doc->items()->orderBy('position')->get();
        $taxes = DB::table($taxTable)->whereIn($fk, $items->pluck('id'))->get()->groupBy($fk);
        $payments = DB::table($sales ? 'sales_document_payments' : 'purchase_document_payments')->where($sales ? 'sales_document_id' : 'purchase_document_id', $doc->id)->get();
        $units = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter())->pluck('unit_label', 'id');
        $warehouses = DB::table('warehouses')->whereIn('id', $items->pluck('warehouse_id')->filter())->pluck('name', 'id');
        $lineTaxes = $taxes->flatten(1)->filter(fn ($t) => ! in_array($t->type ?? null, Tax::WITHHOLDINGS, true));
        $bases = $items->keyBy('id');
        $retentions = $sales
            ? DB::table('sales_document_retentions')->where('sales_document_id', $doc->id)->get()
            : DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->get();

        return [
            'module_name' => $sales ? 'ventas' : 'compras',
            'related' => $doc->related_name, 'related_url' => $doc->related_document_id ? '/documentos/'.($sales ? 'ventas' : 'compras').'/'.$doc->related_document_id : null,
            'reason' => $doc->reason ?? null,
            'supplier_invoice' => $sales ? null : (trim(($doc->supplier_prefix ?? '').'-'.($doc->supplier_number ?? ''), '-') ?: null),
            'seller' => $sales && $doc->seller_id ? DB::table('sellers')->where('id', $doc->seller_id)->value('name') : null,
            'items' => $items->map(fn ($i) => [
                'code' => $i->code, 'description' => $i->description, 'role' => $i->role ?? null, 'tax_code' => $i->tax_code ?? null,
                'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
                'discount' => (float) $i->discount, 'subtotal' => (float) $i->subtotal, 'tax_total' => (float) $i->tax_total, 'total' => (float) $i->total,
                'taxes' => ($taxes[$i->id] ?? collect())->map(fn ($t) => "{$t->name} ".number_format((float) $t->value, 2))->implode(', '),
                'unit' => $units[$i->product_id] ?? null, 'warehouse' => $warehouses[$i->warehouse_id] ?? null,
                'charge_rate' => (float) (($taxes[$i->id] ?? collect())->first(fn ($t) => ! in_array($t->type, Tax::WITHHOLDINGS, true))?->percentage ?? $i->tax_percentage ?? 0),
                'withholding_rate' => (float) (($taxes[$i->id] ?? collect())->first(fn ($t) => in_array($t->type, Tax::WITHHOLDINGS, true))?->percentage ?? 0),
            ]),
            'tax_lines' => $lineTaxes->groupBy('name')->map(fn ($g, $name) => ['name' => $name, 'rate' => (float) $g->first()->percentage, 'value' => (float) $g->sum('value')])->sortByDesc('rate')->values(),
            'withholding_lines' => $taxes->flatten(1)->filter(fn ($t) => in_array($t->type ?? null, Tax::WITHHOLDINGS, true))->groupBy('name')
                ->map(fn ($g, $name) => ['name' => $name, 'value' => (float) $g->sum('value')])->values(),
            'tax_summary' => $lineTaxes->groupBy(fn ($t) => (string) (float) $t->percentage)->map(fn ($g, $rate) => [
                'rate' => (float) $rate, 'base' => (float) $g->sum(fn ($t) => $bases[$t->$fk]->subtotal ?? 0), 'value' => (float) $g->sum('value'),
            ])->sortByDesc('rate')->values(),
            // Se imprime "Efectivo" en todas las formas de pago y agrega el vencimiento en las de crédito.
            'payments' => $payments->map(fn ($p) => ['name' => $p->name, 'value' => (float) $p->value, 'due_date' => $p->due_date, 'form' => ($sales && $doc->payment_form === 'contado') ? 'Contado' : 'Efectivo']),
            'number_label' => $sales && $doc->prefix ? "{$doc->prefix} {$doc->number}" : null,
            'created' => ['by' => $doc->created_by ? DB::table('users')->where('id', $doc->created_by)->value('name')
                    : ($sales && in_array($doc->type, ['CT', 'RM'], true) && $doc->seller_id ? DB::table('sellers')->where('id', $doc->seller_id)->value('name') : null),
                'at' => $doc->created_at?->format('d/m/Y H:i')],
            'retentions' => $retentions->map(fn ($r) => ['name' => $r->name, 'value' => (float) $r->value]),
            'totals' => ['subtotal' => (float) $doc->subtotal, 'discount' => (float) $doc->discount, 'tax' => (float) $doc->tax_total,
                'withholding' => (float) $doc->withholding_total, 'total' => (float) $doc->total, 'balance' => (float) $doc->balance],
            'dian' => $doc->dian_status ? ['status' => $doc->dian_status, 'simulated' => (bool) DB::table('dian_transmissions')->where('company_id', $doc->company_id)->where($sales ? 'sales_document_id' : 'purchase_document_id', $doc->id)->orderByDesc('id')->value('simulated'), 'cufe' => $sales ? $doc->cufe : ($doc->cuds ?? null), 'message' => $doc->dian_message,
                'xml' => $doc->xml_path ? true : false, 'response' => (bool) $doc->dian_response_path, 'attached' => (bool) $doc->attached_path,
                'qr' => $sales ? ($doc->qr_data ?: ($doc->cufe ? 'https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey='.$doc->cufe : null)) : null] : null,
            'printable' => true,
        ] + ($sales ? self::salesExtra($doc) : self::purchaseExtra($doc));
    }

    /** Formato para compras: datos del proveedor, valor en letras y estado de cuenta de la factura. */
    private static function purchaseExtra(PurchaseDocument $doc): array
    {
        $contact = DB::table('contacts as c')->leftJoin('municipalities as m', 'm.code', '=', 'c.municipality_code')->where('c.id', $doc->contact_id)
            ->first(['c.phone_indicative', 'c.phone', 'c.mobile', 'm.name as city']);
        $phone = $contact ? ($contact->phone ?: $contact->mobile) : null;
        $balance = (float) $doc->balance;
        $overdue = $doc->due_date && $doc->due_date->lt(now()->startOfDay());

        return [
            'contact_id' => $doc->contact_id,
            'contact_extra' => [
                'phone' => $phone ? trim(($contact->phone_indicative ? "({$contact->phone_indicative}) " : '').$phone) : null,
                'city' => $contact?->city ? "{$contact->city} - Colombia" : null,
            ],
            'cost_center' => $doc->cost_center_id ? DB::table('cost_centers')->where('id', $doc->cost_center_id)->value('name') : null,
            'words' => self::words((float) $doc->total),
            'debt' => $doc->contact_id ? (float) DB::table('purchase_documents')->where('company_id', $doc->company_id)->where('contact_id', $doc->contact_id)->where('status', '!=', 'annulled')->sum('balance') : null,
            'adjustments' => DB::table('purchase_documents')->where('related_document_id', $doc->id)->where('type', 'NA')->where('status', '!=', 'annulled')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($n) => ['name' => $n->name, 'url' => "/documentos/compras/{$n->id}"])->all(),
            'related_cuds' => $doc->related_document_id ? DB::table('purchase_documents')->where('id', $doc->related_document_id)->value('cuds') : null,
            'statement' => ['total' => (float) $doc->total, 'balance' => $balance, 'overdue' => $overdue ? $balance : 0.0, 'pending' => $overdue ? 0.0 : $balance],
        ];
    }

    /** Datos que usan los formatos de impresion de notas (tercero completo, factura afectada, valor en letras). */
    private static function salesExtra(SalesDocument $doc): array
    {
        $contact = DB::table('contacts as c')->leftJoin('municipalities as m', 'm.code', '=', 'c.municipality_code')->where('c.id', $doc->contact_id)
            ->first(['c.phone_indicative', 'c.phone', 'c.mobile', 'c.phone_extension', 'm.name as city']);
        $related = $doc->related_document_id ? DB::table('sales_documents')->where('id', $doc->related_document_id)->first(['prefix', 'number', 'name', 'cufe']) : null;

        return [
            'contact_extra' => [
                'phone' => $contact ? trim('('.($contact->phone_indicative ?: '000').') '.($contact->phone ?: $contact->mobile).' - Ext. '.($contact->phone_extension ?: '000')) : null,
                'city' => $contact?->city ? "{$contact->city} - Colombia" : null,
            ],
            'related_doc' => $related ? ['label' => $related->prefix ? "{$related->prefix}-{$related->number}" : $related->name, 'cufe' => $related->cufe]
                : ($doc->external_invoice ? ['label' => $doc->external_invoice['numero'] ?? '', 'cufe' => $doc->external_invoice['cufe'] ?? ''] : null),
            'principal' => $doc->principal,
            'payment_form' => $doc->payment_form,
            'type_code' => DB::table('document_types')->where('id', $doc->document_type_id)->value('code'),
            'issued_at' => $doc->created_at?->format('d/m/Y, H:i'),
            'words' => self::words((float) $doc->total),
            'contact_id' => $doc->contact_id,
            'debt' => $doc->contact_id ? (float) DB::table('sales_documents')->where('company_id', $doc->company_id)->where('contact_id', $doc->contact_id)->where('status', '!=', 'annulled')->sum('balance') : null,
            'history' => DB::table('audit_logs')->where('auditable_type', SalesDocument::class)->where('auditable_id', $doc->id)->where('event', 'mail_sent')
                ->orderByDesc('id')->get(['new_values', 'created_at'])->map(function ($l) {
                    $v = json_decode((string) $l->new_values, true) ?: [];

                    return ['at' => \Illuminate\Support\Carbon::parse($l->created_at)->locale('es')->translatedFormat('j M Y - H:i'), 'to' => $v['to'] ?? '', 'name' => $v['name'] ?? ''];
                }),
            'modified_at' => $doc->updated_at && $doc->updated_at->ne($doc->created_at) && $doc->source === 'erp' ? $doc->updated_at->format('d/m/Y H:i') : null,
            'collection_task' => in_array($doc->type, ['FV', 'ND'], true) ? DB::table('crm_tasks')->where('company_id', $doc->company_id)->where('origin', 'Cobranza')
                ->where('source_document', $doc->name)->first(['id', 'status']) : null,
        ] + (in_array($doc->type, ['CT', 'RM'], true) ? self::quoteExtra($doc) : []);
    }

    /** Cotizaciones y remisiones: datos del vendedor que firma, estado y comprobantes generados a partir de ellas. */
    private static function quoteExtra(SalesDocument $doc): array
    {
        $seller = $doc->seller_id ? DB::table('sellers as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('s.id', $doc->seller_id)->first(['s.name', 's.email', 's.phone', 'u.email as user_email']) : null;
        $child = fn (string $type) => DB::table('sales_documents')->where('related_document_id', $doc->id)->where('type', $type)->where('status', '!=', 'annulled')->orderBy('id')->first(['id', 'name']);
        $link = fn ($d) => $d ? ['name' => $d->name, 'url' => "/documentos/ventas/{$d->id}"] : null;

        return [
            'seller_info' => $seller ? ['name' => $seller->name, 'email' => $seller->email ?: $seller->user_email, 'phone' => $seller->phone] : null,
            'quote_status' => $doc->status,
            'invoice_link' => $link($child('FV')),
            'remission_link' => $doc->type === 'CT' ? $link($child('RM')) : null,
            'source_link' => $doc->related_document_id ? $link(DB::table('sales_documents')->where('id', $doc->related_document_id)->first(['id', 'name'])) : null,
            'public_url' => $doc->public_url,
            'contact_person' => $doc->contact_person_id ? DB::table('contact_people')->where('id', $doc->contact_person_id)->selectRaw("TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) AS n")->value('n') : null,
        ];
    }

    /** Valor en letras como lo imprime Siigo: "Cuatrocientos cuarenta y seis mil quinientos pesos m/cte". */
    public static function words(float $value): string
    {
        $words = new \NumberFormatter('es', \NumberFormatter::SPELLOUT);
        $cents = (int) round(fmod($value, 1) * 100);
        $w = str_replace("\u{AD}", '', $words->format((int) $value));

        return mb_strtoupper(mb_substr($w, 0, 1)).mb_substr($w, 1).' pesos'.($cents ? ' con '.str_replace("\u{AD}", '', $words->format($cents)).' centavos' : '').' m/cte';
    }

    private static function receipt(Receipt $doc): array
    {
        $items = $doc->items()->orderBy('id')->get();
        $adjustments = DB::table('receipt_item_adjustments')->whereIn('receipt_item_id', $items->pluck('id'))->orderBy('id')->get()->groupBy('receipt_item_id');
        $contact = DB::table('contacts as c')->leftJoin('municipalities as m', 'm.code', '=', 'c.municipality_code')->where('c.id', $doc->contact_id)
            ->first(['c.dv', 'c.phone_indicative', 'c.phone', 'c.mobile', 'm.name as city']);

        return [
            'module_name' => $doc->type === 'RC' ? 'ventas' : 'compras',
            'payment' => $doc->payment_name,
            'items' => $items->map(fn ($i) => [
                'code' => $i->due_number ? "{$i->due_prefix}-{$i->due_number} - Cuota ".($i->due_quote ?: 1) : ($i->description ?? ''),
                'description' => $i->due_number ? (in_array($i->description, [null, '', "{$i->due_prefix}-{$i->due_number}"], true) ? 'Abono' : $i->description) : ($i->description === 'Anticipo' || $doc->receipt_type === 'AdvancePayment' ? 'Anticipo' : 'Otro ingreso'),
                'due_date' => $i->due_date ? substr((string) $i->due_date, 0, 10) : null,
                'total' => (float) $i->value + (float) $i->adjustments,
                'adjustments' => ($adjustments[$i->id] ?? collect())->map(fn ($a) => ['name' => $a->name, 'base' => (float) $a->base, 'value' => (float) $a->value])->values(),
                'url' => $i->sales_document_id ? "/documentos/ventas/{$i->sales_document_id}" : ($i->purchase_document_id ? "/documentos/compras/{$i->purchase_document_id}" : null),
            ]),
            'contact_extra' => ['dv' => $contact?->dv, 'phone' => trim('('.($contact?->phone_indicative ?: '000').') '.($contact?->phone ?: $contact?->mobile)), 'city' => $contact?->city],
            'created' => trim((DB::table('users')->where('id', $doc->created_by)->value('name') ?? '').' '.$doc->created_at?->locale('es')->translatedFormat('j M Y H:i:s').' hrs'),
            'words' => self::words((float) $doc->total),
            'totals' => ['total' => (float) $doc->total, 'adjustments' => (float) $items->sum('adjustments')],
        ];
    }

    private static function journal(JournalEntry $doc): array
    {
        $lines = $doc->lines()->with(['account:id,code,name', 'contact:id,identification,name'])->orderBy('id')->get();
        $products = DB::table('products')->whereIn('id', $lines->pluck('product_id')->filter())->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'module_name' => self::module($doc),
            'journal' => $lines->map(fn ($l) => [
                'account' => $l->account ? "{$l->account->code} - {$l->account->name}" : '', 'contact' => $l->contact?->name ?? '',
                'description' => $l->description ?: ($products[$l->product_id]->name ?? null), 'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
                'detail' => $l->due_number ? "{$l->due_prefix}-{$l->due_number}-1"
                    : (($p = $products[$l->product_id] ?? null) ? "Prod: {$p->code} Cant: ".rtrim(rtrim(number_format(abs((float) $l->quantity), 4, '.', ''), '0'), '.') : ''),
            ]),
            'source_type' => $doc->source_type,
            'created' => ['by' => $doc->created_by ? DB::table('users')->where('id', $doc->created_by)->value('name') : null, 'at' => $doc->created_at?->format('d/m/Y H:i')],
            'totals' => ['debit' => (float) $doc->debit, 'credit' => (float) $doc->credit, 'total' => (float) $doc->debit],
            'printable' => true,
        ];
    }

    /** Datos con la forma del formulario de creacion (Trade.vue / JournalEntry.vue). */
    public static function payload(Model $doc): array
    {
        if ($doc instanceof JournalEntry) {
            $lines = $doc->lines()->with(['account:id,code,name,due_type', 'contact:id,identification,name'])->orderBy('id')->get();
            $products = DB::table('products')->whereIn('id', $lines->pluck('product_id')->filter())->get(['id', 'code', 'name'])->keyBy('id');
            $groups = DB::table('account_groups')->where('company_id', $doc->company_id)->whereIn('inventory_account_id', $lines->pluck('account_id'))->pluck('id', 'inventory_account_id');

            return [
                'document_type' => $doc->document_type_id, 'date' => substr((string) $doc->date, 0, 10), 'observations' => $doc->observations ?? '',
                'items' => $lines->map(function ($l) use ($products, $groups) {
                    $dueType = $l->account?->due_type;
                    $due = null;
                    if ($dueType && $l->due_number) {
                        $model = $dueType === 'cartera' ? SalesDocument::class : PurchaseDocument::class;
                        $crossed = str_contains((string) $l->due_prefix, '-') ? $model::query()->where('contact_id', $l->contact_id)->where('name', "{$l->due_prefix}-{$l->due_number}")->first() : null;
                        $date = $l->due_date ? substr((string) $l->due_date, 0, 10) : null;
                        $due = $crossed
                            ? ['mode' => 'cruzar', 'document_id' => $crossed->id, 'label' => "{$crossed->name}-1"]
                            : ['mode' => 'crear', 'prefix' => $l->due_prefix, 'number' => $l->due_number, 'quote' => 1, 'due_date' => $date, 'label' => "{$l->due_prefix}-{$l->due_number}-1"];
                    }

                    return [
                        'account_id' => $l->account_id, 'account_label' => $l->account ? "{$l->account->code} - {$l->account->name}" : '', 'due_type' => $dueType,
                        'contact_id' => $l->contact_id, 'contact_label' => $l->contact?->name ?? '',
                        'due' => $due, 'description' => $l->description ?? '', 'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
                        'inventory_group' => $groups[$l->account_id] ?? null,
                        'inventory' => ($p = $products->get($l->product_id)) ? ['product_id' => $p->id, 'label' => "{$p->code} - {$p->name}", 'quantity' => abs((float) $l->quantity),
                            'value' => max((float) $l->debit, (float) $l->credit), 'type' => (float) $l->quantity < 0 ? 'Salida' : 'Entrada'] : null,
                    ];
                })->all(),
            ];
        }
        if ($doc instanceof Receipt) {
            return self::receiptPayload($doc);
        }

        $sales = $doc instanceof SalesDocument;
        $contact = DB::table('contacts')->where('id', $doc->contact_id)->first(['identification', 'name']);
        $items = $doc->items()->orderBy('position')->get();
        $taxTable = $sales ? 'sales_document_item_taxes' : 'purchase_document_item_taxes';
        $fk = $sales ? 'sales_document_item_id' : 'purchase_document_item_id';
        $taxes = DB::table($taxTable)->whereIn($fk, $items->pluck('id'))->get()->groupBy($fk);
        $products = DB::table('products')->whereIn('id', $items->pluck('product_id')->filter())->get(['id', 'code', 'name'])->keyBy('id');
        $accounts = DB::table('accounts')->whereIn('id', $items->pluck('account_id')->filter())->get(['id', 'code', 'name'])->keyBy('id');
        $included = false;

        $lines = $items->map(function ($i) use ($sales, $taxes, $products, $accounts, &$included) {
            $gross = (float) $i->quantity * (float) $i->price;
            $type = $sales ? 'Product' : ($i->item_type ?: 'Product');
            if ($type === 'FixedAsset') {
                $type = 'Account';
            }
            $id = $type === 'Product' ? $i->product_id : $i->account_id;
            $ref = $type === 'Product' ? ($products[$i->product_id] ?? null) : ($accounts[$i->account_id] ?? null);
            $t = $taxes[$i->id] ?? collect();
            $charge = $t->first(fn ($x) => ! in_array($x->type, \App\Models\Tax::WITHHOLDINGS, true));
            $withholding = $t->first(fn ($x) => in_array($x->type, \App\Models\Tax::WITHHOLDINGS, true));
            if ((float) $i->tax_total > 0 && abs($gross - (float) $i->discount - (float) $i->subtotal) > 0.5) {
                $included = true;
            }

            return [
                'type' => self::ITEM_TYPE[$type], 'item_id' => $id, 'label' => $ref ? "{$ref->code} - {$ref->name}" : (string) $i->code,
                'description' => $i->description ?? '', 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
                'discount' => $sales ? ($gross > 0 ? round((float) $i->discount / $gross * 100, 4) : 0) : (float) $i->discount,
                'tax_id' => $charge?->tax_id, 'withholding_id' => $withholding?->tax_id,
            ];
        })->all();

        $payments = DB::table($sales ? 'sales_document_payments' : 'purchase_document_payments')->where($sales ? 'sales_document_id' : 'purchase_document_id', $doc->id)->get();
        $credit = $payments->isNotEmpty() && $payments->every(fn ($p) => ! $p->payment_method_id);
        $out = [
            'document_type' => $doc->document_type_id, 'date' => substr((string) $doc->date, 0, 10),
            'related_id' => $doc->related_document_id, 'related_label' => $doc->related_name ? $doc->related_name.' - '.($contact->name ?? '') : '',
            'contact_id' => $doc->contact_id, 'contact_label' => $contact ? "{$contact->identification} - {$contact->name}" : '',
            'cost_center_id' => $doc->cost_center_id, 'observations' => $doc->observations ?? '',
            'currency' => $doc->currency ?: 'COP', 'exchange_rate' => (float) ($doc->exchange_rate ?? 1),
            'items' => $lines,
            'credit' => $credit,
            'due_date' => $credit ? substr((string) $doc->due_date, 0, 10) : null,
            'warehouse_id' => $items->pluck('warehouse_id')->filter()->first(),
            'payments' => $credit ? [] : $payments->map(fn ($p) => ['method_id' => $p->payment_method_id, 'value' => (float) $p->value, 'due_date' => $p->due_date ? substr((string) $p->due_date, 0, 10) : null])->all(),
        ];
        if ($sales) {
            $out += ['seller_id' => $doc->seller_id, 'reason' => $doc->reason ?? '', 'person_id' => $doc->contact_person_id, 'price_list_id' => $doc->price_list_id];
            $out['summary_retentions'] = (object) DB::table('sales_document_retentions')->where('sales_document_id', $doc->id)->whereNotNull('tax_id')->pluck('tax_id', 'type')->all();
        } else {
            $out += ['supplier_prefix' => $doc->supplier_prefix ?? '', 'supplier_number' => $doc->supplier_number ?? ''];
            $retentions = DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->get();
            $out['summary_retentions'] = (object) $retentions->mapWithKeys(fn ($r) => [$r->type => $r->tax_id])->all();
            $out['checks'] = (object) ['IVA / Impoconsumo incluido' => $included, 'Impuestos cargo incluidos' => $included, 'Descuento en porcentaje' => false];
        }

        return $out;
    }

    /** Recibo con la forma de CashReceipt.vue / PaymentReceipt.vue; el saldo de cada documento incluye lo que este recibo le abonó. */
    private static function receiptPayload(Receipt $doc): array
    {
        $contact = DB::table('contacts')->where('id', $doc->contact_id)->first(['identification', 'name']);
        $items = $doc->items()->orderBy('id')->get();
        $adjustments = DB::table('receipt_item_adjustments')->whereIn('receipt_item_id', $items->pluck('id'))->get()->groupBy('receipt_item_id');
        $model = $doc->type === 'RC' ? SalesDocument::class : PurchaseDocument::class;
        $fk = $doc->type === 'RC' ? 'sales_document_id' : 'purchase_document_id';
        $paid = $model::query()->whereIn('id', $items->pluck($fk)->filter())->get()->keyBy('id');
        $other = $items->first(fn ($i) => ! $i->$fk);

        return [
            'mode' => ['AdvancePayment' => 'anticipo', 'Other' => 'otro', 'Advanced' => 'avanzado'][$doc->receipt_type] ?? 'deuda',
            'lines' => $doc->receipt_type === 'Advanced' ? $items->map(fn ($i) => [
                'account_id' => $i->account_id, 'account_label' => ($a = DB::table('accounts')->where('id', $i->account_id)->first(['code', 'name', 'due_type'])) ? "{$a->code} - {$a->name}" : '',
                'due_type' => $a->due_type ?? null, 'document_id' => $i->$fk ?: null, 'description' => $i->description ?? '', 'debit' => ($v = $doc->type === 'RC' ? -(float) $i->value : (float) $i->value) > 0 ? $v : 0, 'credit' => $v < 0 ? -$v : 0,
            ])->values()->all() : [],
            'document_type' => $doc->document_type_id, 'date' => substr((string) $doc->date, 0, 10),
            'contact_id' => $doc->contact_id, 'contact_label' => $contact->name ?? '',
            'payment_method_id' => $doc->payment_method_id, 'bank_name' => $doc->payment_name, 'value' => (float) $doc->total, 'observations' => $doc->observations ?? '',
            'concept_id' => $other ? DB::table('frequent_concepts')->where('company_id', $doc->company_id)->where('name', $other->description)->value('id') : null,
            'items' => $items->filter(fn ($i) => $i->$fk && isset($paid[$i->$fk]))->map(function ($i) use ($paid, $fk, $adjustments) {
                $d = $paid[$i->$fk];
                $adj = $adjustments[$i->id] ?? collect();

                return [
                    'id' => $d->id, 'name' => $d->name, 'due_date' => ($d->due_date ?? $d->date)?->format('d/m/Y'),
                    'reference' => $d instanceof PurchaseDocument ? (trim(($d->supplier_prefix ?? '').'-'.($d->supplier_number ?? ''), '-') ?: $d->name) : $d->name,
                    'balance' => round((float) $d->balance + (float) $i->value + (float) $i->adjustments, 2), 'pay' => (float) $i->value,
                    'taxes' => $adj->where('kind', 'tax')->map(fn ($a) => ['tax_id' => $a->tax_id, 'base' => (float) $a->base, 'value' => (float) $a->value])->values()->all(),
                    'discounts' => $adj->where('kind', 'discount')->map(fn ($a) => ['concept_id' => $a->frequent_concept_id, 'value' => (float) $a->value])->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
