<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\Contact;
use App\Models\FixedAsset;
use App\Models\Municipality;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Tax;
use App\Services\Documents\TradeRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Busquedas para selectores (autocompletar) del frontend. */
class LookupController extends Controller
{
    /** Ademas del tercero devuelve lo que se autollena en los documentos: contacto, vendedor, lista de precios, credito y responsabilidades. */
    public function contacts(Request $request): JsonResponse
    {
        $fields = ['id', 'name', 'identification', 'dv', 'branch_office', 'seller_id', 'collector_id', 'price_list_id', 'credit_days', 'credit_limit',
            'email', 'address', 'phone', 'mobile', 'person_type', 'vat_responsible', 'fiscal_responsibilities', 'municipality_code'];

        return response()->json(
            Contact::query()
                ->when($request->integer('id'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->search($request->input('q'))->role($request->input('tipo'))->where('active', true))
                ->with('people:id,contact_id,first_name,last_name,email,is_payer')
                ->orderBy('name')->limit($this->limit($request))->get($fields)
                ->map(fn ($c) => [
                    ...$c->only('id', 'name', 'identification', 'dv', 'branch_office', 'seller_id', 'collector_id', 'price_list_id', 'email', 'address', 'person_type', 'municipality_code'),
                    'credit_days' => (int) $c->credit_days,
                    'credit_limit' => (float) $c->credit_limit,
                    'phone' => $c->mobile ?: $c->phone,
                    'vat_responsible' => (bool) $c->vat_responsible,
                    'responsibilities' => array_values((array) $c->fiscal_responsibilities),
                    'label' => "{$c->identification} - {$c->name}",
                    'branch' => (string) ($c->branch_office ?? 0),
                    'people' => $c->people->sortBy(fn ($p) => [mb_strtoupper(trim("{$p->first_name} {$p->last_name}")) === mb_strtoupper(trim($c->name)) ? 0 : 1, $p->is_payer ? 0 : 1])
                        ->values()->map(fn ($p) => ['id' => $p->id, 'name' => trim("{$p->first_name} {$p->last_name}"), 'email' => $p->email]),
                ])
        );
    }

    /** `lista` = lista de precios del cliente; `prices` permite recalcular si cambia el cliente. */
    public function products(Request $request): JsonResponse
    {
        $list = $request->integer('lista') ?: null;

        return response()->json(
            Product::query()->search($request->input('q'))->where('active', true)
                ->when($request->input('cuenta'), fn ($q, $account) => $q->whereIn('account_group_id', AccountGroup::query()->where('inventory_account_id', (int) $account)->select('id'))->where('stock_control', true))
                ->with(['taxes:id,name,type,percentage', 'prices', 'comboItems.product:id,code,name,reference,unit_label'])->orderBy('name')->limit($this->limit($request))->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'label' => "{$p->code} - {$p->name}", 'kind' => $p->type,
                    'price' => $p->priceFor($list), 'prices' => $p->prices->mapWithKeys(fn ($x) => [$x->price_list_id => (float) $x->value]),
                    'stock' => (float) $p->stock, 'stock_control' => (bool) $p->stock_control, 'unit' => $p->unit_label, 'reference' => $p->reference ?? null,
                    'status' => 'Activo', 'tax_included' => (bool) $p->tax_included,
                    'cost' => $request->user()?->can('inventario.ver_costos') ? (float) $p->average_cost : null,
                    'tax_id' => $p->taxes->first(fn ($t) => ! in_array($t->type, Tax::WITHHOLDINGS, true))?->id,
                    'withholding_id' => $p->taxes->first(fn ($t) => in_array($t->type, Tax::WITHHOLDINGS, true))?->id,
                    'components' => $p->comboItems->filter(fn ($c) => $c->product)->map(fn ($c) => [
                        'product_id' => $c->product_id, 'label' => "{$c->product->code} - {$c->product->name}", 'description' => $c->product->name,
                        'reference' => $c->product->reference ?? '', 'unit' => $c->product->unit_label ?? '', 'quantity' => (float) $c->quantity,
                    ])->values(),
                ])
        );
    }

    public function fixedAssets(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));

        return response()->json(
            FixedAsset::query()->where('active', true)
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', "$term%")->orWhere('name', 'like', "%$term%")))
                ->orderBy('code')->limit(20)->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} - {$a->name}", 'name' => $a->name, 'price' => 0])
        );
    }

    /** Documentos de venta (FV) o de compra (FC/DS) para notas credito/debito y ajustes. */
    public function documents(Request $request): JsonResponse
    {
        $purchases = $request->input('modulo') === 'compras';
        \Illuminate\Support\Facades\Gate::authorize($purchases ? 'compras.ver' : 'ventas.ver');
        $model = $purchases ? PurchaseDocument::class : SalesDocument::class;
        $types = $purchases ? ['FC', 'DS'] : ['FV'];
        $term = trim((string) $request->input('q'));

        return response()->json(
            $model::query()->whereIn('type', $types)->with('contact:id,name,identification')
                ->when($request->input('contact'), fn ($q, $c) => $q->where('contact_id', $c))
                ->when($term !== '', fn ($q) => $q->where('name', 'like', "%$term%"))
                ->orderByDesc('date')->limit(20)->get()
                ->map(fn ($d) => [
                    'id' => $d->id, 'label' => "{$d->name} - ".($d->contact?->name ?? ''), 'name' => $d->name,
                    'contact_id' => $d->contact_id, 'contact_label' => $d->contact ? "{$d->contact->identification} - {$d->contact->name}" : '',
                    'date' => $d->date->format('d/m/Y'), 'total' => (float) $d->total, 'balance' => (float) $d->balance,
                ])
        );
    }

    /** Items de un documento para precargar notas credito/debito y notas de ajuste. */
    /** Encabezado de la factura que se corrige: la nota toma su cliente, contacto, vendedor y formas de pago. */
    public function documentHeader(Request $request, int $id): JsonResponse
    {
        $purchases = $request->input('modulo') === 'compras';
        \Illuminate\Support\Facades\Gate::authorize($purchases ? 'compras.ver' : 'ventas.ver');
        $doc = ($purchases ? PurchaseDocument::class : SalesDocument::class)::query()->findOrFail($id);

        return response()->json(collect(\App\Services\Documents\DocumentLocator::payload($doc))
            ->only(['contact_id', 'contact_label', 'person_id', 'seller_id', 'cost_center_id', 'credit', 'due_date', 'payments'])
            ->merge(['principal' => $doc->principal, 'payment_form' => $doc->payment_form])->all());
    }

    public function documentItems(Request $request, int $id): JsonResponse
    {
        $purchases = $request->input('modulo') === 'compras';
        \Illuminate\Support\Facades\Gate::authorize($purchases ? 'compras.ver' : 'ventas.ver');
        $doc = ($purchases ? PurchaseDocument::class : SalesDocument::class)::query()->with('items')->findOrFail($id);
        $taxes = Tax::query()->where('active', true)->where('type', 'IVA')->get(['id', 'percentage']);
        $types = ['Product' => 'Producto', 'Account' => 'Cuenta contable', 'FixedAsset' => 'Activo fijo'];

        return response()->json($doc->items->sortBy('position')->values()->map(fn ($i) => [
            'type' => $types[$i->item_type ?? 'Product'] ?? 'Producto',
            'product_id' => $i->product_id, 'account_id' => $i->account_id ?? null,
            'label' => trim(($i->code ? "{$i->code} - " : '').$i->description),
            'description' => $i->description, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
            'discount' => $purchases ? (float) $i->discount : TradeRegistrar::discountPercent((float) $i->quantity, (float) $i->price, (float) $i->discount),
            'tax_id' => $i->tax_percentage > 0 ? $taxes->first(fn ($t) => abs((float) $t->percentage - (float) $i->tax_percentage) < 0.01)?->id : null,
            'role' => $i->role ?? null, 'tax_code' => $i->tax_code ?? null, 'free' => ! $i->product_id && ! ($i->account_id ?? null),
        ]));
    }

    /** Documentos con saldo pendiente de un tercero (recibos de caja y de pago). */
    public function pending(Request $request): JsonResponse
    {
        $purchases = $request->input('modulo') === 'compras';
        \Illuminate\Support\Facades\Gate::authorize($purchases ? 'compras.ver' : 'ventas.ver');
        $model = $purchases ? PurchaseDocument::class : SalesDocument::class;

        return response()->json(
            $model::query()->where('contact_id', $request->integer('contact'))->where('balance', '>', 0)
                ->orderBy('due_date')->orderBy('date')->limit(200)->get()
                ->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name, 'due_date' => ($d->due_date ?? $d->date)?->format('d/m/Y'),
                    'balance' => (float) $d->balance, 'total' => (float) $d->total, 'subtotal' => (float) $d->subtotal,
                    'reference' => $purchases ? (trim(($d->supplier_prefix ?? '').'-'.($d->supplier_number ?? ''), '-') ?: $d->name) : $d->name,
                ])
        );
    }

    /** Ventana "Cruce deuda con anticipos" de los recibos: deuda pendiente y saldo de anticipos del tercero. */
    public function advances(Request $request): JsonResponse
    {
        return response()->json(app(\App\Services\Documents\AdvanceCrossing::class)->summary($request->input('modulo') === 'ventas' ? 'ventas' : 'compras', $request->integer('contact')));
    }

    public function accounts(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));

        $accounts = Account::query()
            ->when($request->boolean('transactional', true), fn ($q) => $q->where('transactional', true))
            ->where('active', true)
            ->when($request->input('prefijo'), fn ($q, $p) => $q->where('code', 'like', "$p%"))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', "$term%")
                ->orWhere('name', 'like', "%$term%")))
            ->orderBy('code')
            ->limit($this->limit($request))
            ->get(['id', 'code', 'name', 'category', 'due_type', 'requires_third_party']);
        $groups = AccountGroup::query()->whereIn('inventory_account_id', $accounts->pluck('id'))->pluck('id', 'inventory_account_id');

        return response()->json($accounts->map(fn ($a) => ['id' => $a->id, 'label' => "{$a->code} - {$a->name}", 'code' => $a->code, 'name' => $a->name,
            'category' => $a->category, 'due_type' => $a->due_type, 'third' => (bool) $a->requires_third_party, 'inventory_group' => $groups[$a->id] ?? null]));
    }

    /** Ciudades con su departamento para el campo "Ciudad". */
    public function municipalities(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));

        $rows = Municipality::query()
            ->join('departments', 'departments.code', '=', 'municipalities.department_code')
            ->when($term !== '', fn ($q) => $q->where('municipalities.name', 'like', "$term%"))
            ->orderByRaw('municipalities.name = ? desc', [$term])
            ->orderBy('municipalities.name')
            ->limit(30)
            ->get(['municipalities.code', 'municipalities.name', 'municipalities.department_code', 'departments.name as department']);

        return response()->json($rows->map(fn ($m) => [
            'code' => $m->code, 'department_code' => $m->department_code, 'label' => "{$m->name} - {$m->department}",
        ]));
    }

    private function limit(Request $request): int
    {
        return min(max($request->integer('limite', 20), 10), 100);
    }
}
