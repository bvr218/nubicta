<?php

namespace App\Http\Controllers\ApiV1;

use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\Receipt;
use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;

/** Forma JSON de cada recurso de la API /api/v1 (campos en español, fechas ISO y valores numéricos). */
final class ApiPresenter
{
    public const PRODUCT_TYPES = ['producto' => 'Product', 'servicio' => 'Service', 'combo' => 'Combo'];

    public const TAX_CLASSES = ['gravado' => 'Taxed', 'exento' => 'Exempt', 'excluido' => 'Excluded'];

    /** Acepta el valor en español de la API o el interno. */
    public static function internal(mixed $value, array $map): mixed
    {
        return is_string($value) ? ($map[mb_strtolower($value)] ?? $value) : $value;
    }

    public static function contact(Contact $c): array
    {
        return [
            'id' => $c->id, 'tipo_persona' => $c->person_type, 'tipo_identificacion' => $c->identification_type,
            'identificacion' => $c->identification, 'dv' => $c->dv, 'sucursal' => (int) $c->branch_office, 'nombre' => $c->name,
            'nombres' => $c->first_name, 'apellidos' => $c->last_name, 'razon_social' => $c->business_name,
            'nombre_comercial' => $c->trade_name, 'correo' => $c->email, 'telefono' => $c->mobile ?: $c->phone,
            'direccion' => $c->address, 'pais' => $c->country_code ?: 'CO', 'ciudad' => $c->municipality_code,
            'ciudad_extranjera' => $c->foreign_city, 'codigo_postal' => $c->postal_code,
            'responsable_iva' => (bool) $c->vat_responsible, 'responsabilidades_fiscales' => $c->fiscal_responsibilities ?? [],
            'roles' => array_keys(array_filter(array_map(fn ($col) => (bool) $c->{$col}, Contact::ROLES))),
            'vendedor_id' => $c->seller_id, 'lista_precio_id' => $c->price_list_id, 'cupo_credito' => (float) $c->credit_limit,
            'dias_credito' => (int) $c->credit_days, 'observaciones' => $c->observations, 'activo' => (bool) $c->active,
            'creado' => $c->created_at?->toIso8601String(), 'actualizado' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function product(Product $p): array
    {
        $stock = $p->stock_control ? DB::table('warehouse_stock')->where('product_id', $p->id)->join('warehouses', 'warehouses.id', '=', 'warehouse_stock.warehouse_id')
            ->get(['warehouses.id', 'warehouses.name', 'warehouse_stock.quantity'])->map(fn ($w) => ['bodega_id' => $w->id, 'bodega' => $w->name, 'cantidad' => (float) $w->quantity])->values() : [];

        return [
            'id' => $p->id, 'codigo' => $p->code, 'nombre' => $p->name, 'tipo' => array_search($p->type, self::PRODUCT_TYPES, true) ?: $p->type, 'grupo_inventario_id' => $p->account_group_id,
            'referencia' => $p->reference, 'descripcion' => $p->description, 'codigo_barras' => $p->barcode, 'marca' => $p->brand, 'modelo' => $p->model,
            'unidad_dian' => $p->unit_code, 'unidad' => $p->unit_label, 'inventariable' => (bool) $p->stock_control, 'visible_en_ventas' => (bool) $p->visible_in_sales,
            'clasificacion_tributaria' => array_search($p->tax_classification, self::TAX_CLASSES, true) ?: $p->tax_classification, 'iva_incluido' => (bool) $p->tax_included, 'arancel' => $p->tariff,
            'existencias' => (float) $p->stock, 'existencias_por_bodega' => $stock, 'existencia_minima' => (float) $p->min_stock, 'costo_promedio' => (float) $p->average_cost,
            'precios' => $p->prices->map(fn ($x) => ['lista_precio_id' => $x->price_list_id, 'valor' => (float) $x->value])->values(),
            'impuestos' => $p->taxes->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->name, 'tipo' => $t->type, 'porcentaje' => (float) $t->percentage])->values(),
            'activo' => (bool) $p->active, 'creado' => $p->created_at?->toIso8601String(), 'actualizado' => $p->updated_at?->toIso8601String(),
        ];
    }

    public static function sale(SalesDocument $d, bool $detail = false): array
    {
        $out = [
            'id' => $d->id, 'tipo' => $d->type, 'tipo_documento_id' => $d->document_type_id, 'numero' => $d->name, 'consecutivo' => (int) $d->number,
            'fecha' => $d->date?->toDateString(), 'vencimiento' => $d->due_date?->toDateString(),
            'cliente' => ['id' => $d->contact_id, 'identificacion' => $d->contact?->identification, 'nombre' => $d->contact?->name],
            'vendedor_id' => $d->seller_id, 'centro_costo_id' => $d->cost_center_id, 'lista_precio_id' => $d->price_list_id,
            'documento_relacionado' => $d->related_document_id ? ['id' => $d->related_document_id, 'numero' => $d->related_name] : null, 'motivo' => $d->reason,
            'subtotal' => (float) $d->subtotal, 'descuento' => (float) $d->discount, 'impuestos' => (float) $d->tax_total, 'retenciones' => (float) $d->withholding_total,
            'total' => (float) $d->total, 'saldo' => (float) $d->balance, 'observaciones' => $d->observations,
            'referencia_externa' => $d->external_reference, 'forma_pago' => $d->payment_form === 'contado' ? 'contado' : null,
            'mandante' => $d->principal ? ['identificacion' => $d->principal['identificacion'] ?? null, 'nombre' => $d->principal['nombre'] ?? null] : null,
            'factura_externa' => $d->external_invoice,
            'estado' => $d->status === 'annulled' ? 'anulado' : 'emitido', 'cufe' => $d->cufe, 'estado_dian' => $d->dian_status, 'mensaje_dian' => $d->dian_message,
            'estado_correo' => $d->mail_status, 'creado' => $d->created_at?->toIso8601String(), 'actualizado' => $d->updated_at?->toIso8601String(),
        ];
        if ($detail) {
            $out['items'] = $d->items->sortBy('position')->values()->map(fn ($i) => [
                'producto_id' => $i->product_id, 'codigo' => $i->code, 'descripcion' => $i->description, 'cantidad' => (float) $i->quantity, 'precio' => (float) $i->price,
                'descuento' => (float) $i->discount, 'subtotal' => (float) $i->subtotal, 'impuestos' => (float) $i->tax_total, 'porcentaje_impuesto' => (float) $i->tax_percentage,
                'total' => (float) $i->total, 'bodega_id' => $i->warehouse_id, 'vendedor_id' => $i->seller_id,
                'rol' => $i->role, 'impuesto' => $i->tax_code === 'ZZ' ? 'ZZ' : null,
            ]);
            $out['pagos'] = DB::table('sales_document_payments')->where('sales_document_id', $d->id)->get()
                ->map(fn ($p) => ['forma_pago_id' => $p->payment_method_id, 'nombre' => $p->name, 'valor' => (float) $p->value, 'vencimiento' => $p->due_date])->values();
            $out['retenciones_detalle'] = DB::table('sales_document_retentions')->where('sales_document_id', $d->id)->get()
                ->map(fn ($r) => ['impuesto_id' => $r->tax_id, 'nombre' => $r->name, 'tipo' => $r->type, 'porcentaje' => (float) $r->percentage, 'base' => (float) $r->base, 'valor' => (float) $r->value])->values();
        }

        return $out;
    }

    public static function purchase(PurchaseDocument $d, bool $detail = false): array
    {
        $out = [
            'id' => $d->id, 'tipo' => $d->type, 'tipo_documento_id' => $d->document_type_id, 'numero' => $d->name, 'consecutivo' => (int) $d->number,
            'fecha' => $d->date?->toDateString(), 'vencimiento' => $d->due_date?->toDateString(),
            'proveedor' => ['id' => $d->contact_id, 'identificacion' => $d->contact?->identification, 'nombre' => $d->contact?->name],
            'factura_proveedor' => ['prefijo' => $d->supplier_prefix, 'numero' => $d->supplier_number], 'centro_costo_id' => $d->cost_center_id,
            'documento_relacionado' => $d->related_document_id ? ['id' => $d->related_document_id, 'numero' => $d->related_name] : null,
            'subtotal' => (float) $d->subtotal, 'descuento' => (float) $d->discount, 'impuestos' => (float) $d->tax_total, 'retenciones' => (float) $d->withholding_total,
            'total' => (float) $d->total, 'saldo' => (float) $d->balance, 'observaciones' => $d->observations,
            'estado' => $d->status === 'annulled' ? 'anulado' : 'emitido', 'cuds' => $d->cuds, 'estado_dian' => $d->dian_status, 'mensaje_dian' => $d->dian_message,
            'creado' => $d->created_at?->toIso8601String(), 'actualizado' => $d->updated_at?->toIso8601String(),
        ];
        if ($detail) {
            $out['items'] = $d->items->sortBy('position')->values()->map(fn ($i) => [
                'tipo' => ['Product' => 'producto', 'Account' => 'cuenta', 'FixedAsset' => 'activo'][$i->item_type] ?? $i->item_type,
                'producto_id' => $i->product_id, 'cuenta_id' => $i->account_id, 'codigo' => $i->code, 'descripcion' => $i->description,
                'cantidad' => (float) $i->quantity, 'precio' => (float) $i->price, 'descuento' => (float) $i->discount, 'subtotal' => (float) $i->subtotal,
                'impuestos' => (float) $i->tax_total, 'porcentaje_impuesto' => (float) $i->tax_percentage, 'total' => (float) $i->total, 'bodega_id' => $i->warehouse_id,
            ]);
            $out['pagos'] = DB::table('purchase_document_payments')->where('purchase_document_id', $d->id)->get()
                ->map(fn ($p) => ['forma_pago_id' => $p->payment_method_id, 'nombre' => $p->name, 'valor' => (float) $p->value, 'vencimiento' => $p->due_date])->values();
        }

        return $out;
    }

    public static function receipt(Receipt $r, bool $detail = false): array
    {
        $out = [
            'id' => $r->id, 'tipo' => $r->type, 'modalidad' => ['DebtPayment' => 'abono', 'AdvancePayment' => 'anticipo', 'Other' => 'otro', 'Advanced' => 'avanzado'][$r->receipt_type] ?? $r->receipt_type,
            'tipo_documento_id' => $r->document_type_id, 'numero' => $r->name, 'consecutivo' => (int) $r->number, 'fecha' => $r->date?->toDateString(),
            'tercero' => ['id' => $r->contact_id, 'identificacion' => $r->contact?->identification, 'nombre' => $r->contact?->name],
            'forma_pago' => ['id' => $r->payment_method_id, 'nombre' => $r->payment_name], 'total' => (float) $r->total, 'observaciones' => $r->observations,
            'estado' => $r->status === 'annulled' ? 'anulado' : 'emitido', 'creado' => $r->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $out['items'] = $r->items->map(fn ($i) => [
                'documento_id' => $i->sales_document_id ?? $i->purchase_document_id, 'documento' => $i->due_prefix ? "{$i->due_prefix}-{$i->due_number}" : null,
                'cuenta_id' => $i->account_id, 'descripcion' => $i->description, 'valor' => (float) $i->value, 'ajustes' => (float) $i->adjustments,
            ])->values();
        }

        return $out;
    }

    public static function journal(JournalEntry $j, bool $detail = false): array
    {
        $out = [
            'id' => $j->id, 'tipo_documento_id' => $j->document_type_id, 'numero' => $j->name, 'consecutivo' => (int) $j->number, 'fecha' => $j->date?->toDateString(),
            'observaciones' => $j->observations, 'debitos' => (float) $j->debit, 'creditos' => (float) $j->credit,
            'estado' => $j->status === 'annulled' ? 'anulado' : 'contabilizado', 'creado' => $j->created_at?->toIso8601String(),
        ];
        if ($detail) {
            $codes = DB::table('accounts')->whereIn('id', $j->lines->pluck('account_id'))->pluck('code', 'id');
            $out['lineas'] = $j->lines->map(fn ($l) => [
                'cuenta_id' => $l->account_id, 'cuenta' => $codes[$l->account_id] ?? null, 'tercero_id' => $l->contact_id, 'centro_costo_id' => $l->cost_center_id,
                'descripcion' => $l->description, 'debito' => (float) $l->debit, 'credito' => (float) $l->credit,
                'cruce' => $l->due_prefix ? ['prefijo' => $l->due_prefix, 'numero' => $l->due_number, 'vencimiento' => $l->due_date] : null,
                'producto_id' => $l->product_id, 'cantidad' => $l->quantity !== null ? (float) $l->quantity : null,
            ])->values();
        }

        return $out;
    }
}
