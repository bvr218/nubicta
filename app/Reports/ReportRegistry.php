<?php

namespace App\Reports;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Informes del catalogo resueltos con una consulta: filtros, columnas, totales y exportacion.
 * Columnas: [clave => [titulo, formato]] con formato text|money|qty|int|pct|date.
 */
final class ReportRegistry
{
    public const MONTHS = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    private const SIGN = "(CASE WHEN d.type = 'NC' THEN -1 ELSE 1 END)";

    /** @return array<string, array> */
    public static function all(): array
    {
        return [
            // ---------------- Ventas ----------------
            'ventas-cliente' => [
                'title' => 'Ventas por cliente', 'filters' => ['seller', 'range', 'contact', 'nc', 'pos'], 'primary' => ['seller'],
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Cliente', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::salesValueColumns('Descuentos por item'), 'cargos' => ['Cargo en totales', 'money'], 'descuentos_totales' => ['Descuento en totales', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'links' => ['cliente' => '/terceros/{contact_id}', 'documentos' => '/reportes/movimiento-facturas-venta?tercero={contact_id}&desde={from}&hasta={to}'],
                'query' => fn ($f) => self::salesDocs($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('c.id contact_id, '.self::ident('c').' ident, '.self::branch('c').' sucursal, c.name cliente, count(*) documentos, '.self::salesDocSums())
                    ->groupBy('c.id', 'c.identification', 'c.dv', 'c.branch_office', 'c.name')->orderByRaw('cast(c.identification as unsigned)'),
            ],
            'ventas-producto' => [
                'title' => 'Ventas por producto', 'filters' => ['product', 'seller', 'nc', 'range', 'group'], 'primary' => ['product'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'], 'grupo' => ['Grupo inventario', 'text'],
                    'cantidad' => ['Cantidad vendida', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'links' => ['cantidad' => '/reportes/ventas-producto-facturas?producto={product_id}&desde={from}&hasta={to}&nc={nc}&vendedor={?seller}'],
                'query' => fn ($f) => self::salesLines($f, 'coalesce(i.seller_id, d.seller_id)')
                    ->selectRaw('max(i.product_id) product_id, '.(($f['nc'] ?? true) ? '1' : '0').' nc, i.code codigo, coalesce(max(p.name), max(i.description)) producto, max(p.reference) referencia, max(ag.name) grupo, '.self::salesItemSums())
                    ->groupBy('i.code')->orderByRaw('cast(i.code as unsigned), i.code'),
            ],
            'ventas-producto-facturas' => [
                'title' => 'Facturas del producto', 'filters' => ['product', 'seller', 'nc', 'range'], 'primary' => ['product'],
                'note' => 'Comprobantes que incluyen este producto en el periodo. Abre el comprobante para anularlo, editarlo o consultar la venta.',
                'columns' => ['fecha' => ['Fecha', 'date'], 'documento' => ['Comprobante', 'text'], 'tipo' => ['Tipo', 'text'], 'cliente' => ['Cliente', 'text'], 'vendedor' => ['Vendedor', 'text'],
                    'cantidad' => ['Cantidad', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'links' => ['documento' => '/documentos/ventas/{doc_id}'],
                'query' => fn ($f) => self::salesLines($f, 'coalesce(i.seller_id, d.seller_id)')
                    ->when(! ($f['product'] ?? null), fn ($q) => $q->whereRaw('1 = 0'))
                    ->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->leftJoin('sellers as sv', 'sv.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)'))
                    ->selectRaw("d.id doc_id, d.date fecha, d.name documento, case d.type when 'FV' then 'Factura de venta' when 'NC' then 'Nota crédito' when 'ND' then 'Nota débito' else d.type end tipo, c.name cliente, max(sv.name) vendedor, ".self::salesItemSums())
                    ->groupBy('d.id', 'd.date', 'd.number', 'd.name', 'd.type', 'c.id', 'c.name')
                    ->orderBy('d.date')->orderBy('d.number'),
            ],
            'ventas-vendedor' => [
                'title' => 'Ventas por vendedor', 'filters' => ['seller', 'contact', 'range', 'nc'], 'primary' => ['seller'],
                'columns' => ['ident' => ['Identificación', 'text'], 'vendedor' => ['Nombre vendedor', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesDocs($f)->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                    ->selectRaw("s.id seller_id, s.identification ident, coalesce(s.name, 'Sin vendedor') vendedor, count(*) documentos, ".self::salesDocSums())
                    ->groupBy('s.id', 's.identification', 's.name')->orderBy('vendedor'),
            ],
            'ventas-cliente-producto' => [
                'title' => 'Ventas por cliente por producto', 'filters' => ['contact', 'product', 'seller', 'group', 'range', 'nc'], 'primary' => ['contact'],
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Cliente', 'text'], 'codigo' => ['Código producto', 'text'],
                    'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'], 'grupo' => ['Grupo inventario', 'text'],
                    'cantidad' => ['Cantidad vendida', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesLines($f, 'coalesce(i.seller_id, d.seller_id)')->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw(self::ident('c').' ident, '.self::branch('c').' sucursal, c.name cliente, i.code codigo, coalesce(max(p.name), max(i.description)) producto, max(p.reference) referencia, max(ag.name) grupo, '.self::salesItemSums())
                    ->groupBy('c.id', 'c.identification', 'c.dv', 'c.branch_office', 'c.name', 'i.code')->orderByRaw('cast(c.identification as unsigned)')->orderByRaw('cast(i.code as unsigned), i.code'),
            ],
            'ventas-vendedor-producto' => [
                'title' => 'Ventas por vendedor por producto', 'filters' => ['seller', 'product', 'group', 'range', 'nc'], 'primary' => ['seller'],
                'columns' => ['vendedor' => ['Nombre vendedor', 'text'], 'codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'grupo' => ['Grupo inventario', 'text'], 'cantidad' => ['Cantidad vendida', 'qty'], ...self::salesValueColumns('Descuento'), 'total' => ['Total', 'money']],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'total'],
                'query' => fn ($f) => self::salesLines($f, 'coalesce(i.seller_id, d.seller_id)')->leftJoin('sellers as s', 's.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)'))
                    ->selectRaw("coalesce(s.name, 'Sin vendedor') vendedor, i.code codigo, coalesce(max(p.name), max(i.description)) producto, max(p.reference) referencia, max(ag.name) grupo, ".self::salesItemSums())
                    ->groupBy('s.id', 's.name', 'i.code')->orderBy('vendedor')->orderByRaw('cast(i.code as unsigned), i.code'),
            ],
            'movimiento-facturas-venta' => [
                'title' => 'Movimiento ventas y compras', 'filters' => ['kind', 'contact', 'seller', 'cost_center', 'range', 'creator'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Tercero'], 'kinds' => self::MOVEMENT_KINDS,
                'columns' => self::movementColumns(true),
                'totals' => ['cantidad', 'unitario', 'descuento', 'aiu', 'cargo1', 'cargo2', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'query' => fn ($f) => self::movement($f, $f['kind'] ? [$f['kind']] : array_keys(self::MOVEMENT_KINDS)),
            ],
            'movimiento-notas-venta' => [
                'title' => 'Movimiento de notas débito, crédito y nota débito (Ventas)', 'filters' => ['contact', 'seller', 'cost_center', 'range', 'creator'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Tercero'],
                'columns' => self::movementColumns(false),
                'totals' => ['cantidad', 'unitario', 'descuento', 'aiu', 'cargo1', 'cargo2', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'query' => fn ($f) => self::movement($f, ['NC', 'ND']),
            ],
            'movimiento-recibos' => [
                'title' => 'Movimiento de recibos de caja/pagos', 'filters' => ['kind', 'contact', 'cost_center', 'range', 'creator'], 'primary' => ['kind'],
                'labels' => ['kind' => 'Tipo de transacción', 'contact' => 'Tercero'], 'kinds' => ['RC' => 'Recibos de caja', 'RP' => 'Recibos de pago'],
                'columns' => ['documento' => ['Documento', 'text'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'nombre' => ['Nombre', 'text'],
                    'centro' => ['Centro de costo', 'text'], 'fecha' => ['Fecha de elaboración', 'date'], 'forma_pago' => ['Forma de pago', 'text'], 'valor' => ['Valor', 'money'],
                    'anticipos' => ['Valor anticipos', 'money']],
                'totals' => ['valor', 'anticipos'],
                'query' => fn ($f) => DB::table('receipts as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')->leftJoin('payment_methods as pm', 'pm.id', '=', 'r.payment_method_id')
                    ->where('r.company_id', $f['cid'])->whereIn('r.type', $f['kind'] ? [$f['kind']] : ['RC', 'RP'])->where('r.status', '<>', 'annulled')
                    ->whereBetween('r.date', [$f['from'], $f['to']])->when($f['contact'], fn ($q, $v) => $q->where('r.contact_id', $v))
                    ->when($f['cost_center'], fn ($q) => $q->whereRaw('1 = 0'))->when($f['creator'], fn ($q, $v) => $q->where('r.created_by', $v))
                    ->selectRaw("r.id, r.name documento, c.identification ident, ".self::branch('c')." sucursal, c.name nombre, null centro, r.date fecha,
                        coalesce(pm.name, r.payment_name) forma_pago, r.total valor, 0 anticipos, 'COP' moneda")
                    ->orderBy('r.type')->orderByRaw("substring_index(r.name, '-', 2)")->orderBy('r.number')->orderBy('r.id'),
            ],
            'listado-productos' => [
                'title' => 'Listado de productos / Servicios', 'filters' => ['product', 'kind', 'status', 'group'], 'primary' => ['product'],
                'labels' => ['kind' => 'Saldo', 'status' => 'Estado', 'group' => 'Categoría'],
                'kinds' => ['con' => 'Con saldo', 'sin' => 'Sin saldo', 'todos' => 'Todos'], 'statuses' => ['activo' => 'Activo', 'inactivo' => 'Inactivo', 'todos' => 'Todos'],
                'defaults' => ['kind' => 'con', 'status' => 'activo'], 'column_picker' => true, 'hidden' => ['precio1', 'precio2', 'precio3'],
                'columns' => ['tipo' => ['Tipo', 'text'], 'codigo' => ['Código', 'text'], 'nombre' => ['Nombre', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'categoria' => ['Categoría', 'text'], 'estado' => ['Estado', 'text'], 'impuesto1' => ['Impuesto cargo', 'text'], 'impuesto2' => ['Impuesto Cargo 2', 'text'],
                    'precio1' => ['Precio de venta 1', 'money'], 'descripcion' => ['Descripción larga', 'text'], 'incluido' => ['Es incluido', 'text'], 'inventariable' => ['Inventariable', 'text'],
                    'precio2' => ['Precio de venta 2', 'money'], 'precio3' => ['Precio de venta 3', 'money'], 'saldo' => ['Saldo cantidades', 'qty'],
                    'visible' => ['Visible en facturas de venta', 'text'], 'minimo' => ['Stock mínimo', 'qty']],
                'totals' => ['saldo'],
                'links' => ['codigo' => '/productos/{id}/editar'],
                'query' => function ($f) {
                    $price = fn (int $n) => "(select pp.value from product_prices pp join price_lists pl on pl.id = pp.price_list_id where pp.product_id = p.id and pl.position = $n limit 1)";
                    $tax = fn (int $n) => '(select t.name from product_tax pt join taxes t on t.id = pt.tax_id where pt.product_id = p.id and t.type not in '.self::WITHHOLDING_TYPES." order by t.id limit 1 offset $n)";

                    return DB::table('products as p')->leftJoin('account_groups as g', 'g.id', '=', 'p.account_group_id')->where('p.company_id', $f['cid'])
                        ->when($f['product'], fn ($q, $v) => $q->where('p.id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                        ->when($f['kind'] === 'con', fn ($q) => $q->where('p.stock', '>', 0))->when($f['kind'] === 'sin', fn ($q) => $q->where('p.stock', '<=', 0))
                        ->when($f['status'] === 'activo', fn ($q) => $q->where('p.active', true))->when($f['status'] === 'inactivo', fn ($q) => $q->where('p.active', false))
                        ->selectRaw("p.id, case p.type when 'Service' then 'Servicio' when 'Combo' then 'Combo' else 'Producto' end tipo, p.code codigo, p.name nombre, p.reference referencia,
                            g.name categoria, if(p.active, 'Activo', 'Inactivo') estado, {$tax(0)} impuesto1, {$tax(1)} impuesto2, {$price(1)} precio1, nullif(p.description, '') descripcion,
                            if(p.tax_included, 'Si', 'NO') incluido, if(p.stock_control, 'Si', 'No') inventariable, {$price(2)} precio2, {$price(3)} precio3, p.stock saldo,
                            if(p.visible_in_sales, 'SI', 'NO') visible, p.min_stock minimo")
                        ->orderBy('p.code');
                },
            ],
            'ventas-centro-costo' => [
                'title' => 'Ventas por centro de costo', 'filters' => ['cost_center', 'seller', 'contact', 'range', 'nc'], 'primary' => ['cost_center'],
                'columns' => ['codigo' => ['Código centro de costo', 'text'], 'centro' => ['Centro de costo', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::salesValueColumns('Descuento'), 'cargos' => ['Cargo en totales', 'money'], 'descuentos_totales' => ['Descuento en totales', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['bruto', 'descuento', 'subtotal', 'cargo', 'retencion', 'cargos', 'descuentos_totales', 'total'],
                'query' => fn ($f) => self::salesDocs($f)->join('cost_centers as cc', 'cc.id', '=', 'd.cost_center_id')
                    ->when($f['cost_center'], fn ($q, $v) => $q->where('d.cost_center_id', $v))
                    ->selectRaw('cc.code codigo, cc.name centro, count(*) documentos, '.self::salesDocSums())
                    ->groupBy('cc.id', 'cc.code', 'cc.name')->orderBy('cc.code'),
            ],
            'comparativo-ventas-mes' => [
                'title' => 'Comparativo de ventas por mes', 'filters' => ['year', 'tax', 'nc'],
                'columns' => ['mes' => ['Mes', 'text'], 'anterior' => ['Año anterior', 'money'], 'actual' => ['Año seleccionado', 'money'], 'variacion' => ['Variación', 'money'], 'porcentaje' => ['% Variación', 'pct']],
                'totals' => ['anterior', 'actual', 'variacion'],
                'query' => fn ($f) => DB::query()->fromSub(
                    self::sales([...$f, 'from' => ($f['year'] - 1).'-01-01', 'to' => $f['year'].'-12-31'], self::comparativeTypes($f))
                        ->selectRaw('month(d.date) m, sum(case when year(d.date) = ? then '.self::comparativeValue($f, 'd').' else 0 end) anterior, sum(case when year(d.date) = ? then '.self::comparativeValue($f, 'd').' else 0 end) actual', [$f['year'] - 1, $f['year']])
                        ->groupByRaw('month(d.date)'), 't')
                    ->selectRaw("elt(m, 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre') mes, anterior, actual, actual - anterior variacion, case when anterior <> 0 then (actual - anterior) / anterior * 100 end porcentaje")
                    ->orderBy('m'),
            ],
            'comparativo-ventas-producto' => [
                'title' => 'Comparativo de ventas por producto', 'filters' => ['year', 'product', 'tax', 'nc'],
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Producto', 'text'], ...self::monthColumns()],
                'totals' => [...array_keys(self::monthColumns())],
                'query' => fn ($f) => self::salesItems([...$f, 'from' => $f['year'].'-01-01', 'to' => $f['year'].'-12-31'], self::comparativeTypes($f))
                    ->selectRaw('i.code codigo, max(i.description) producto, '.self::monthSums(self::comparativeValue($f, 'i')))
                    ->groupBy('i.code')->orderByDesc('total'),
            ],
            'comparativo-ventas-vendedor' => [
                'title' => 'Comparativo de ventas por vendedor', 'filters' => ['year', 'seller', 'tax', 'nc'],
                'columns' => ['vendedor' => ['Vendedor', 'text'], ...self::monthColumns()],
                'totals' => [...array_keys(self::monthColumns())],
                'query' => fn ($f) => self::sales([...$f, 'from' => $f['year'].'-01-01', 'to' => $f['year'].'-12-31'], self::comparativeTypes($f))
                    ->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                    ->selectRaw("coalesce(s.name, 'Sin vendedor') vendedor, ".self::monthSums(self::comparativeValue($f, 'd')))
                    ->groupBy('s.name')->orderByDesc('total'),
            ],
            'facturacion-obsequios' => [
                'title' => 'Informe facturación de obsequios', 'filters' => ['range', 'contact', 'product'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'cantidad' => ['Cantidad', 'qty'],
                    'base' => ['Valor base IVA', 'money'], 'porcentaje' => ['Porcentaje IVA', 'pct'], 'iva' => ['Valor IVA obsequio', 'money'], 'asumido' => ['IVA asumido por', 'text'],
                    'ident' => ['Identificación', 'text'], 'cliente' => ['Nombre tercero', 'text'], 'sucursal' => ['Sucursal', 'text']],
                'totals' => ['cantidad', 'base', 'iva'],
                'query' => fn ($f) => self::salesItems($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.type', 'FV')
                    ->whereRaw('(i.price = 0 or i.discount >= i.quantity * i.price)')
                    ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                    ->selectRaw("d.name documento, i.code codigo, coalesce(p.name, i.description) producto, i.quantity cantidad, i.quantity * coalesce(p.average_cost, 0) base,
                        coalesce(i.tax_percentage, 0) porcentaje, i.quantity * coalesce(p.average_cost, 0) * coalesce(i.tax_percentage, 0) / 100 iva, 'Empresa' asumido,
                        ".self::ident('c').' ident, c.name cliente, '.self::branch('c').' sucursal')
                    ->orderBy('d.date')->orderBy('d.number'),
            ],
            'cotizaciones-vendedor' => [
                'title' => 'Cotizaciones por vendedor', 'filters' => ['seller', 'range'], 'primary' => ['seller'], 'labels' => ['seller' => 'Responsable'],
                'columns' => ['vendedor' => ['Responsable', 'text'], 'descartadas' => ['Cotizaciones descartadas', 'money'], 'aprobadas' => ['Cotizaciones aprobadas', 'money'],
                    'facturadas' => ['Cotizaciones facturadas', 'money'], 'sin_aprobar' => ['Cotizaciones sin aprobar', 'money']],
                'totals' => ['descartadas', 'aprobadas', 'facturadas', 'sin_aprobar'],
                'query' => function ($f) {
                    $billed = "exists (select 1 from sales_documents x where x.related_document_id = d.id and x.type = 'FV' and x.status <> 'annulled')";

                    return DB::table('sales_documents as d')->where('d.company_id', $f['cid'])->where('d.type', 'CT')->whereBetween('d.date', [$f['from'], $f['to']])
                        ->when($f['seller'], fn ($q, $v) => $q->where('d.seller_id', $v))->leftJoin('sellers as s', 's.id', '=', 'd.seller_id')
                        ->selectRaw("coalesce(s.name, 'Sin responsable') vendedor,
                            sum(case when d.status = 'annulled' then d.total else 0 end) descartadas,
                            sum(case when d.status = 'approved' and not $billed then d.total else 0 end) aprobadas,
                            sum(case when d.status <> 'annulled' and $billed then d.total else 0 end) facturadas,
                            sum(case when d.status not in ('annulled', 'approved') and not $billed then d.total else 0 end) sin_aprobar")
                        ->groupBy('s.id', 's.name')->orderBy('vendedor');
                },
            ],
            'facturacion-electronica' => [
                'title' => 'Informes facturación electrónica', 'filters' => ['range', 'contact', 'dian'],
                'columns' => ['documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'cliente' => ['Cliente', 'text'], 'total' => ['Total', 'money'],
                    'estado' => ['Estado DIAN', 'text'], 'correo' => ['Estado correo', 'text'], 'cufe' => ['CUFE', 'text']],
                'totals' => ['total'],
                'query' => fn ($f) => self::sales($f, ['FV', 'NC', 'ND'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->when($f['dian'], fn ($q, $v) => $q->where('d.dian_status', $v))
                    ->selectRaw("d.name documento, d.date fecha, c.name cliente, d.total * ".self::SIGN." total, case d.dian_status when 'Accepted' then 'Aceptado' when 'Rejected' then 'Rechazado' when 'Pending' then 'Pendiente' else coalesce(d.dian_status, 'Sin enviar') end estado, coalesce(d.mail_status, '-') correo, d.cufe")
                    ->orderByDesc('d.date')->orderByDesc('d.number'),
            ],
            'recibos-caja-facturas' => [
                'title' => 'Recibos de caja detallado por facturas', 'filters' => ['contact', 'payment', 'creator', 'range'], 'primary' => ['contact'],
                'columns' => ['recibo' => ['Comprobante', 'text'], 'fecha' => ['Fecha elaboración', 'date'], 'creador' => ['Creador', 'text'], 'ident' => ['Identificación', 'text'],
                    'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Cliente', 'text'], 'cuenta' => ['Cuenta contable', 'text'], 'vence' => ['Vencimiento', 'text'],
                    'valor' => ['Valor', 'money'], 'forma' => ['Forma de pago', 'text']],
                'totals' => ['valor'],
                'links' => ['recibo' => '/documentos/recibos/{receipt_id}', 'cliente' => '/terceros/{contact_id}'],
                'query' => fn ($f) => self::receipts($f, 'RC')
                    ->leftJoin('sales_documents as sd', 'sd.id', '=', 'ri.sales_document_id')->leftJoin('users as u', 'u.id', '=', 'r.created_by')
                    ->leftJoin('accounts as a', 'a.id', '=', 'ri.account_id')
                    ->when($f['payment'], fn ($q, $v) => $q->where('r.payment_method_id', $v))->when($f['creator'], fn ($q, $v) => $q->where('r.created_by', $v))
                    ->selectRaw("r.id receipt_id, c.id contact_id, r.name recibo, r.date fecha, u.name creador, c.identification ident, ".self::branch('c')." sucursal, c.name cliente,
                        coalesce(a.code, (select g.account_code from ledger_lines g where g.company_id = r.company_id and g.document = r.name and g.credit > 0 and g.account_code like '13%' limit 1)) cuenta,
                        coalesce(nullif(concat_ws('-', ri.due_prefix, ri.due_number, ri.due_quote), ''), sd.name, ri.description) vence, ri.value valor, r.payment_name forma")
                    ->orderBy('r.date')->orderBy('r.number')->orderBy('ri.id'),
            ],
            'seguimiento-correos' => [
                'title' => 'Seguimiento envío masivo de correos electrónicos', 'filters' => ['range'], 'labels' => ['range' => 'Fecha proceso'],
                'note' => 'Cada envío es la corrida diaria de recordatorios de pago de la cobranza automática.',
                'columns' => ['tipo' => ['Tipo proceso', 'text'], 'fecha' => ['Fecha proceso', 'date'], 'numero' => ['Número envío', 'int'], 'correos' => ['Correos enviados', 'count'],
                    'facturas' => ['Facturas', 'text']],
                'totals' => ['correos'],
                'query' => fn ($f) => DB::query()->fromSub(DB::table('collection_notices as n')->join('sales_documents as d', 'd.id', '=', 'n.sales_document_id')
                    ->where('n.company_id', $f['cid'])->where('n.kind', 'recordatorio')->groupBy('n.sent_on')
                    ->selectRaw("'Recordatorios de pago' tipo, n.sent_on fecha, dense_rank() over (order by n.sent_on) numero, count(*) correos,
                        group_concat(d.name order by d.name separator ', ') facturas"), 't')
                    ->whereBetween('t.fecha', [$f['from'], $f['to']])->orderByDesc('t.fecha'),
            ],

            // ---------------- Cartera ----------------
            'cartera-detallada' => [
                'title' => 'Cuentas por cobrar/pagar detallada por documento', 'filters' => ['q', 'module'], 'primary' => ['q'],
                'labels' => ['q' => 'Buscar por identificación o nombre', 'module' => 'Tipo'], 'q_placeholder' => 'Buscar', 'column_picker' => true, 'narrow' => ['nombre'],
                'module_labels' => ['ventas' => 'Saldos por cobrar (Clientes)', 'compras' => 'Saldos por pagar (Proveedores)'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'sucursal' => ['Sucursal', 'text'], 'documento' => ['Documento', 'text'],
                    'vence' => ['Fecha vencimiento', 'date'], 'centro' => ['Centro de costo', 'text'],
                    'd30' => ['Vencido 1 a 30', 'money'], 'd60' => ['Vencido 31 a 60', 'money'], 'd90' => ['Vencido 61 a 90', 'money'], 'mas90' => ['Vencido más de 91', 'money'],
                    'por_vencer' => ['Saldo por vencer', 'money'], 'favor' => ['Saldo a favor', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['d30', 'd60', 'd90', 'mas90', 'por_vencer', 'favor', 'total'],
                'links' => ['nombre' => '@contact_url', 'documento' => '@url'],
                'query' => fn ($f) => self::agingByDocument($f),
            ],
            'cxc-general' => [
                'title' => 'Cuentas por cobrar/pagar general por cliente/proveedor', 'filters' => ['contact', 'module'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Cliente', 'module' => 'Tipo'], 'column_picker' => true, 'narrow' => ['nombre'],
                'module_labels' => ['ventas' => 'Saldos por cobrar (Clientes)', 'compras' => 'Saldos por pagar (Proveedores)'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'sucursal' => ['Sucursal', 'text'],
                    'd30' => ['Vencido 1 a 30', 'money'], 'd60' => ['Vencido 31 a 60', 'money'], 'd90' => ['Vencido 61 a 90', 'money'], 'mas90' => ['Vencido más de 91', 'money'],
                    'por_vencer' => ['Saldo por vencer', 'money'], 'favor' => ['Saldo a favor', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['d30', 'd60', 'd90', 'mas90', 'por_vencer', 'favor', 'total'],
                'links' => ['nombre' => '@url'],
                'query' => fn ($f) => self::agingByContact($f),
            ],
            'cartera-movimiento' => [
                'title' => 'Movimiento de cuentas por cobrar/pagar por documento', 'filters' => ['q', 'module', 'contact'], 'primary' => ['q'],
                'labels' => ['q' => 'Vencimiento(Documento)', 'module' => 'Tipo', 'contact' => 'Tercero'], 'q_placeholder' => 'Comprobante + Consecutivo', 'column_picker' => true,
                'module_labels' => ['ventas' => 'Saldos por cobrar (Clientes)', 'compras' => 'Saldos por pagar (Proveedores)'],
                'note' => 'Sin un documento en el filtro se muestran los documentos que todavía tienen saldo.',
                'columns' => ['documento' => ['Documento', 'text'], 'secuencia' => ['Secuencia', 'int'], 'fecha' => ['Fecha', 'date'], 'detalle' => ['Detalle', 'text'],
                    'cuenta' => ['Código de cuenta', 'text'], 'debito' => ['Débito', 'money'], 'credito' => ['Crédito', 'money']],
                'totals' => ['debito', 'credito'],
                'links' => ['documento' => '@url'],
                'query' => fn ($f) => self::dueMovements($f),
            ],
            'cxc-corte' => [
                'title' => 'Cuentas por cobrar con corte a una fecha', 'filters' => ['contact', 'cutoff'], 'primary' => ['contact', 'cutoff'],
                'labels' => ['contact' => 'Buscar por cliente'], 'defaults' => ['cutoff' => now()->subDay()->toDateString()], 'banner' => true,
                'note' => 'Los datos presentados corresponden exclusivamente al estado histórico de la cartera al corte seleccionado. Para visualizar saldos actuales o creados recientemente, utiliza el reporte de estado de cuenta.',
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'cliente' => ['Nombre cliente', 'text'],
                    'deuda' => ['Deuda por cobrar', 'money'], 'favor' => ['Saldo a favor', 'money'], 'saldo' => ['Saldo cartera', 'money']],
                'totals' => ['deuda', 'favor', 'saldo'],
                'links' => ['cliente' => '@url'],
                'query' => fn ($f) => DB::query()->fromSub(
                    DB::table('sales_documents as d')->where('d.company_id', $f['cid'])->whereIn('d.type', ['FV', 'ND', 'SI', 'NC'])
                        ->where('d.status', '<>', 'annulled')->where('d.date', '<=', $f['cutoff'])->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
                        ->leftJoinSub(DB::table('receipt_items as ri')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')->where('r.company_id', $f['cid'])
                            ->where('r.date', '>', $f['cutoff'])->where('r.status', '<>', 'annulled')->whereNotNull('ri.sales_document_id')
                            ->groupBy('ri.sales_document_id')->selectRaw('ri.sales_document_id id, sum(ri.value) v'), 'ap', 'ap.id', '=', 'd.id')
                        ->leftJoinSub(DB::table('sales_documents as n')->where('n.company_id', $f['cid'])->where('n.type', 'NC')->where('n.date', '>', $f['cutoff'])
                            ->where('n.status', '<>', 'annulled')->whereNotNull('n.related_document_id')
                            ->groupBy('n.related_document_id')->selectRaw('n.related_document_id id, sum(n.total) v'), 'an', 'an.id', '=', 'd.id')
                        ->where(fn ($w) => $w->where('d.balance', '<>', 0)->orWhereNotNull('ap.id')->orWhereNotNull('an.id'))
                        ->selectRaw('d.contact_id, d.balance + coalesce(ap.v, 0) + coalesce(an.v, 0) saldo'), 't')
                    ->join('contacts as c', 'c.id', '=', 't.contact_id')
                    ->selectRaw("c.identification ident, ".self::branch('c')." sucursal, c.name cliente, round(sum(greatest(t.saldo, 0)), 2) deuda,
                        round(sum(greatest(-t.saldo, 0)), 2) favor, round(sum(t.saldo), 2) saldo, concat('/ventas/clientes/', c.id) url")
                    ->groupBy('c.id', 'c.identification', 'c.branch_office', 'c.name')->havingRaw('abs(sum(t.saldo)) > 0.009 or sum(greatest(-t.saldo, 0)) > 0.009')
                    ->orderByRaw('cast(c.identification as unsigned), c.identification'),
            ],
            'busqueda-terceros' => [
                'title' => 'Búsqueda de cliente, proveedor u otro', 'filters' => ['contact', 'status'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Tercero'], 'statuses' => ['activo' => 'Activo', 'inactivo' => 'Inactivo'], 'column_picker' => true,
                'columns' => ['nombre' => ['Nombre tercero', 'text'], 'tipo_id' => ['Tipo de identificación', 'text'], 'ident' => ['Identificación', 'text'],
                    'dv' => ['Digito verificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'regimen' => ['Tipo de regimen IVA', 'text'], 'direccion' => ['Dirección', 'text'],
                    'ciudad' => ['Ciudad', 'text'], 'telefono' => ['Teléfono.', 'text'], 'contacto' => ['Nombres contacto', 'text'], 'estado' => ['Estado', 'text']],
                'totals' => [],
                'links' => ['nombre' => '@url'],
                'query' => fn ($f) => DB::table('contacts as c')->leftJoin('municipalities as m', 'm.code', '=', 'c.municipality_code')->where('c.company_id', $f['cid'])
                    ->when($f['contact'], fn ($q, $v) => $q->where('c.id', $v))
                    ->when($f['status'], fn ($q, $v) => $q->where('c.active', $v === 'activo'))
                    ->selectRaw("c.name nombre, ".self::ID_TYPE_SQL." tipo_id, c.identification ident, coalesce(c.dv, '-') dv, ".self::branch('c')." sucursal,
                        if(c.vat_responsible, 'Responsable de IVA', 'No responsable de IVA') regimen, coalesce(nullif(c.address, ''), '-') direccion, coalesce(m.name, '-') ciudad,
                        concat(coalesce(c.phone_indicative, ''), '-', coalesce(c.phone, ''), '-', coalesce(c.phone_extension, '')) telefono,
                        coalesce((select trim(concat(p.first_name, ' ', coalesce(p.last_name, ''))) from contact_people p where p.contact_id = c.id order by p.id limit 1), '-') contacto,
                        if(c.active, 'Activo', 'Inactivo') estado, concat(if(c.is_customer or not c.is_supplier, '/ventas/clientes/', '/compras/proveedores/'), c.id) url")
                    ->orderBy('c.name'),
            ],
            'busqueda-contactos' => [
                'title' => 'Búsqueda avanzada contacto de cliente, proveedor u otro', 'filters' => ['contact', 'status'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Cliente proveedor u otro', 'status' => 'Contacto principal'], 'statuses' => ['si' => 'Si', 'no' => 'No'], 'column_picker' => true,
                'columns' => ['ident' => ['Número de identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'tercero' => ['Nombre cliente proveedor u otro', 'text'],
                    'contacto' => ['Nombre de contacto', 'text'], 'telefono' => ['Teléfono de contacto', 'text'], 'email' => ['Correo de contacto', 'text'], 'principal' => ['Contacto principal', 'text']],
                'totals' => [],
                'query' => fn ($f) => DB::query()->fromSub(DB::table('contact_people as p')->join('contacts as c', 'c.id', '=', 'p.contact_id')->where('c.company_id', $f['cid'])
                    ->when($f['contact'], fn ($q, $v) => $q->where('c.id', $v))
                    ->selectRaw("c.identification ident, ".self::branch('c')." sucursal, c.name tercero, trim(concat(p.first_name, ' ', coalesce(p.last_name, ''))) contacto,
                        concat('(', lpad(coalesce(nullif(p.phone_indicative, ''), '0'), 3, '0'), ') ', coalesce(nullif(p.phone, ''), '0000000')) telefono, coalesce(p.email, '-') email,
                        if(p.id = (select min(x.id) from contact_people x where x.contact_id = c.id), 'Si', 'No') principal"), 't')
                    ->when($f['status'], fn ($q, $v) => $q->where('principal', $v === 'si' ? 'Si' : 'No'))
                    ->orderByRaw("trim(trim(leading '.' from trim(contacto))), tercero"),
            ],

            // ---------------- Compras ----------------
            'compras-proveedor' => [
                'title' => 'Compras por proveedor', 'filters' => ['range', 'contact', 'nc'], 'primary' => ['contact'],
                'labels' => ['contact' => 'Proveedor', 'nc' => 'Incluye nota débito'],
                'columns' => ['ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'proveedor' => ['Proveedor', 'text'], 'documentos' => ['Número de comprobantes', 'count'],
                    ...self::purchaseValueColumns()],
                'totals' => ['bruto', 'descuento', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchases($f, self::purchaseTypes($f))->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw('c.identification ident, '.self::branch('c').' sucursal, c.name proveedor, count(*) documentos, '.self::purchaseDocSums())
                    ->groupBy('c.id', 'c.identification', 'c.branch_office', 'c.name')->orderByRaw('cast(c.identification as unsigned), c.identification'),
            ],
            'compras-producto' => [
                'title' => 'Compras por producto', 'filters' => ['range', 'product', 'group', 'nc'], 'primary' => ['product'],
                'labels' => ['nc' => 'Incluye nota crédito'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'grupo' => ['Grupo inventario', 'text'], 'factura' => ['Factura proveedor', 'text'], 'cantidad' => ['Cantidad', 'qty'], ...self::purchaseValueColumns()],
                'totals' => ['cantidad', 'bruto', 'descuento', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchaseProductLines($f)
                    ->selectRaw("p.code codigo, p.name producto, p.reference referencia, g.name grupo, nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '') factura, ".self::purchaseItemSums())
                    ->groupBy('p.id', 'p.code', 'p.name', 'p.reference', 'g.name', 'd.supplier_prefix', 'd.supplier_number')->orderByRaw('cast(p.code as unsigned), p.code, min(d.date), min(d.id)'),
            ],
            'compras-producto-proveedor' => [
                'title' => 'Compras por producto por proveedor', 'filters' => ['range', 'product', 'group', 'contact', 'nc'], 'primary' => ['product'],
                'labels' => ['contact' => 'Proveedor', 'nc' => 'Incluye nota débito'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'grupo' => ['Grupo inventario', 'text'],
                    'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'proveedor' => ['Proveedor', 'text'], 'factura' => ['Factura proveedor', 'text'],
                    'cantidad' => ['Cantidad', 'qty'], 'unitario' => ['Valor unitario', 'money'], ...self::purchaseValueColumns()],
                'totals' => ['cantidad', 'unitario', 'bruto', 'descuento', 'subtotal', 'impuestos', 'retenciones', 'total'],
                'query' => fn ($f) => self::purchaseProductLines($f)->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("p.code codigo, p.name producto, g.name grupo, c.identification ident, ".self::branch('c')." sucursal, c.name proveedor,
                        nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '') factura, i.price unitario, ".self::purchaseItemSums())
                    ->groupBy('p.id', 'p.code', 'p.name', 'g.name', 'c.id', 'c.identification', 'c.branch_office', 'c.name', 'd.supplier_prefix', 'd.supplier_number', 'i.price')
                    ->orderByRaw('cast(p.code as unsigned), p.code, cast(c.identification as unsigned), factura'),
            ],
            'cruce-orden-compra' => [
                'title' => 'Cruce orden de compra vs factura de compra', 'filters' => ['range', 'contact'], 'primary' => ['contact'], 'labels' => ['contact' => 'Proveedor'],
                'columns' => ['proveedor' => ['Proveedor', 'text'], 'orden' => ['Orden compra', 'text'], 'tipo' => ['Tipo', 'text'], 'item' => ['Ítem', 'text'], 'descripcion' => ['Descripción', 'text'],
                    'solicitada' => ['Cantidad solicitada', 'qty'], 'recibida' => ['Cantidad recibida', 'qty'], 'pendiente' => ['Cantidad pendiente', 'qty'], 'facturas' => ['Facturas compras', 'text']],
                'totals' => ['solicitada', 'recibida', 'pendiente'],
                'query' => fn ($f) => DB::query()->fromSub(self::purchases($f, ['OC'])->join('contacts as c', 'c.id', '=', 'd.contact_id')->join('purchase_document_items as i', 'i.purchase_document_id', '=', 'd.id')
                    ->selectRaw("c.name proveedor, d.name orden, 'Producto' tipo, coalesce(i.code, '-') item, i.description descripcion, i.quantity solicitada,
                        least(i.quantity, (select coalesce(sum(fi.quantity), 0) from purchase_documents v join purchase_document_items fi on fi.purchase_document_id = v.id
                            where v.related_document_id = d.id and v.status <> 'annulled' and fi.product_id <=> i.product_id)) recibida,
                        (select group_concat(v.name) from purchase_documents v where v.related_document_id = d.id and v.status <> 'annulled') facturas, d.date fecha, d.id oid"), 't')
                    ->selectRaw('t.*, solicitada - recibida pendiente')->orderBy('fecha')->orderBy('oid'),
            ],
            'movimiento-notas-debito' => [
                'title' => 'Movimiento notas débito', 'filters' => ['range', 'contact'],
                'columns' => ['modulo' => ['Módulo', 'text'], 'documento' => ['Documento', 'text'], 'fecha' => ['Fecha', 'date'], 'tercero' => ['Tercero', 'text'], 'subtotal' => ['Subtotal', 'money'], 'impuestos' => ['Impuestos', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['subtotal', 'impuestos', 'total'],
                'query' => fn ($f) => self::sales($f, ['ND'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                    ->selectRaw("'Ventas' modulo, d.name documento, d.date fecha, c.name tercero, d.subtotal, d.tax_total impuestos, d.total")
                    ->unionAll(self::purchases($f, ['ND', 'NA'])->join('contacts as c', 'c.id', '=', 'd.contact_id')
                        ->selectRaw("'Compras' modulo, d.name documento, d.date fecha, c.name tercero, d.subtotal, d.tax_total impuestos, d.total")),
            ],
            'libro-oficial-compras' => [
                'title' => 'Libro oficial de compras', 'filters' => ['range', 'contact'], 'labels' => ['contact' => 'Proveedor'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'fecha' => ['Fecha elaboración', 'date'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'],
                    'tercero' => ['Nombre tercero', 'text'], 'factura' => ['Factura proveedor', 'text'], 'gravada' => ['Base gravada', 'money'], 'exenta' => ['Base exenta', 'money'],
                    'iva' => ['IVA', 'money'], 'total' => ['Total', 'money']],
                'totals' => ['gravada', 'exenta', 'iva', 'total'],
                'query' => fn ($f) => self::officialBook($f, true),
            ],

            // ---------------- Ventas oficiales e impuestos ----------------
            'libro-oficial-ventas' => [
                'title' => 'Libro oficial de ventas', 'filters' => ['range', 'contact'], 'labels' => ['contact' => 'Cliente'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'fecha' => ['Fecha elaboración', 'date'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'],
                    'tercero' => ['Nombre tercero', 'text'], 'aiu' => ['Base AIU', 'money'], 'gravada' => ['Base gravada', 'money'], 'exenta' => ['Base exenta', 'money'], 'iva' => ['IVA', 'money'],
                    'impoconsumo' => ['Impoconsumo', 'money'], 'advalorem' => ['AD-Valorem', 'money'], 'cargos' => ['Cargo en totales', 'money'], 'descuentos' => ['Descuento en totales', 'money'],
                    'total' => ['Total', 'money']],
                'totals' => ['aiu', 'gravada', 'exenta', 'iva', 'impoconsumo', 'advalorem', 'cargos', 'descuentos', 'total'],
                'query' => fn ($f) => self::officialBook($f, false),
            ],
            'impuestos-detallados' => [
                'title' => 'Impuestos detallados', 'filters' => ['range', 'contact'], 'labels' => ['contact' => 'Tercero'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'tercero' => ['Nombre tercero', 'text'],
                    'fecha' => ['Fecha elaboración', 'date'], 'base_v' => ['Base ventas', 'money'], 'imp_v' => ['Valor impuesto ventas', 'money'], 'base_c' => ['Base compras', 'money'],
                    'imp_c' => ['Valor impuesto compras', 'money'], 'base_dv' => ['Base devolución ventas', 'money'], 'imp_dv' => ['Valor impuesto devolución ventas', 'money'],
                    'base_dc' => ['Base devolución compras', 'money'], 'imp_dc' => ['Valor impuesto devolución compras', 'money']],
                'totals' => ['base_v', 'imp_v', 'base_c', 'imp_c', 'base_dv', 'imp_dv', 'base_dc', 'imp_dc'],
                'query' => function ($f) {
                    $side = fn ($tbl, $types, $col) => DB::table("$tbl as d")->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])
                        ->whereIn('d.type', $types)->where('d.status', '<>', 'annulled')->whereBetween('d.date', [$f['from'], $f['to']])->where('d.tax_total', '<>', 0)
                        ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v));
                    $cols = fn ($k) => implode(', ', array_map(fn ($c) => ($c === $k ? 'd.subtotal' : '0')." base_$c, ".($c === $k ? 'd.tax_total' : '0')." imp_$c", ['v', 'c', 'dv', 'dc']));
                    $pick = "d.name documento, c.identification ident, ".self::branch('c')." sucursal, c.name tercero, d.date fecha";

                    return DB::query()->fromSub($side('sales_documents', ['FV', 'ND'], 'v')->selectRaw("$pick, {$cols('v')}")
                        ->unionAll($side('purchase_documents', ['FC', 'DS', 'ND'], 'c')->selectRaw("$pick, {$cols('c')}"))
                        ->unionAll($side('sales_documents', ['NC'], 'dv')->selectRaw("$pick, {$cols('dv')}"))
                        ->unionAll($side('purchase_documents', ['NA'], 'dc')->selectRaw("$pick, {$cols('dc')}")), 't')->orderBy('fecha')->orderBy('documento');
                },
            ],

            // ---------------- Inventario ----------------
            'costeo-mensual' => [
                'title' => 'Informe costeo mensual', 'filters' => ['group', 'cutoff', 'product'], 'primary' => ['group'],
                'labels' => ['cutoff' => 'Fecha Corte', 'group' => 'Categoría'], 'links' => ['producto' => '/productos/{id}/editar'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'promedio' => ['Costo promedio', 'cost'], 'salidas' => ['Salida del inventario', 'qty'], 'costo' => ['Costos totales', 'money']],
                'totals' => ['promedio', 'salidas', 'costo'],
                'query' => function ($f) {
                    [$y, $m] = [(int) substr($f['cutoff'], 0, 4), (int) substr($f['cutoff'], 5, 2)];
                    $snapshot = DB::table('product_monthly_costs')->where('company_id', $f['cid'])->where('year', $y)->where('month', $m)->exists();
                    if ($snapshot) {
                        return DB::table('product_monthly_costs as k')->leftJoin('products as p', 'p.id', '=', 'k.product_id')
                            ->where('k.company_id', $f['cid'])->where('k.year', $y)->where('k.month', $m)
                            ->when($f['product'], fn ($q, $v) => $q->where('k.product_id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                            ->selectRaw('p.id, k.code codigo, p.name producto, p.reference referencia, k.average_cost promedio, k.quantity_out salidas, k.total_cost costo')
                            ->orderByRaw('cast(k.code as unsigned), k.code');
                    }
                    $start = sprintf('%04d-%02d-01', $y, $m);

                    return DB::table('sales_document_items as i')->join('sales_documents as d', 'd.id', '=', 'i.sales_document_id')->join('products as p', 'p.id', '=', 'i.product_id')
                        ->where('d.company_id', $f['cid'])->whereIn('d.type', ['FV', 'ND'])->where('d.status', '<>', 'annulled')->where('p.stock_control', true)
                        ->whereBetween('d.date', [$start, date('Y-m-t', strtotime($start))])
                        ->when($f['product'], fn ($q, $v) => $q->where('p.id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                        ->selectRaw('p.id, p.code codigo, p.name producto, p.reference referencia, p.average_cost promedio, sum(i.quantity) salidas, sum(i.quantity) * p.average_cost costo')
                        ->groupBy('p.id', 'p.code', 'p.name', 'p.reference', 'p.average_cost')->orderByRaw('cast(p.code as unsigned), p.code');
                },
            ],
            'rentabilidad-producto' => [
                'title' => 'Rentabilidad por producto', 'filters' => ['product', 'range', 'cost_center', 'group', 'nc'], 'primary' => ['product'],
                'labels' => ['range' => 'Año fiscal', 'group' => 'Categoría', 'nc' => 'Incluye notas crédito y débito'], 'column_picker' => true, 'defaults' => ['nc' => false],
                'note' => 'Costo de cada venta: el costo promedio del mes cuando ya se ejecutó el costeo mensual y, si no, el costo promedio actual del producto.',
                'columns' => ['tipo' => ['Tipo', 'text'], 'codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'categoria' => ['Categoría', 'text'], 'cantidad' => ['Cantidad vendida', 'qty'], 'ventas' => ['Ventas brutas totales', 'money'], 'costo' => ['Costos totales', 'money'],
                    'utilidad' => ['Utilidad $', 'money'], 'utilidad_pct' => ['Utilidad %', 'pct'], 'rentabilidad' => ['Rentabilidad %', 'pct']],
                'totals' => ['ventas', 'costo'],
                'query' => fn ($f) => DB::query()->fromSub(self::salesItems($f, $f['nc'] ? ['FV', 'ND', 'NC'] : ['FV', 'ND'])->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('account_groups as g', 'g.id', '=', 'p.account_group_id')
                    ->leftJoin('product_monthly_costs as mc', fn ($j) => $j->on('mc.product_id', '=', 'i.product_id')->whereRaw('mc.year = year(d.date) and mc.month = month(d.date)'))
                    ->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))->when($f['cost_center'], fn ($q, $v) => $q->where('d.cost_center_id', $v))
                    ->selectRaw("'Producto' tipo, i.code codigo, max(coalesce(p.name, i.description)) producto, coalesce(max(p.reference), '-') referencia, max(g.name) categoria,
                        sum(i.quantity * ".self::SIGN.') cantidad, round(sum((i.subtotal + i.discount) * '.self::SIGN.'), 2) ventas,
                        round(sum(i.quantity * case when d.source = \'erp\' and i.unit_cost > 0 then i.unit_cost else coalesce(mc.average_cost, p.average_cost, 0) end * '.self::SIGN.'), 2) costo')
                    ->groupBy('i.code'), 't')
                    ->selectRaw('t.*, ventas - costo utilidad, case when costo <> 0 then (ventas - costo) / costo * 100 end utilidad_pct, case when ventas <> 0 then (ventas - costo) / ventas * 100 end rentabilidad')
                    ->orderBy('codigo'),
            ],
            'valoracion-inventarios' => [
                'title' => 'Valoración de inventarios', 'filters' => ['product', 'group', 'period'], 'primary' => ['product', 'group', 'period'],
                'labels' => ['group' => 'Categoría', 'period' => 'Fecha'], 'note' => 'En el reporte solo se permiten visualizar productos inventariables',
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia fábrica', 'text'],
                    'categoria' => ['Categoría', 'text'], 'existencia' => ['Saldo cantidades', 'qty'], 'valor' => ['Valor total', 'money']],
                'totals' => ['valor'],
                'query' => fn ($f) => self::valuation($f),
            ],
            'saldos-productos' => [
                'title' => 'Saldos de productos', 'filters' => ['product', 'group'], 'primary' => ['product'], 'labels' => ['group' => 'Categoría'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia de fábrica', 'text'], 'existencia' => ['Saldo cantidades', 'qty']],
                'totals' => ['existencia'],
                'query' => fn ($f) => self::stockProducts($f)->where('p.stock', '<>', 0)
                    ->selectRaw("p.code codigo, p.name producto, coalesce(p.reference, '-') referencia, p.stock existencia")->orderBy('p.code'),
            ],
            'saldos-inventario' => [
                'title' => 'Saldos de inventario', 'filters' => ['q', 'group', 'kind'], 'primary' => ['q', 'group', 'kind'],
                'labels' => ['q' => 'Buscar por producto', 'group' => 'Categoría de inventarios', 'kind' => 'Saldos'], 'q_placeholder' => 'Buscar por producto',
                'kinds' => ['todos' => 'Todos los saldos', 'con' => 'Con saldos', 'sin' => 'Sin saldos', 'negativos' => 'Saldos negativos'], 'defaults' => ['kind' => 'con'],
                'columns' => ['codigo' => ['Código', 'text'], 'producto' => ['Nombre', 'text'], 'referencia' => ['Referencia de fábrica', 'text'], 'unidad' => ['Unidad de medida', 'text'],
                    'categoria' => ['Categoría de inventarios', 'text'], 'existencia' => ['Saldo total', 'qty'], 'unitario' => ['Valor unitario aproximado', 'money'], 'valor' => ['Valor total aproximado', 'money']],
                'totals' => ['existencia', 'valor'],
                'query' => fn ($f) => self::stockProducts($f)
                    ->when($f['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('p.name', 'like', "%$v%")->orWhere('p.code', 'like', "$v%")))
                    ->when($f['kind'] === 'con', fn ($q) => $q->where('p.stock', '>', 0))->when($f['kind'] === 'sin', fn ($q) => $q->where('p.stock', 0))
                    ->when($f['kind'] === 'negativos', fn ($q) => $q->where('p.stock', '<', 0))
                    ->selectRaw("p.code codigo, p.name producto, coalesce(p.reference, '-') referencia, p.unit_label unidad, g.name categoria, p.stock existencia,
                        p.average_cost unitario, round(p.stock * p.average_cost, 2) valor")->orderBy('p.id'),
            ],
            'movimiento-productos' => [
                'title' => 'Movimiento de productos', 'filters' => ['range', 'product', 'group'], 'primary' => ['product'], 'labels' => ['group' => 'Categoría'],
                'columns' => ['codigo' => ['Código producto', 'text'], 'producto' => ['Nombre producto', 'text'], 'referencia' => ['Referencia de fabrica', 'text'],
                    'comprobante' => ['Comprobante', 'text'], 'secuencia' => ['Secuencia', 'int'], 'fecha' => ['Fecha elaboración', 'date'],
                    'cant_inicial' => ['Cantidad inicial', 'qty'], 'cant_entrada' => ['Cantidad entrada', 'qty'], 'cant_salida' => ['Cantidad salida', 'qty'], 'cant_saldo' => ['Saldo cantidades', 'qty'],
                    'val_inicial' => ['Valor inicial', 'money'], 'val_entrada' => ['Valor entrada', 'money'], 'val_salida' => ['Valor salida', 'money'], 'val_saldo' => ['Saldo valor', 'money']],
                'totals' => ['cant_entrada', 'cant_salida', 'val_entrada', 'val_salida'],
                'query' => fn ($f) => self::kardexLines($f)
                    ->selectRaw("p.code codigo, p.name producto, coalesce(p.reference, '-') referencia")
                    ->orderBy('p.code')->orderBy('m.date')->orderBy('m.id'),
            ],
            'kardex' => [
                'title' => 'Movimiento de productos / Kárdex', 'filters' => ['product', 'range'], 'primary' => ['product'], 'labels' => ['product' => 'Producto'],
                'columns' => ['fecha' => ['Fecha', 'date'], 'comprobante' => ['Documento', 'text'], 'cant_entrada' => ['Cant. entrada', 'qty'], 'costo_entrada' => ['Costo unitario entrada', 'money'],
                    'val_entrada' => ['Valor entrada', 'money'], 'cant_salida' => ['Cant. salida', 'qty'], 'costo_salida' => ['Costo unitario salida', 'money'], 'val_salida' => ['Valor salida', 'money'],
                    'cant_saldo' => ['Saldo unidades', 'qty'], 'val_saldo' => ['Saldo valores', 'money']],
                'totals' => ['cant_entrada', 'val_entrada', 'cant_salida', 'val_salida'],
                'query' => fn ($f) => self::kardexLines($f)
                    ->selectRaw('case when m.q > 0 then round(abs(m.v / m.q), 2) else 0 end costo_entrada, case when m.q < 0 then round(abs(m.v / m.q), 2) else 0 end costo_salida')
                    ->orderBy('m.date')->orderBy('m.id'),
            ],
            'ensambles' => [
                'title' => 'Ensamble producto proceso / terminado', 'filters' => ['product', 'group'], 'primary' => ['product'], 'labels' => ['group' => 'Categoría'],
                'columns' => ['codigo' => ['Código producto ensamble', 'text'], 'producto' => ['Nombre producto ensamble', 'text'], 'referencia' => ['Referencia fábrica ensamble', 'text'],
                    'bodega' => ['Bodega', 'text'], 'codigo_mp' => ['Código producto MP/PP', 'text'], 'producto_mp' => ['Nombre producto MP/PP', 'text'],
                    'referencia_mp' => ['Referencia fábrica MP/PP', 'text'], 'unidad' => ['Unidad medida', 'text'], 'cantidad' => ['Cantidad requerida', 'qty']],
                'totals' => [],
                'query' => fn ($f) => DB::table('product_combo_items as k')->join('products as p', 'p.id', '=', 'k.combo_id')->join('products as x', 'x.id', '=', 'k.product_id')
                    ->where('k.company_id', $f['cid'])->when($f['product'], fn ($q, $v) => $q->where('p.id', $v))->when($f['group'], fn ($q, $v) => $q->where('p.account_group_id', $v))
                    ->selectRaw("p.code codigo, p.name producto, coalesce(p.reference, '-') referencia, '-' bodega, x.code codigo_mp, x.name producto_mp, coalesce(x.reference, '-') referencia_mp,
                        x.unit_label unidad, k.quantity cantidad")->orderBy('p.code')->orderBy('x.code'),
            ],
            'remisiones-cruzadas' => [
                'title' => 'Informe de remisiones cruzadas', 'filters' => ['range', 'contact', 'status'], 'primary' => ['contact'],
                'labels' => ['range' => 'Fecha elaboración', 'contact' => 'Cliente'], 'statuses' => ['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'cruzada' => 'Cruzada'],
                'columns' => ['comprobante' => ['Comprobante', 'text'], 'secuencia' => ['Secuencia', 'int'], 'fecha' => ['Fecha Remisión', 'date'], 'cliente' => ['Cliente', 'text'],
                    'producto' => ['Producto / Servicio', 'text'], 'cantidad' => ['Cantidad Total', 'qty'], 'pendiente' => ['Cantidad Pendiente', 'qty'], 'factura' => ['Factura', 'text'],
                    'fecha_factura' => ['Fecha Factura', 'date'], 'entregada' => ['Cantidad Entregada', 'qty'], 'estado' => ['Estado', 'text']],
                'totals' => ['cantidad', 'pendiente', 'entregada'],
                'query' => fn ($f) => DB::query()->fromSub(self::sales($f, ['RM'])->join('contacts as c', 'c.id', '=', 'd.contact_id')->join('sales_document_items as i', 'i.sales_document_id', '=', 'd.id')
                    ->selectRaw("d.name comprobante, i.position + 1 secuencia, d.date fecha, c.name cliente, concat_ws(' - ', i.code, i.description) producto, i.quantity cantidad,
                        (select group_concat(v.name) from sales_documents v where v.related_document_id = d.id and v.type = 'FV' and v.status <> 'annulled') factura,
                        (select max(v.date) from sales_documents v where v.related_document_id = d.id and v.type = 'FV' and v.status <> 'annulled') fecha_factura,
                        least(i.quantity, (select coalesce(sum(vi.quantity), 0) from sales_documents v join sales_document_items vi on vi.sales_document_id = v.id
                            where v.related_document_id = d.id and v.type = 'FV' and v.status <> 'annulled' and vi.product_id <=> i.product_id)) entregada, d.id oid"), 't')
                    ->selectRaw("t.*, cantidad - entregada pendiente, case when entregada <= 0 then 'Pendiente' when entregada < cantidad then 'Parcial' else 'Cruzada' end estado")
                    ->when($f['status'], fn ($q, $v) => $q->havingRaw('estado = ?', [ucfirst($v)]))
                    ->orderBy('fecha')->orderBy('oid')->orderBy('secuencia'),
            ],

            // ---------------- Contabilidad ----------------
            'consecutivo-comprobantes' => [
                'title' => 'Consecutivo de comprobantes', 'filters' => ['creator', 'kind', 'range', 'status'], 'primary' => ['creator'],
                'labels' => ['range' => 'Fecha elaboración', 'kind' => 'Tipo transacción', 'status' => 'Estado consecutivo'],
                'kinds' => ['C' => 'Cotización', 'FV' => 'Factura de venta', 'NC' => 'Nota crédito', 'RC' => 'Recibo de caja', 'FC' => 'Factura de compra', 'DS' => 'Documento soporte',
                    'RP' => 'Recibo de pago', 'CC' => 'Comprobante contable'],
                'statuses' => ['contabilizado' => 'Contabilizado', 'anulado' => 'Anulado'],
                'columns' => ['tipo' => ['Tipo transacción', 'text'], 'comprobante' => ['Comprobante', 'text'], 'valor' => ['Valor', 'money'], 'moneda' => ['Moneda', 'text'],
                    'fecha' => ['Fecha elaboración', 'date'], 'creacion' => ['Fecha creación', 'date'], 'creador' => ['Creado por', 'text'], 'estado' => ['Estado consecutivo', 'text']],
                'totals' => ['valor'],
                'links' => ['comprobante' => '@url'],
                'query' => function ($f) {
                    $user = fn ($a, $seller = false) => "coalesce((select u.name from users u where u.id = $a.created_by)".($seller ? ", (select s.name from sellers s where s.id = $a.seller_id)" : '').", '-')";
                    $state = fn ($a) => "if($a.status = 'annulled', 'Anulado', 'Contabilizado')";
                    $base = fn ($tbl, $a) => DB::table("$tbl as $a")->where("$a.company_id", $f['cid'])->whereBetween("$a.date", [$f['from'], $f['to']])
                        ->when($f['creator'], fn ($q, $v) => $q->where("$a.created_by", $v))
                        ->when($f['status'], fn ($q, $v) => $v === 'anulado' ? $q->where("$a.status", 'annulled') : $q->where("$a.status", '<>', 'annulled'));
                    $code = fn ($a) => "substring_index($a.name, '-', 1)";
                    $rows = $base('sales_documents', 'd')->whereIn('d.type', ['FV', 'NC', 'ND', 'CT'])
                        ->selectRaw("{$code('d')} tipo, d.name comprobante, d.total valor, d.currency moneda, d.date fecha, date(d.created_at) creacion, {$user('d', true)} creador, {$state('d')} estado, concat('/documentos/ventas/', d.id) url")
                        ->unionAll($base('purchase_documents', 'd')->whereIn('d.type', ['FC', 'DS', 'NC', 'ND', 'NA'])
                            ->selectRaw("{$code('d')} tipo, d.name comprobante, d.total valor, d.currency moneda, d.date fecha, date(d.created_at) creacion, {$user('d')} creador, {$state('d')} estado, concat('/documentos/compras/', d.id) url"))
                        ->unionAll($base('receipts', 'd')
                            ->selectRaw("{$code('d')} tipo, d.name comprobante, d.total valor, 'COP' moneda, d.date fecha, date(d.created_at) creacion, {$user('d')} creador, {$state('d')} estado, concat('/documentos/recibos/', d.id) url"))
                        ->unionAll($base('journal_entries', 'd')
                            ->selectRaw("{$code('d')} tipo, d.name comprobante, d.debit valor, 'COP' moneda, d.date fecha, date(d.created_at) creacion, {$user('d')} creador, {$state('d')} estado, concat('/documentos/comprobantes/', d.id) url"));

                    return DB::query()->fromSub($rows, 't')->when($f['kind'], fn ($q, $v) => $q->where('tipo', $v))
                        ->orderBy('tipo')->orderByRaw("substring_index(comprobante, '-', 2), cast(substring_index(comprobante, '-', -1) as unsigned)");
                },
            ],
            'comprobantes-no-contabilizados' => [
                'title' => 'Comprobantes no contabilizados', 'filters' => ['year'], 'primary' => ['year'],
                'columns' => ['tipo' => ['Tipo de transacción', 'text'], 'documento' => ['Comprobante', 'text'], 'ident' => ['Identificación', 'text'], 'tercero' => ['Nombre del tercero', 'text'],
                    'fecha' => ['Fecha de elaboración', 'date'], 'estado' => ['Estado', 'text'], 'accion' => ['Acciones', 'text']],
                'totals' => [],
                'links' => ['accion' => '@url', 'documento' => '@url'],
                'query' => function ($f) {
                    $y = [$f['year'].'-01-01', $f['year'].'-12-31'];
                    $missing = fn ($q) => $q->whereNotExists(fn ($w) => $w->from('ledger_lines as g')->where('g.company_id', $f['cid'])->whereColumn('g.document', 'd.name'));
                    $label = "case d.type when 'FV' then 'Factura de venta' when 'NC' then 'Nota crédito' when 'ND' then 'Nota débito' when 'FC' then 'Factura de compra'
                        when 'DS' then 'Documento soporte' when 'NA' then 'Nota de ajuste' else d.type end";

                    return $missing(DB::table('sales_documents as d')->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])
                        ->whereIn('d.type', ['FV', 'NC', 'ND'])->where('d.status', '<>', 'annulled')->whereBetween('d.date', $y))
                        ->selectRaw("$label tipo, d.name documento, c.identification ident, c.name tercero, d.date fecha, 'No contabilizado' estado, 'Ver' accion, concat('/documentos/ventas/', d.id) url")
                        ->unionAll($missing(DB::table('purchase_documents as d')->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])
                            ->whereIn('d.type', ['FC', 'DS', 'NC', 'ND', 'NA'])->where('d.status', '<>', 'annulled')->whereBetween('d.date', $y))
                            ->selectRaw("$label tipo, d.name documento, c.identification ident, c.name tercero, d.date fecha, 'No contabilizado' estado, 'Ver' accion, concat('/documentos/compras/', d.id) url"));
                },
            ],
            'comprobantes-detallados' => [
                'title' => 'Comprobantes detallados', 'filters' => ['range', 'doctype', 'account', 'seller'], 'column_picker' => true,
                'columns' => ['documento' => ['Comprobante', 'text'], 'secuencia' => ['Secuencia', 'int'], 'fecha' => ['Fecha elaboración', 'date'], 'cuenta' => ['Código contable', 'text'],
                    'nombre_cuenta' => ['Cuenta contable', 'text'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'tercero' => ['Nombre tercero', 'text'], 'vendedor' => ['Vendedor', 'text'],
                    'descripcion' => ['Descripción', 'text'], 'detalle' => ['Detalle', 'text'], 'centro' => ['Centro de costo', 'text'], 'debito' => ['Débito', 'money'], 'credito' => ['Crédito', 'money']],
                'totals' => ['debito', 'credito'],
                'query' => fn ($f) => DB::table('ledger_lines as g')->leftJoin('accounts as a', 'a.id', '=', 'g.account_id')->where('g.company_id', $f['cid'])->whereBetween('g.date', [$f['from'], $f['to']])
                    ->when($f['doctype'], fn ($q, $v) => $q->where('g.document_type', $v))->when($f['account'], fn ($q, $v) => $q->where('g.account_code', 'like', "$v%"))
                    ->when($f['seller'], fn ($q, $v) => $q->whereExists(fn ($w) => $w->from('sales_documents as d')->whereColumn('d.company_id', 'g.company_id')->whereColumn('d.name', 'g.document')
                        ->where(fn ($s) => $s->where('d.seller_id', $v)->orWhereExists(fn ($i) => $i->from('sales_document_items as i')->whereColumn('i.sales_document_id', 'd.id')->where('i.seller_id', $v)))))
                    ->selectRaw("g.document documento, row_number() over (partition by g.document order by g.id) secuencia, g.date fecha, g.account_code cuenta, a.name nombre_cuenta,
                        g.identification ident, if(coalesce(g.branch, 0) = 0, '-', g.branch) sucursal, g.contact_name tercero, ".self::sellerName('g')." vendedor, '-' descripcion, '-' detalle, '-' centro, g.debit debito, g.credit credito")
                    ->orderBy('g.document')->orderBy('g.id'),
            ],
            'flujo-efectivo' => [
                'title' => 'Estado de flujos de efectivo - Método indirecto', 'filters' => ['year'], 'paginate' => false,
                'note' => 'Entradas y salidas de efectivo del año comparadas con el año anterior, con los rubros y cuentas de la configuración contable estándar.',
                'columns' => ['concepto' => ['Concepto', 'text'], 'actual' => ['Año seleccionado', 'money'], 'anterior' => ['Año anterior', 'money']],
                'totals' => [],
                'rows' => fn ($f) => self::cashFlow($f),
            ],
            'cambios-patrimonio' => [
                'title' => 'Estado de cambios en el patrimonio', 'filters' => ['year'], 'paginate' => false,
                'note' => 'Movimientos del patrimonio del año comparados con el saldo al cierre del año anterior, con los rubros y cuentas de la configuración contable estándar.',
                'columns' => ['rubro' => ['Rubro', 'text'], 'cuentas' => ['Cuentas', 'text'], 'inicial' => ['Saldo año anterior', 'money'], 'aumentos' => ['Aumentos', 'money'],
                    'disminuciones' => ['Disminuciones', 'money'], 'final' => ['Saldo año seleccionado', 'money']],
                'totals' => ['inicial', 'aumentos', 'disminuciones', 'final'],
                'rows' => fn ($f) => self::equityChanges($f),
            ],
            'auxiliar-centro-costo' => [
                'title' => 'Movimiento Auxiliar Centro de costo por cuenta contable', 'filters' => ['range', 'account', 'cost_center'],
                'columns' => ['documento' => ['Comprobante', 'text'], 'secuencia' => ['Secuencia', 'int'], 'fecha' => ['Fecha elaboración', 'date'], 'descripcion' => ['Descripción', 'text'],
                    'detalle' => ['Detalle', 'text'], 'inicial' => ['Saldo inicial', 'money'], 'debito' => ['Débito', 'money'], 'credito' => ['Crédito', 'money'], 'saldo' => ['Saldo Movimiento', 'money']],
                'totals' => ['debito', 'credito'],
                'query' => fn ($f) => self::costCenterLines($f)->when($f['cost_center'], fn ($q, $v) => $q->where('jl.cost_center_id', $v))
                    ->selectRaw("je.name documento, row_number() over (partition by je.id order by jl.id) secuencia, jl.date fecha, concat(a.code, ' - ', a.name) descripcion,
                        coalesce(jl.description, concat(cc.code, ' - ', cc.name)) detalle, 0 inicial, jl.debit debito, jl.credit credito, jl.debit - jl.credit saldo")
                    ->orderBy('cc.code')->orderBy('a.code')->orderBy('jl.date'),
            ],
            'balance-centro-costo' => [
                'title' => 'Balance de prueba por centro de costo', 'filters' => ['range', 'account', 'cost_center'],
                'columns' => ['cuenta' => ['Código cuenta contable', 'text'], 'nombre' => ['Nombre cuenta contable', 'text'], 'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'],
                    'tercero' => ['Nombre tercero', 'text'], 'inicial' => ['Saldo inicial', 'money'], 'debito' => ['Movimiento débito', 'money'], 'credito' => ['Movimiento crédito', 'money'], 'saldo' => ['Saldo final', 'money']],
                'totals' => ['debito', 'credito'],
                'query' => fn ($f) => self::costCenterLines($f)->leftJoin('contacts as c', 'c.id', '=', 'jl.contact_id')->when($f['cost_center'], fn ($q, $v) => $q->where('jl.cost_center_id', $v))
                    ->selectRaw("a.code cuenta, a.name nombre, coalesce(c.identification, '-') ident, ".self::branch('c')." sucursal, coalesce(c.name, '-') tercero, 0 inicial,
                        sum(jl.debit) debito, sum(jl.credit) credito, sum(jl.debit - jl.credit) saldo")
                    ->groupBy('a.code', 'a.name', 'c.id', 'c.identification', 'c.branch_office', 'c.name')->orderBy('a.code'),
            ],

            // ---------------- Nomina (Reportes de nómina) ----------------
            'prima-servicios' => [
                'title' => 'Prima de servicios', 'filters' => ['range', 'contact', 'cost_center'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'contrato' => ['No. Contrato', 'text'], 'sueldo' => ['Sueldo', 'money'],
                    'origen' => ['Origen', 'text'], 'centro' => ['Centro de costo', 'text'], 'dias_lnr' => ['Días Lic. no remunerada', 'qty'], 'valor_lnr' => ['Valor Lic. no remunerada', 'money'],
                    'acumulado' => ['Acumulado', 'money'], 'promedio' => ['Salario promedio', 'money'], 'dias' => ['Días trabajados', 'qty'], 'prima' => ['Valor prima', 'money'],
                    'anticipo' => ['Anticipo', 'money'], 'retencion' => ['Retención', 'money'], 'editado' => ['Valor editado', 'text'], 'neto' => ['Valor neto', 'money']],
                'totals' => ['prima', 'anticipo', 'retencion', 'neto'],
                'query' => fn ($f) => self::payrollDocs($f)->join('payroll_runs as r', 'r.id', '=', 'pd.payroll_run_id')->where('r.kind', 'prima')
                    ->selectRaw("c.identification ident, c.name nombre, pd.contract_number contrato, pd.salary sueldo, 'Detallados' origen, cc.name centro,
                        0 dias_lnr, 0 valor_lnr, ".self::payrollItem('prima', 'base * quantity / 30')." acumulado, ".self::payrollItem('prima', 'base')." promedio,
                        pd.worked_days dias, ".self::payrollItem('prima')." prima, 0 anticipo, ".self::payrollItem('retefuente')." retencion, 'No' editado, pd.net neto")
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period'),
            ],
            'provisiones' => [
                'title' => 'Provisiones', 'filters' => ['range', 'contact', 'cost_center'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'contrato' => ['No contrato', 'text'], 'cesantias' => ['Cesantías', 'money'],
                    'intereses' => ['Intereses sobre cesantías', 'money'], 'prima' => ['Prima', 'money'], 'vacaciones' => ['Vacaciones', 'money'], 'total' => ['Total Provisiones', 'money']],
                'totals' => ['cesantias', 'intereses', 'prima', 'vacaciones', 'total'],
                'query' => fn ($f) => self::payrollDocs($f)->leftJoin('payroll_items as i', fn ($j) => $j->on('i.payroll_document_id', '=', 'pd.id')->where('i.type', 'provision'))
                    ->groupBy('e.id', 'c.identification', 'c.name', 'e.contract_number')
                    ->selectRaw("c.identification ident, c.name nombre, e.contract_number contrato,
                        coalesce(sum(if(i.concept = 'prov_cesantias', i.value, 0)), 0) cesantias, coalesce(sum(if(i.concept = 'prov_intereses', i.value, 0)), 0) intereses,
                        coalesce(sum(if(i.concept = 'prov_prima', i.value, 0)), 0) prima, coalesce(sum(if(i.concept = 'prov_vacaciones', i.value, 0)), 0) vacaciones,
                        coalesce(sum(i.value), 0) total")
                    ->orderByRaw('cast(c.identification as unsigned)'),
            ],
            'acumulados' => [
                'title' => 'Acumulados', 'filters' => ['range', 'contact', 'kind', 'cost_center', 'q'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado', 'kind' => 'Acumulado', 'q' => 'Novedad'],
                'kinds' => ['detallados' => 'Detallados', 'iniciales' => 'Iniciales'], 'defaults' => ['kind' => 'detallados'],
                'columns' => ['ident' => ['Identificación', 'text'], 'nombre' => ['Nombre empleado', 'text'], 'contrato' => ['No contrato', 'text'], 'periodo' => ['Periodo', 'text'],
                    'mes' => ['Mes', 'text'], 'anio' => ['Año', 'text'], 'centro' => ['Centro de costo', 'text'], 'origen' => ['Origen', 'text'], 'novedad' => ['Novedad', 'text'],
                    'tipo' => ['Tipo', 'text'], 'cantidad' => ['Horas/Días', 'qty'], 'valor' => ['Valor total', 'money']],
                'totals' => ['cantidad', 'valor'],
                'query' => fn ($f) => self::payrollDocs($f)->join('payroll_items as i', 'i.payroll_document_id', '=', 'pd.id')->whereIn('i.type', ['earning', 'deduction'])
                    ->when($f['kind'] === 'iniciales', fn ($q) => $q->whereRaw('1 = 0'))->when($f['q'], fn ($q, $v) => $q->where('i.label', 'like', "%$v%"))
                    ->selectRaw("c.identification ident, c.name nombre, pd.contract_number contrato, coalesce(e.payment_frequency, 'Mensual') periodo,
                        cast(substr(pd.period, 6, 2) as unsigned) mes, cast(left(pd.period, 4) as unsigned) anio, cc.name centro, 'Detallados' origen, i.label novedad,
                        if(i.type = 'earning', 'Ingreso', 'Deducción') tipo, i.quantity cantidad, i.value valor")
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period')
                    ->orderByRaw("if(field(i.concept, 'sueldo', 'auxilio_transporte', 'salud', 'pension') = 0, 99, field(i.concept, 'sueldo', 'auxilio_transporte', 'salud', 'pension')), i.id"),
            ],
            'seguridad-social' => [
                'title' => 'Seguridad Social y Parafiscales', 'filters' => ['range', 'contact'], 'primary' => ['contact'],
                'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['empleado' => ['Empleado', 'text'], 'nomina' => ['Nómina', 'text'], 'ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'],
                    'contrato' => ['No contrato', 'text'], 'eps' => ['Fondo de salud', 'text'], 'devengado' => ['Total devengado Seguridad Social', 'money'],
                    'ibc_pension_emp' => ['IBC pensión empresa', 'money'], 'ibc_salud_emp' => ['IBC salud empresa', 'money'], 'exonerado' => ['Exonerado', 'text'],
                    'ibc_salud' => ['IBC salud empleado', 'money'], 'salud' => ['Aporte salud empleado', 'money'], 'salud_emp' => ['Aporte salud empresa', 'money'],
                    'afp' => ['Fondo de pensión', 'text'], 'ibc_pension' => ['IBC pensión empleado', 'money'], 'pension' => ['Aporte pensión empleado', 'money'],
                    'pension_pub' => ['Aporte pensión Publico empleado', 'money'], 'pension_emp' => ['Aporte pensión empresa', 'money'],
                    'pension_pub_emp' => ['Aporte pensión Publico empresa', 'money'], 'fsp' => ['Fondo de Solidaridad Pensional (FSP)', 'money'], 'arl' => ['ARL', 'text'],
                    'ibc_arl' => ['IBC ARL', 'money'], 'arl_pct' => ['Porcentaje ARL', 'qty'], 'arl_emp' => ['ARL Empresa', 'money'], 'ibc_para' => ['IBC Parafiscales', 'money'],
                    'exonerado_para' => ['Exonerado Parafiscales', 'text'], 'ccf' => ['Caja de compensación', 'money'], 'icbf' => ['ICBF', 'money'], 'sena' => ['SENA', 'money'],
                    'periodo' => ['Periodo', 'date']],
                'totals' => ['devengado', 'ibc_pension_emp', 'ibc_salud_emp', 'ibc_salud', 'salud', 'salud_emp', 'ibc_pension', 'pension', 'pension_pub', 'pension_emp',
                    'pension_pub_emp', 'fsp', 'ibc_arl', 'arl_pct', 'arl_emp', 'ibc_para', 'ccf', 'icbf', 'sena'],
                'query' => fn ($f) => self::payrollDocs($f)->leftJoin('contacts as ce', 'ce.id', '=', 'e.eps_contact_id')->leftJoin('contacts as cp', 'cp.id', '=', 'e.pension_contact_id')
                    ->leftJoin('contacts as ca', 'ca.id', '=', 'e.arl_contact_id')
                    ->selectRaw(implode(', ', ["cast(e.id as char) empleado, cast(pd.id as char) nomina, c.identification ident, c.name nombre, pd.contract_number contrato",
                        'coalesce(e.eps, ce.name) eps', self::payrollItem(['salud_empresa', 'pension_empresa'], 'base', 'max').' devengado',
                        self::payrollItem('pension_empresa', 'base').' ibc_pension_emp', self::payrollItem('salud_empresa', 'base').' ibc_salud_emp', "'No' exonerado",
                        self::payrollItem('salud', 'base').' ibc_salud', self::payrollItem('salud').' salud', self::payrollItem('salud_empresa').' salud_emp',
                        'coalesce(e.pension_fund, cp.name) afp', self::payrollItem('pension', 'base').' ibc_pension', self::payrollItem('pension').' pension', '0 pension_pub',
                        self::payrollItem('pension_empresa').' pension_emp', '0 pension_pub_emp', self::payrollItem('fsp').' fsp', 'coalesce(e.arl, ca.name) arl',
                        self::payrollItem('arl', 'base').' ibc_arl', 'elt(greatest(coalesce(e.arl_class, 1), 1), 0.522, 1.044, 2.436, 4.35, 6.96) arl_pct',
                        self::payrollItem('arl').' arl_emp', self::payrollItem('ccf', 'base').' ibc_para', "'No' exonerado_para", self::payrollItem('ccf').' ccf',
                        self::payrollItem('icbf').' icbf', self::payrollItem('sena').' sena', "date(concat(pd.period, '-', least(30, day(last_day(concat(pd.period, '-01')))))) periodo"]))
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period'),
            ],

            // ---------------- Nomina (versiones anteriores) ----------------
            'acumulados-nomina-generales' => [
                'title' => 'Acumulados generales de nómina', 'filters' => ['year'],
                'columns' => ['liquidacion' => ['Liquidación', 'text'], 'tipo' => ['Tipo', 'text'], 'desde' => ['Desde', 'date'], 'hasta' => ['Hasta', 'date'], 'estado' => ['Estado', 'text'], 'total' => ['Total', 'money']],
                'totals' => ['total'],
                'query' => fn ($f) => DB::table('payroll_runs')->where('company_id', $f['cid'])->whereYear('period_start', $f['year'])
                    ->selectRaw('name liquidacion, settlement_type tipo, period_start desde, period_end hasta, status estado, total')->orderBy('period_start'),
            ],
            'acumulados-nomina-detallados' => [
                'title' => 'Acumulados detallados de nómina', 'filters' => ['range', 'contact', 'cost_center'], 'primary' => ['contact'], 'labels' => ['range' => 'Periodo', 'contact' => 'Empleado'],
                'columns' => ['contrato' => ['Número contrato', 'text'], 'ident' => ['Identificación', 'text'], 'nombre' => ['Nombre empleado', 'text'], 'periodo' => ['Periodo', 'text'],
                    'mes' => ['Mes', 'text'], 'anio' => ['Año', 'text'], 'centro' => ['Centro costo', 'text'], 'novedad' => ['Novedad', 'text'], 'tipo' => ['Tipo', 'text'],
                    'cantidad' => ['Horas/Días', 'qty'], 'valor' => ['Valor en nómina', 'money'], 'inicial' => ['Valor acumulado inicial', 'money'], 'total' => ['Valor total', 'money']],
                'totals' => ['cantidad', 'valor', 'inicial', 'total'],
                'query' => fn ($f) => self::payrollDocs($f)->join('payroll_items as i', 'i.payroll_document_id', '=', 'pd.id')->whereIn('i.type', ['earning', 'deduction'])
                    ->selectRaw("pd.contract_number contrato, c.identification ident, c.name nombre, coalesce(e.payment_frequency, 'Mensual') periodo,
                        cast(substr(pd.period, 6, 2) as unsigned) mes, cast(left(pd.period, 4) as unsigned) anio, cc.name centro, i.label novedad,
                        if(i.type = 'earning', 'Ingreso', 'Deducción') tipo, i.quantity cantidad, i.value valor, 0 inicial, i.value total")
                    ->orderByRaw('cast(c.identification as unsigned)')->orderBy('pd.period')->orderBy('i.id'),
            ],
            'acumulados-iniciales-nomina' => [
                'title' => 'Acumulados iniciales de nómina', 'filters' => ['contact'], 'primary' => ['contact'], 'labels' => ['contact' => 'Empleado'],
                'columns' => ['contrato' => ['No contrato', 'text'], 'ident' => ['Identificación', 'text'], 'nombre' => ['Nombre', 'text'], 'novedad' => ['Novedad', 'text'],
                    'cantidad' => ['Horas/Días', 'qty'], 'valor' => ['Valor', 'money'], 'periodo' => ['Periodo', 'text'], 'mes' => ['Mes', 'text']],
                'totals' => ['cantidad', 'valor'],
                'note' => 'Los acumulados iniciales son los valores de nómina registrados antes de empezar a liquidar en el sistema.',
                'query' => fn ($f) => DB::table('employees as e')->join('contacts as c', 'c.id', '=', 'e.contact_id')->where('e.company_id', $f['cid'])->whereRaw('1 = 0')
                    ->selectRaw("e.contract_number contrato, c.identification ident, c.name nombre, '' novedad, 0 cantidad, 0 valor, '' periodo, '' mes"),
            ],

            // ---------------- General ----------------
            'auditoria-borrado' => [
                'title' => 'Auditoría de borrado de comprobantes', 'filters' => ['range', 'q'], 'primary' => ['q'], 'labels' => ['range' => 'Fecha de borrado', 'q' => 'Comprobante'],
                'columns' => ['comprobante' => ['Comprobante borrado', 'text'], 'ident' => ['Identificación', 'text'], 'tercero' => ['Nombre del tercero', 'text'],
                    'fecha' => ['Fecha y hora de borrado', 'text'], 'usuario' => ['Usuario', 'text'], 'correo' => ['Correo electrónico', 'text'], 'origen' => ['Origen de borrado', 'text']],
                'totals' => [],
                'query' => fn ($f) => DB::query()->fromSub(DB::table('audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->where('l.company_id', $f['cid'])->where('l.event', 'deleted')
                    ->whereBetween(DB::raw('date(l.created_at)'), [$f['from'], $f['to']])
                    ->selectRaw("coalesce(json_unquote(json_extract(l.old_values, '$.name')), concat(substring_index(l.auditable_type, '\\\\', -1), ' #', l.auditable_id)) comprobante,
                        json_unquote(json_extract(l.old_values, '$.contact_id')) contact_id, date_format(l.created_at, '%d/%m/%Y %h:%i %p') fecha, u.name usuario, u.email correo,
                        'Individual' origen, l.created_at creado"), 't')
                    ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->when($f['q'], fn ($q, $v) => $q->where('t.comprobante', 'like', "%$v%"))
                    ->selectRaw("t.comprobante, coalesce(c.identification, '-') ident, coalesce(c.name, '-') tercero, t.fecha, t.usuario, t.correo, t.origen")->orderByDesc('t.creado'),
            ],
            'paises-ciudades' => [
                'title' => 'Países, departamentos y ciudades', 'filters' => ['q'], 'primary' => ['q'], 'labels' => ['q' => 'Ciudad'],
                'columns' => ['pais' => ['País', 'text'], 'departamento' => ['Estado / Departamento', 'text'], 'ciudad' => ['Ciudad', 'text'],
                    'cod_pais' => ['Código país', 'text'], 'cod_dep' => ['Código Estado / Departamento', 'text'], 'codigo' => ['Código ciudad', 'text']],
                'totals' => [],
                'query' => fn ($f) => DB::table('geo_cities as g')
                    ->when($f['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('g.country', 'like', "%$v%")->orWhere('g.state', 'like', "%$v%")->orWhere('g.name', 'like', "%$v%")))
                    ->selectRaw('g.country pais, g.state departamento, g.name ciudad, g.country_code cod_pais, g.state_code cod_dep, g.code codigo')
                    ->orderByRaw("g.country_code <> 'COL'")->orderBy('g.country')->orderBy('g.name'),
            ],
            'historial-reportes' => [
                'title' => 'Historial de reportes generados', 'filters' => ['q', 'status'], 'primary' => ['q', 'status'], 'banner' => true, 'refresh' => true, 'q_placeholder' => 'Buscar por nombre',
                'note' => 'En esta sección encontrarás los reportes generados en las últimas 24 horas, disponibles para su descarga.',
                'labels' => ['q' => 'Búsqueda'], 'statuses' => ['finalizado' => 'Finalizado'],
                'columns' => ['reporte' => ['Nombre reporte', 'text'], 'formato' => ['Formato', 'text'], 'generacion' => ['Generación', 'text'],
                    'estado' => ['Estado', 'text'], 'accion' => ['Acción', 'text']],
                'totals' => [],
                'links' => ['accion' => '@url'],
                'query' => fn ($f) => DB::table('report_exports as x')->where('x.company_id', $f['cid'])->where('x.created_at', '>=', now()->subDay())
                    ->when($f['q'], fn ($q, $v) => $q->where('x.title', 'like', "%$v%"))
                    ->selectRaw("x.title reporte, upper(coalesce(x.format, 'xlsx')) formato, date_format(x.created_at, '%d/%m/%Y %h:%i %p') generacion,
                        'Finalizado' estado, if(x.url is null, null, 'Descargar') accion, x.url")->orderByDesc('x.created_at'),
            ],
        ];
    }

    private const ID_TYPE_SQL = "case c.identification_type when '13' then 'Cédula de ciudadanía' when '31' then 'NIT' when '22' then 'Cédula de extranjería'
        when '21' then 'Tarjeta de extranjería' when '41' then 'Pasaporte' when '12' then 'Tarjeta de identidad' when '11' then 'Registro civil'
        when '42' then 'Documento de identificación extranjero' when '47' then 'PEP' when '50' then 'NIT de otro país' when '91' then 'NUIP' else '-' end";

    private const PSIGN = "(CASE WHEN d.type IN ('NC', 'NA') THEN -1 ELSE 1 END)";

    // ---------------- Consultas base ----------------

    public static function sales(array $f, array $types = ['FV', 'NC']): Builder
    {
        return DB::table('sales_documents as d')->where('d.company_id', $f['cid'])->whereIn('d.type', $types)->where('d.status', '<>', 'annulled')
            ->whereBetween('d.date', [$f['from'], $f['to']])
            ->when($f['contact'] ?? null, fn ($q, $v) => $q->where('d.contact_id', $v))
            ->when($f['seller'] ?? null, fn ($q, $v) => $q->where('d.seller_id', $v));
    }

    public static function salesItems(array $f, array $types = ['FV', 'NC']): Builder
    {
        return self::sales($f, $types)->join('sales_document_items as i', 'i.sales_document_id', '=', 'd.id')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v));
    }

    public static function purchases(array $f, array $types = ['FC', 'DS', 'NC', 'ND', 'NA']): Builder
    {
        return DB::table('purchase_documents as d')->where('d.company_id', $f['cid'])->whereIn('d.type', $types)->where('d.status', '<>', 'annulled')
            ->whereBetween('d.date', [$f['from'], $f['to']])
            ->when($f['contact'] ?? null, fn ($q, $v) => $q->where('d.contact_id', $v));
    }

    public static function purchaseItems(array $f): Builder
    {
        return self::purchases($f)->join('purchase_document_items as i', 'i.purchase_document_id', '=', 'd.id')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v));
    }

    private static function receipts(array $f, string $type): Builder
    {
        return DB::table('receipts as r')->join('receipt_items as ri', 'ri.receipt_id', '=', 'r.id')->join('contacts as c', 'c.id', '=', 'r.contact_id')
            ->where('r.company_id', $f['cid'])->where('r.type', $type)->where('r.status', '<>', 'annulled')->whereBetween('r.date', [$f['from'], $f['to']])
            ->when($f['contact'], fn ($q, $v) => $q->where('r.contact_id', $v));
    }

    private static function products(array $f): Builder
    {
        return DB::table('products as p')->leftJoin('product_lines as l', 'l.id', '=', 'p.product_line_id')->where('p.company_id', $f['cid'])->where('p.stock_control', true)
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('p.id', $v))
            ->when($f['line'] ?? null, fn ($q, $v) => $q->where('p.product_line_id', $v));
    }

    /** Libro oficial: un renglon por comprobante con bases gravada y exenta segun el IVA de cada item; el total no descuenta retenciones. */
    private static function officialBook(array $f, bool $purchases): Builder
    {
        $sign = $purchases ? self::PSIGN : self::SIGN;
        $items = $purchases ? 'purchase_document_items' : 'sales_document_items';
        $taxes = $purchases ? 'purchase_document_item_taxes' : 'sales_document_item_taxes';
        $fk = $purchases ? 'purchase_document_id' : 'sales_document_id';
        $q = ($purchases ? self::purchases($f, ['FC', 'DS', 'NC', 'ND', 'NA']) : self::sales($f))->join('contacts as c', 'c.id', '=', 'd.contact_id')
            ->join("$items as i", "i.$fk", '=', 'd.id')
            ->leftJoin("$taxes as t", fn ($j) => $j->on('t.'.($purchases ? 'purchase_document_item_id' : 'sales_document_item_id'), '=', 'i.id')->where('t.type', 'IVA'))
            ->groupBy(['d.id', 'd.name', 'd.date', 'd.type', 'd.number', 'c.identification', 'c.branch_office', 'c.name', ...($purchases ? ['d.supplier_prefix', 'd.supplier_number'] : [])]);
        $gravada = "round(sum(case when coalesce(t.percentage, 0) > 0 then i.subtotal else 0 end) * $sign, 2)";
        $exenta = "round(sum(case when coalesce(t.percentage, 0) > 0 then 0 else i.subtotal end) * $sign, 2)";
        $iva = "round(sum(coalesce(t.value, 0)) * $sign, 2)";
        $who = "d.name documento, d.date fecha, c.identification ident, ".self::branch('c')." sucursal, c.name tercero";

        return $purchases
            ? $q->selectRaw("$who, nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '') factura, $gravada gravada, $exenta exenta, $iva iva, $gravada + $exenta + $iva total")
                ->orderByDesc('d.date')->orderByDesc('d.id')
            : $q->selectRaw("$who, 0 aiu, $gravada gravada, $exenta exenta, $iva iva, 0 impoconsumo, 0 advalorem, 0 cargos, 0 descuentos, $gravada + $exenta + $iva total")
                ->orderByDesc('d.date')->orderByDesc('d.number');
    }

    private static function stockProducts(array $f): Builder
    {
        return DB::table('products as p')->leftJoin('account_groups as g', 'g.id', '=', 'p.account_group_id')->where('p.company_id', $f['cid'])->where('p.stock_control', true)
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('p.id', $v))->when($f['group'] ?? null, fn ($q, $v) => $q->where('p.account_group_id', $v));
    }

    /**
     * Saldo en cantidad y valor de cada producto inventariable al cierre del mes: el último saldo de la historia importada hasta esa
     * fecha más los movimientos propios de Nubicta, con la misma continuidad que kardexLines.
     */
    private static function valuation(array $f): Builder
    {
        $cid = (int) $f['cid'];
        $cut = DB::getPdo()->quote(Carbon::create($f['year'], $f['month'])->endOfMonth()->toDateString());
        $balances = "select x.product_id, sum(x.q) q, sum(x.v) v from (
                select h.product_id, h.balance_quantity q, h.balance_value v from inventory_history h
                join (select product_id, max(position) position from inventory_history where company_id = $cid and date <= $cut group by product_id) l
                    on l.product_id = h.product_id and l.position = h.position
                where h.company_id = $cid
                union all
                select m.product_id, sum(m.quantity), sum(case when m.quantity >= 0 then abs(m.total_cost) else -abs(m.total_cost) end)
                from inventory_movements m
                where m.company_id = $cid and m.date <= $cut
                    and (m.concept <> 'saldo_inicial' or not exists (select 1 from inventory_history e where e.company_id = $cid and e.product_id = m.product_id))
                group by m.product_id
            ) x group by x.product_id";

        return self::stockProducts($f)->joinSub($balances, 'b', 'b.product_id', '=', 'p.id')
            ->whereRaw('(b.q <> 0 or round(b.v, 2) <> 0)')
            ->selectRaw("p.code codigo, p.name producto, coalesce(p.reference, '-') referencia, g.name categoria, b.q existencia, round(b.v, 2) valor")
            ->orderByDesc('p.id');
    }

    /**
     * Renglones del kárdex: la historia importada de Siigo (inventory_history, ver inventory:history) y luego los movimientos propios
     * de Nubicta, cuyo saldo continúa el de la historia en lugar del saldo inicial de la apertura.
     */
    private static function kardexLines(array $f): Builder
    {
        $cid = (int) $f['cid'];
        $scope = fn (string $col) => ($f['product'] ? " and $col = ".(int) $f['product'] : '')
            .(($f['group'] ?? null) ? " and $col in (select id from products where account_group_id = ".(int) $f['group'].')' : '');
        $from = DB::getPdo()->quote($f['from']);
        $to = DB::getPdo()->quote($f['to']);

        $rows = "select h.product_id, h.date, h.position id, h.document doc, h.quantity q, h.value v, h.balance_quantity bq, h.balance_value bv
            from inventory_history h where h.company_id = $cid and h.date between $from and $to {$scope('h.product_id')}
            union all
            select m.product_id, m.date, 1000000000000 + m.id, m.document, m.quantity, case when m.quantity >= 0 then abs(m.total_cost) else -abs(m.total_cost) end,
                m.balance_quantity + coalesce(e.balance_quantity - o.q, 0), m.balance_cost + coalesce(e.balance_value - o.v, 0)
            from inventory_movements m
            left join (select product_id, sum(quantity) q, sum(total_cost) v from inventory_movements where company_id = $cid and concept = 'saldo_inicial' {$scope('product_id')} group by product_id) o
                on o.product_id = m.product_id
            left join (select h.product_id, h.balance_quantity, h.balance_value from inventory_history h
                join (select product_id, max(position) position from inventory_history where company_id = $cid {$scope('product_id')} group by product_id) l
                on l.product_id = h.product_id and l.position = h.position where h.company_id = $cid) e on e.product_id = m.product_id
            where m.company_id = $cid and m.date between $from and $to and (m.concept <> 'saldo_inicial' or e.product_id is null) {$scope('m.product_id')}";

        return DB::table(DB::raw("($rows) as m"))->join('products as p', 'p.id', '=', 'm.product_id')
            ->selectRaw("m.doc comprobante, 1 secuencia, m.date fecha, m.bq - m.q cant_inicial, greatest(m.q, 0) cant_entrada, greatest(-m.q, 0) cant_salida,
                m.bq cant_saldo, round(m.bv - m.v, 2) val_inicial, case when m.q > 0 or (m.q = 0 and m.v > 0) then round(abs(m.v), 2) else 0 end val_entrada,
                case when m.q < 0 or (m.q = 0 and m.v < 0) then round(abs(m.v), 2) else 0 end val_salida, round(m.bv, 2) val_saldo");
    }

    private static function movements(array $f, string $source): Builder
    {
        return DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.company_id', $f['cid'])->where('m.document', 'like', $source.'-%')
            ->whereBetween('m.date', [$f['from'], $f['to']])->when($f['product'], fn ($q, $v) => $q->where('m.product_id', $v))
            ->selectRaw('m.document documento, m.date fecha, p.code codigo, p.name producto, m.quantity cantidad, m.total_cost costo')->orderBy('m.date');
    }

    private static function costCenterLines(array $f): Builder
    {
        return DB::table('journal_lines as jl')->join('cost_centers as cc', 'cc.id', '=', 'jl.cost_center_id')->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('jl.company_id', $f['cid'])->whereBetween('jl.date', [$f['from'], $f['to']])
            ->when($f['account'], fn ($q, $v) => $q->where('a.code', 'like', "$v%"));
    }

    /** Cartera por edades a la fecha de corte (cuentas por cobrar o por pagar). */
    /** Saldos por tercero en rangos de vencimiento a hoy; Total = vencido + por vencer - saldo a favor (notas y pagos a favor). */
    private static function agingByContact(array $f): Builder
    {
        $purchases = $f['module'] === 'compras';
        $days = 'datediff(curdate(), d.due_date)';
        $sum = fn ($cond) => "round(sum(case when d.balance > 0 and $cond then d.balance else 0 end), 2)";
        $url = $purchases ? '/compras/proveedores/' : '/ventas/clientes/';

        $inner = DB::table(($purchases ? 'purchase_documents' : 'sales_documents').' as d')->where('d.company_id', $f['cid'])
            ->whereIn('d.type', $purchases ? ['FC', 'DS', 'NC', 'ND', 'SI', 'NA'] : ['FV', 'ND', 'SI', 'NC'])->where('d.status', '<>', 'annulled')->where('d.balance', '<>', 0)
            ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
            ->groupBy('d.contact_id')
            ->selectRaw("d.contact_id, {$sum("$days between 1 and 30")} d30, {$sum("$days between 31 and 60")} d60, {$sum("$days between 61 and 90")} d90,
                {$sum("$days > 90")} mas90, {$sum("(d.due_date is null or $days <= 0)")} por_vencer, round(sum(case when d.balance < 0 then -d.balance else 0 end), 2) favor");

        return DB::query()->fromSub($inner, 'b')->join('contacts as c', 'c.id', '=', 'b.contact_id')
            ->selectRaw("c.identification ident, c.name nombre, coalesce(c.branch_office, 0) sucursal, b.d30, b.d60, b.d90, b.mas90, b.por_vencer, b.favor,
                round(b.d30 + b.d60 + b.d90 + b.mas90 + b.por_vencer - b.favor, 2) total, concat('$url', c.id) url")
            ->orderByRaw('cast(c.identification as unsigned)')->orderBy('c.identification')->orderBy('c.branch_office');
    }

    /**
     * Movimientos de cada documento de cartera: el documento que crea el saldo y los recibos y notas que lo abonan,
     * con la cuenta por cobrar o por pagar de cada uno, agrupados por vencimiento.
     */
    private static function dueMovements(array $f): Builder
    {
        $purchases = $f['module'] === 'compras';
        $table = $purchases ? 'purchase_documents' : 'sales_documents';
        $fk = $purchases ? 'purchase_document_id' : 'sales_document_id';
        $like = $purchases ? '2%' : '13%';
        $docUrl = $purchases ? '/documentos/compras/' : '/documentos/ventas/';
        $account = fn ($doc) => "(select l.account_code from ledger_lines l where l.company_id = d.company_id and l.document = $doc and l.account_code like '$like' order by l.id limit 1)";
        // Siigo identifica el vencimiento de una compra con el número del proveedor y no con el comprobante interno.
        $ref = $purchases ? "coalesce(concat(nullif(d.supplier_prefix, ''), '-', nullif(d.supplier_number, '')), d.name)" : 'd.name';
        $pays = ($purchases ? 'purchase' : 'sales').'_document_payments';
        $creditPay = "(select min(p.id) from $pays p join payment_methods pm on pm.id = p.payment_method_id where p.$fk = d.id and pm.due_date = 1)";
        $creditAccount = "(select ac.code from $pays p join payment_methods pm on pm.id = p.payment_method_id join accounts ac on ac.id = pm.account_id where p.id = $creditPay)";
        // En el asiento de Siigo la cuenta por cobrar/pagar va después de un renglón por cada producto, en el orden de las formas de pago.
        $sequence = "(select count(*) from ".($purchases ? 'purchase' : 'sales')."_document_items x where x.$fk = d.id)
            + coalesce(nullif((select count(*) from $pays p where p.$fk = d.id and p.id <= $creditPay), 0), 1)";
        $match = fn ($v) => fn ($o) => $o->where('d.name', $v)->when($purchases, fn ($p) => $p->orWhereRaw("upper($ref) = ?", [$v]));
        $search = null;
        if ($f['q']) {
            // "Comprobante + Consecutivo" puede traer la cuota al final (FV-2-100-1); solo se quita si sin ella no hay coincidencia exacta.
            $search = strtoupper(str_replace(' ', '', $f['q']));
            if (! DB::table("$table as d")->where('d.company_id', $f['cid'])->where($match($search))->exists()) {
                $search = preg_replace('/-\d+$/', '', $search);
            }
        }
        $due = fn ($q) => $q->from("$table as d")->where('d.company_id', $f['cid'])->whereIn('d.type', $purchases ? ['FC', 'DS', 'SI'] : ['FV', 'ND', 'SI'])->where('d.status', '<>', 'annulled')
            ->when($search, fn ($w, $v) => $w->where($match($v)), fn ($w) => $w->where('d.balance', '<>', 0))
            ->when($f['contact'], fn ($w, $v) => $w->where('d.contact_id', $v));
        [$open, $pay] = $purchases ? ['0 debito, d.total credito', 'ri.value debito, 0 credito'] : ['d.total debito, 0 credito', '0 debito, ri.value credito'];
        [$noteOpen, $noteType] = $purchases ? ['n.total debito, 0 credito', ['NC', 'NA']] : ['0 debito, n.total credito', ['NC']];

        $docs = $due(DB::query())->selectRaw("d.name documento, $sequence secuencia, d.date fecha, concat($ref, '-1') detalle, coalesce($creditAccount, {$account('d.name')}) cuenta, $open,
            d.name orden, d.id oid, 0 paso, concat('$docUrl', d.id) url");
        $receipts = $due(DB::query())->join('receipt_items as ri', "ri.$fk", '=', 'd.id')->join('receipts as r', 'r.id', '=', 'ri.receipt_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'ri.account_id')->where('r.status', '<>', 'annulled')
            ->selectRaw("r.name documento, (select count(*) from receipt_items x where x.receipt_id = r.id and x.id <= ri.id) secuencia, r.date fecha,
                concat($ref, '-', coalesce(ri.due_quote, 1)) detalle, coalesce(a.code, $creditAccount, {$account('r.name')}) cuenta, $pay, d.name orden, d.id oid, 1 paso, concat('/documentos/recibos/', r.id) url");
        $notes = $due(DB::query())->join("$table as n", 'n.related_document_id', '=', 'd.id')->whereIn('n.type', $noteType)->where('n.status', '<>', 'annulled')
            ->selectRaw("n.name documento, 1 secuencia, n.date fecha, concat($ref, '-1') detalle, coalesce($creditAccount, {$account('n.name')}) cuenta, $noteOpen, d.name orden, d.id oid, 2 paso, concat('$docUrl', n.id) url");
        $movements = $docs->unionAll($receipts)->unionAll($notes);
        if ($purchases) {
            $debitNotes = $due(DB::query())->join("$table as n", 'n.related_document_id', '=', 'd.id')->where('n.type', 'ND')->where('n.status', '<>', 'annulled')
                ->selectRaw("n.name documento, 1 secuencia, n.date fecha, concat($ref, '-1') detalle, coalesce($creditAccount, {$account('n.name')}) cuenta, 0 debito, n.total credito, d.name orden, d.id oid, 2 paso, concat('$docUrl', n.id) url");
            $movements->unionAll($debitNotes);
        }

        return DB::query()->fromSub($movements, 'm')->orderBy('m.oid')->orderBy('m.paso')->orderBy('m.fecha');
    }

    /** Un renglon por documento con saldo, en los mismos rangos de vencimiento a hoy que agingByContact. */
    private static function agingByDocument(array $f): Builder
    {
        $purchases = $f['module'] === 'compras';
        $days = 'datediff(curdate(), d.due_date)';
        $pos = fn ($cond) => "case when d.balance > 0 and $cond then d.balance else 0 end";
        $url = $purchases ? '/documentos/compras/' : '/documentos/ventas/';

        return DB::table(($purchases ? 'purchase_documents' : 'sales_documents').' as d')->join('contacts as c', 'c.id', '=', 'd.contact_id')
            ->leftJoin('cost_centers as cc', 'cc.id', '=', 'd.cost_center_id')
            ->where('d.company_id', $f['cid'])->whereIn('d.type', $purchases ? ['FC', 'DS', 'NC', 'ND', 'SI', 'NA'] : ['FV', 'ND', 'SI', 'NC'])
            ->where('d.status', '<>', 'annulled')->where('d.balance', '<>', 0)
            ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
            ->when($f['q'], fn ($q, $v) => $q->where(fn ($w) => $w->where('c.name', 'like', "%$v%")->orWhere('c.identification', 'like', "$v%")))
            ->selectRaw("c.identification ident, c.name nombre, ".self::branch('c')." sucursal, d.name documento, d.due_date vence, cc.name centro,
                {$pos("$days between 1 and 30")} d30, {$pos("$days between 31 and 60")} d60, {$pos("$days between 61 and 90")} d90, {$pos("$days > 90")} mas90,
                {$pos("(d.due_date is null or $days <= 0)")} por_vencer, case when d.balance < 0 then -d.balance else 0 end favor, d.balance total, concat('$url', d.id) url,
                concat('".($purchases ? '/compras/proveedores/' : '/ventas/clientes/')."', c.id) contact_url")
            ->orderByRaw('left(c.name, 1), cast(c.identification as unsigned), c.identification, d.due_date, d.id');
    }

    private static function aging(array $f): Builder
    {
        $purchases = $f['module'] === 'compras';
        $table = $purchases ? 'purchase_documents' : 'sales_documents';
        $due = 'datediff(?, coalesce(d.due_date, d.date))';
        $bucket = fn ($cond) => "case when $cond then d.balance else 0 end";

        return DB::table("$table as d")->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $f['cid'])
            ->whereIn('d.type', $purchases ? ['FC', 'DS'] : ['FV'])->where('d.status', '<>', 'annulled')->where('d.balance', '>', 0)->where('d.date', '<=', $f['cutoff'])
            ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))
            ->selectRaw("c.identification ident, c.name tercero, d.name documento, d.date fecha, coalesce(d.due_date, d.date) vence, greatest($due, 0) dias, d.total,
                {$bucket("$due <= 0")} sin_vencer, {$bucket("$due between 1 and 30")} d30, {$bucket("$due between 31 and 60")} d60,
                {$bucket("$due between 61 and 90")} d90, {$bucket("$due > 90")} mas90, d.balance saldo", array_fill(0, 6, $f['cutoff']))
            ->orderBy('c.name')->orderBy('d.date');
    }

    /** Flujo de efectivo por metodo indirecto a partir del libro auxiliar. */
    /** Rubros del flujo de efectivo con las cuentas de la configuración contable estándar. */
    private const CASH_FLOW = [
        'Ajustes para conciliar la utilidad con los flujos netos de efectivo' => [
            'Depreciaciones y amortizaciones' => ['5160', '5165'],
        ],
        'Cambios en activos y pasivos operacionales' => [
            'Activos por impuestos corrientes' => ['1355', '1805'],
            'Beneficios a empleados' => ['2505', '2510'],
            'Cuentas comerciales por cobrar y otros deudores' => ['1305', '1325', '1330', '1365', '1380'],
            'Cuentas comerciales por pagar' => ['2205', '2210', '2299', '2305', '2335', '2370', '2380'],
            'Ingresos recibidos por anticipado' => ['2805', '2815'],
            'Inventarios' => ['1435', '1498'],
            'Otras actividades de operación' => ['1398', '1399'],
            'Pasivos por impuesto corriente' => ['2365', '2367', '2368', '2404', '2408', '2464', '2495'],
        ],
        'Flujo de efectivo en actividades de financiación' => [
            'Aumento /disminución en el patrimonio' => ['3105', '3205', '3305', '3605', '3610', '3705', '3710', '3905'],
            'Obligaciones financieras' => ['2105', '2110'],
        ],
        'Flujo de efectivo en actividades de inversión' => [
            'Intangibles' => ['1635'],
            'Otras inversiones' => ['1205', '1295', '1720', '1945'],
            'Propiedad planta y equipo' => ['1504', '1516', '1524', '1528', '1540', '1592'],
        ],
    ];

    /** Rubros del estado de cambios en el patrimonio con las cuentas de la configuración contable estándar. */
    private const EQUITY = [
        'Capital suscrito y pagado' => ['3105'],
        'Superávit de capital' => ['3205'],
        'Reservas' => ['3305'],
        'Resultado del ejercicio' => ['3605', '3610'],
        'Resultados de años anteriores' => ['3705'],
        'Otras partidas del patrimonio' => ['3710', '3905'],
    ];

    private static function equityChanges(array $f): array
    {
        $start = $f['year'].'-01-01';
        $end = $f['year'].'-12-31';
        $sums = DB::table('ledger_lines')->where('company_id', $f['cid'])->where('account_code', 'like', '3%')->where('date', '<=', $end)
            ->selectRaw('left(account_code, 4) c, sum(case when date < ? then credit - debit else 0 end) inicial, sum(case when date >= ? then credit else 0 end) aumentos,
                sum(case when date >= ? then debit else 0 end) disminuciones', [$start, $start, $start])
            ->groupByRaw('left(account_code, 4)')->get()->keyBy('c');
        $rows = [];
        foreach (self::EQUITY as $rubro => $codes) {
            $pick = fn (string $k) => (float) collect($codes)->sum(fn ($c) => $sums[$c]->$k ?? 0);
            $ini = $pick('inicial');
            $rows[] = ['rubro' => $rubro, 'cuentas' => implode(', ', $codes), 'inicial' => $ini, 'aumentos' => $pick('aumentos'),
                'disminuciones' => $pick('disminuciones'), 'final' => $ini + $pick('aumentos') - $pick('disminuciones')];
        }

        return $rows;
    }

    /** Flujo de efectivo por método indirecto del año y del anterior, a partir del libro auxiliar. */
    private static function cashFlow(array $f): array
    {
        $years = ['actual' => $f['year'], 'anterior' => $f['year'] - 1];
        $sums = [];
        foreach ($years as $k => $y) {
            $sums[$k] = DB::table('ledger_lines')->where('company_id', $f['cid'])->whereBetween('date', ["$y-01-01", "$y-12-31"])
                ->selectRaw('left(account_code, 4) c, sum(debit - credit) v')->groupByRaw('left(account_code, 4)')->pluck('v', 'c')->map(fn ($v) => (float) $v)->all();
            $sums[$k]['_cash_before'] = (float) DB::table('ledger_lines')->where('company_id', $f['cid'])->where('date', '<', "$y-01-01")
                ->where('account_code', 'like', '11%')->sum(DB::raw('debit - credit'));
        }
        $mov = fn (string $k, array $codes) => array_sum(array_map(fn ($c) => $sums[$k][$c] ?? 0, $codes));
        $class = fn (string $k, array $digits) => array_sum(array_filter($sums[$k], fn ($c) => in_array(((string) $c)[0], $digits, true), ARRAY_FILTER_USE_KEY));
        $line = fn (string $label, array $vals, bool $bold = false) => ['concepto' => $label, 'actual' => $vals['actual'] ?? null, 'anterior' => $vals['anterior'] ?? null, 'bold' => $bold];
        $each = fn (callable $fn) => array_map($fn, array_combine(array_keys($years), array_keys($years)));

        $profit = $each(fn ($k) => -$class($k, ['4', '5', '6', '7']));
        $rows = [$line('Utilidad (pérdida) del ejercicio', $profit, true)];
        $net = $profit;
        $operation = $profit;
        foreach (self::CASH_FLOW as $section => $groups) {
            $rows[] = $line(mb_strtoupper($section), [], true);
            $total = $each(fn () => 0.0);
            foreach ($groups as $group => $codes) {
                // Los gastos que no son salida de efectivo se suman; en activos, pasivos y patrimonio el efectivo es el inverso del movimiento débito - crédito.
                $vals = $each(fn ($k) => str_starts_with($codes[0], '5') ? $mov($k, $codes) : -$mov($k, $codes));
                $rows[] = $line($group, $vals);
                $total = $each(fn ($k) => $total[$k] + $vals[$k]);
            }
            $rows[] = $line("Total $section", $total, true);
            $net = $each(fn ($k) => $net[$k] + $total[$k]);
            if (str_starts_with($section, 'Cambios')) {
                $operation = $each(fn ($k) => $net[$k]);
                $rows[] = $line('Flujo neto de efectivo de actividades de operación', $operation, true);
            }
        }
        $opening = $each(fn ($k) => $sums[$k]['_cash_before']);
        $closing = $each(fn ($k) => $opening[$k] + array_sum(array_filter($sums[$k], fn ($c) => str_starts_with((string) $c, '11'), ARRAY_FILTER_USE_KEY)));
        $rows[] = $line('Aumento (disminución) neto del efectivo', $net, true);
        $rows[] = $line('Efectivo y equivalentes al inicio del año', $opening);
        $rows[] = $line('Efectivo y equivalentes al final del año', $closing, true);
        $rows[] = $line('Diferencia por cuentas sin rubro asignado', $each(fn ($k) => $closing[$k] - $opening[$k] - $net[$k]));

        return $rows;
    }

    // ---------------- Ventas ----------------

    private const WITHHOLDING_TYPES = "('Retefuente', 'ReteIVA', 'ReteICA', 'Autorretencion')";

    /** Facturas y notas debito de venta y, si el filtro "Incluye nota credito" esta en Si, notas credito restando. */
    public static function salesDocs(array $f): Builder
    {
        return self::sales([...$f, 'seller' => null], ($f['nc'] ?? true) ? ['FV', 'ND', 'NC'] : ['FV', 'ND'])
            ->when($f['seller'] ?? null, fn ($q, $v) => $q->where('d.seller_id', $v))
            ->when($f['pos'] ?? false, fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** Items de venta con producto y grupo de inventario. */
    private static function salesLines(array $f, string $seller = 'd.seller_id'): Builder
    {
        return self::salesDocs([...$f, 'seller' => null])->join('sales_document_items as i', 'i.sales_document_id', '=', 'd.id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')->leftJoin('account_groups as ag', 'ag.id', '=', 'p.account_group_id')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v))
            ->when($f['group'] ?? null, fn ($q, $v) => $q->where('p.account_group_id', $v))
            ->when($f['seller'] ?? null, fn ($q, $v) => $q->whereRaw("$seller = ?", [$v]));
    }

    private static function salesValueColumns(string $discount): array
    {
        return ['bruto' => ['Valor bruto', 'money'], 'descuento' => [$discount, 'money'], 'subtotal' => ['Subtotal', 'money'],
            'cargo' => ['Impuesto cargo', 'money'], 'retencion' => ['Impuesto retención', 'money']];
    }

    private static function salesDocSums(): string
    {
        $s = self::SIGN;

        return "sum((d.subtotal + d.discount) * $s) bruto, sum(d.discount * $s) descuento, sum(d.subtotal * $s) subtotal, sum(d.tax_total * $s) cargo,
            sum(d.withholding_total * $s) retencion, 0 cargos, 0 descuentos_totales, sum(d.total * $s) total";
    }

    private static function salesItemSums(): string
    {
        $s = self::SIGN;
        $ret = '(select coalesce(sum(t.value), 0) from sales_document_item_taxes t where t.sales_document_item_id = i.id and t.type in '.self::WITHHOLDING_TYPES.')';

        return "sum(i.quantity * $s) cantidad, sum((i.subtotal + i.discount) * $s) bruto, sum(i.discount * $s) descuento, sum(i.subtotal * $s) subtotal,
            sum(i.tax_total * $s) cargo, sum($ret * $s) retencion, sum(i.total * $s) total";
    }

    // ---------------- Movimiento de documentos ----------------

    public const MOVEMENT_KINDS = ['DS' => 'Documento soporte', 'FC' => 'Factura de compra', 'FV' => 'Factura de venta'];

    private const MOVEMENT_LABELS = ['DS' => 'Documento soporte', 'FC' => 'Factura de compra', 'FV' => 'Factura de venta', 'NC' => 'Nota crédito', 'ND' => 'Nota débito', 'NA' => 'Nota de ajuste'];

    private static function movementColumns(bool $withClass): array
    {
        return [
            ...($withClass ? ['clase' => ['Tipo transacción', 'text']] : []),
            'comprobante' => ['Número comprobante', 'text'], 'consecutivo' => ['Consecutivo', 'text'], 'factura_proveedor' => ['Factura proveedor', 'text'],
            ...($withClass ? [] : ['relacionado' => ['Comprobante relacionado', 'text']]),
            'ident' => ['Identificación', 'text'], 'sucursal' => ['Sucursal', 'text'], 'tercero' => ['Nombre tercero', 'text'], 'centro' => ['Centro costo', 'text'],
            'creacion' => ['Fecha creación', 'date'], 'modificacion' => ['Fecha modificación', 'date'], 'fecha' => ['Fecha elaboración', 'date'],
            'contacto' => ['Nombre contacto', 'text'], 'correo' => ['Correo electrónico', 'text'], 'registro' => ['Tipo de registro', 'text'], 'clasificacion' => ['Tipo clasificación', 'text'],
            'codigo' => ['Código', 'text'], 'nombre' => ['Nombre', 'text'], 'referencia' => ['Referencia fábrica', 'text'], 'bodega' => ['Bodega', 'text'],
            'vendedor_ident' => ['Identificación Vendedor', 'text'], 'vendedor' => ['Nombre vendedor', 'text'], 'cantidad' => ['Cantidad', 'qty'], 'unitario' => ['Valor unitario', 'money'],
            'descuento' => ['Valor desc.', 'money'], 'aiu' => ['Base AIU', 'money'], 'cargo1_nombre' => ['Impuesto cargo', 'text'], 'cargo1' => ['Valor Impuesto Cargo', 'money'],
            'cargo2_nombre' => ['Impuesto Cargo 2', 'text'], 'cargo2' => ['Valor Impuesto Cargo 2', 'money'], 'retencion_nombre' => ['Impuesto retención', 'text'],
            'retencion' => ['Valor Impuesto Retención', 'money'], 'base_retencion' => ['Base retención (ICA/IVA)', 'money'], 'cargos' => ['Cargo en totales', 'money'],
            'descuentos_totales' => ['Descuento en totales', 'money'], 'total' => ['Total', 'money'], 'moneda' => ['Moneda', 'text'], 'tasa' => ['Tasa de cambio', 'money'],
            'forma_pago' => ['Forma pago', 'text'], 'vence' => ['Fecha vencimiento', 'date'], 'observaciones' => ['Observaciones', 'text'],
        ];
    }

    /** Lineas de producto (tipo de registro 0) y formas de pago (3) de ventas y compras, ordenadas. */
    private static function movement(array $f, array $types): Builder
    {
        $parts = [];
        $sales = array_values(array_intersect($types, ['FV', 'NC', 'ND']));
        $purchases = array_values(array_intersect($types, ['FC', 'DS', 'NA']));
        foreach (['sales' => $sales, 'purchases' => $purchases] as $module => $list) {
            if (! $list) {
                continue;
            }
            $p = $module === 'sales';
            [$docs, $items, $taxes, $pays, $fk, $tfk] = $p
                ? ['sales_documents', 'sales_document_items', 'sales_document_item_taxes', 'sales_document_payments', 'sales_document_id', 'sales_document_item_id']
                : ['purchase_documents', 'purchase_document_items', 'purchase_document_item_taxes', 'purchase_document_payments', 'purchase_document_id', 'purchase_document_item_id'];
            $base = fn () => DB::table("$docs as d")->join('contacts as c', 'c.id', '=', 'd.contact_id')
                ->leftJoin('document_types as dt', 'dt.id', '=', 'd.document_type_id')->leftJoin('cost_centers as cc', 'cc.id', '=', 'd.cost_center_id')
                ->where('d.company_id', $f['cid'])->whereIn('d.type', $list)->where('d.status', '<>', 'annulled')->whereBetween('d.date', [$f['from'], $f['to']])
                ->when($f['contact'], fn ($q, $v) => $q->where('d.contact_id', $v))->when($f['cost_center'], fn ($q, $v) => $q->where('d.cost_center_id', $v))
                ->when($f['creator'], fn ($q, $v) => $q->where('d.created_by', $v))
                ->when($p && ($f['seller'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('d.seller_id', $f['seller'])
                    ->orWhereExists(fn ($e) => $e->from('sales_document_items as si')->whereColumn('si.sales_document_id', 'd.id')->where('si.seller_id', $f['seller']))))
                ->when(! $p && ($f['seller'] ?? null), fn ($q) => $q->whereRaw('1 = 0'));
            $class = 'case d.type '.implode(' ', array_map(fn ($t) => "when '$t' then '".self::MOVEMENT_LABELS[$t]."'", $list)).' end';
            $siigoType = $p ? "if(d.type = 'ND', 'DF', d.type)" : 'd.type';
            $person = "(select trim(concat(cp.first_name, ' ', coalesce(cp.last_name, ''))) from contact_people cp where cp.contact_id = c.id order by cp.id limit 1)";
            $email = '(select cp.email from contact_people cp where cp.contact_id = c.id order by cp.id limit 1)';
            $common = "$class clase, d.type sort_type, concat($siigoType, '-', coalesce(dt.code, '1')) comprobante, cast(d.number as char) consecutivo,
                ".($p ? 'null' : "nullif(concat_ws('-', d.supplier_prefix, d.supplier_number), '')")." factura_proveedor, d.related_name relacionado,
                c.identification ident, ".self::branch('c')." sucursal, c.name tercero, cc.name centro, d.created_at creacion, null modificacion, d.date fecha,
                coalesce($person, c.name) contacto, coalesce($email, c.email) correo, d.currency moneda, 0 tasa, nullif(d.observations, '') observaciones, d.id doc_id";
            $tax = fn (string $what, bool $ret, int $offset) => "(select t.$what from $taxes t where t.$tfk = i.id and t.type ".($ret ? '' : 'not ').'in '.self::WITHHOLDING_TYPES." order by t.id limit 1 offset $offset)";
            $ret = "coalesce((select sum(t.value) from $taxes t where t.$tfk = i.id and t.type in ".self::WITHHOLDING_TYPES.'), 0)';
            $parts[] = $base()->join("$items as i", "i.$fk", '=', 'd.id')->leftJoin('products as p', 'p.id', '=', 'i.product_id')
                ->leftJoin('warehouses as w', 'w.id', '=', 'i.warehouse_id')
                ->when($p, fn ($q) => $q->leftJoin('sellers as s', 's.id', '=', DB::raw('coalesce(i.seller_id, d.seller_id)')))
                ->selectRaw("$common, 0 registro, ".($p ? "'Producto'" : "case i.item_type when 'Account' then 'Gasto' when 'FixedAsset' then 'Activo fijo' else 'Producto' end")." clasificacion,
                    i.code codigo, coalesce(p.name, i.description) nombre, p.reference referencia, w.name bodega, ".($p ? 's.identification' : 'null').' vendedor_ident, '.($p ? 's.name' : 'null')." vendedor,
                    i.quantity cantidad, i.price unitario, i.discount descuento, 0 aiu, {$tax('name', false, 0)} cargo1_nombre, coalesce({$tax('value', false, 0)}, 0) cargo1,
                    {$tax('name', false, 1)} cargo2_nombre, coalesce({$tax('value', false, 1)}, 0) cargo2, {$tax('name', true, 0)} retencion_nombre, $ret retencion,
                    null base_retencion, 0 cargos, 0 descuentos_totales, ".($p ? 'i.total' : "i.subtotal + i.tax_total - $ret")." total, null forma_pago, d.due_date vence, i.position orden");
            $parts[] = $base()->join("$pays as pp", "pp.$fk", '=', 'd.id')
                ->selectRaw("$common, if(d.type = 'ND' and pp.due_date is not null, 4, 3) registro, null clasificacion, null codigo, null nombre, null referencia, null bodega, null vendedor_ident, null vendedor,
                    null cantidad, null unitario, null descuento, null aiu, null cargo1_nombre, null cargo1, null cargo2_nombre, null cargo2, null retencion_nombre, null retencion,
                    null base_retencion, null cargos, null descuentos_totales, pp.value total, if(d.type = 'ND' and pp.due_date is not null, null, pp.name) forma_pago,
                    pp.due_date vence, 10000 + pp.id orden");
        }
        $union = array_shift($parts);
        foreach ($parts as $q) {
            $union->unionAll($q);
        }

        return DB::query()->fromSub($union, 'm')->select('m.*')
            ->orderByRaw("field(m.sort_type, 'DS', 'FC', 'FV', 'NA', 'NC', 'ND')")->orderBy('m.comprobante')->orderByDesc('m.fecha')->orderBy('m.creacion')->orderByRaw('cast(m.consecutivo as unsigned)')->orderBy('m.doc_id')->orderBy('m.orden');
    }

    /** Nombre del vendedor del comprobante de venta (encabezado o, si no tiene, el del primer ítem). */
    private static function sellerName(string $alias): string
    {
        return "(select s.name from sales_documents d join sellers s on s.id = coalesce(d.seller_id, (select i.seller_id from sales_document_items i where i.sales_document_id = d.id and i.seller_id is not null order by i.position limit 1)) where d.company_id = $alias.company_id and d.name = $alias.document limit 1)";
    }

    /** Identificacion con digito de verificacion (12345678-9). */
    private static function ident(string $alias): string
    {
        return "concat($alias.identification, if($alias.dv is null or $alias.dv = '', '', concat('-', $alias.dv)))";
    }

    private static function branch(string $alias): string
    {
        return "if(coalesce($alias.branch_office, 0) = 0, '-', $alias.branch_office)";
    }

    // ---------------- Utilidades ----------------

    private static function itemSums(): string
    {
        return 'sum(i.quantity * '.self::SIGN.') cantidad, sum(i.subtotal * '.self::SIGN.') subtotal, sum(i.tax_total * '.self::SIGN.') impuestos, sum((i.subtotal + i.tax_total) * '.self::SIGN.') total';
    }

    private static function purchaseItemSums(): string
    {
        $ret = 'coalesce((select sum(t.value) from purchase_document_item_taxes t where t.purchase_document_item_id = i.id and t.type in '.self::WITHHOLDING_TYPES.'), 0)';

        return 'sum(i.quantity * '.self::PSIGN.') cantidad, sum((i.subtotal + i.discount) * '.self::PSIGN.') bruto, sum(i.discount * '.self::PSIGN.') descuento, '
            .'sum(i.subtotal * '.self::PSIGN.') subtotal, sum(i.tax_total * '.self::PSIGN.') impuestos, sum('.$ret.' * '.self::PSIGN.') retenciones, '
            .'sum((i.subtotal + i.tax_total - '.$ret.') * '.self::PSIGN.') total';
    }

    private static function purchaseDocSums(): string
    {
        return 'sum((d.subtotal + d.discount) * '.self::PSIGN.') bruto, sum(d.discount * '.self::PSIGN.') descuento, sum(d.subtotal * '.self::PSIGN.') subtotal, '
            .'sum(d.tax_total * '.self::PSIGN.') impuestos, sum(d.withholding_total * '.self::PSIGN.') retenciones, sum(d.total * '.self::PSIGN.') total';
    }

    private static function purchaseValueColumns(): array
    {
        return ['bruto' => ['Valor bruto', 'money'], 'descuento' => ['Descuento', 'money'], 'subtotal' => ['Subtotal', 'money'],
            'impuestos' => ['Impuesto cargo', 'money'], 'retenciones' => ['Impuesto retención', 'money'], 'total' => ['Total', 'money']];
    }

    /** Compras de Siigo: FC y DS, mas las notas (devoluciones) cuando el filtro de notas esta activo. */
    private static function purchaseTypes(array $f): array
    {
        return $f['nc'] ? ['FC', 'DS', 'NC', 'ND', 'NA'] : ['FC', 'DS'];
    }

    private static function purchaseProductLines(array $f): Builder
    {
        return self::purchases($f, self::purchaseTypes($f))->join('purchase_document_items as i', 'i.purchase_document_id', '=', 'd.id')
            ->join('products as p', 'p.id', '=', 'i.product_id')->leftJoin('account_groups as g', 'g.id', '=', 'p.account_group_id')
            ->where('i.item_type', 'Product')
            ->when($f['product'] ?? null, fn ($q, $v) => $q->where('i.product_id', $v))
            ->when($f['group'] ?? null, fn ($q, $v) => $q->where('p.account_group_id', $v));
    }

    /** Comprobantes de nómina del rango por mes (se filtra por periodo, no por día). */
    private static function payrollDocs(array $f)
    {
        return DB::table('payroll_documents as pd')->join('employees as e', 'e.id', '=', 'pd.employee_id')->join('contacts as c', 'c.id', '=', 'e.contact_id')
            ->leftJoin('cost_centers as cc', 'cc.id', '=', 'e.cost_center_id')->where('pd.company_id', $f['cid'])
            ->whereBetween('pd.period', [substr($f['from'], 0, 7), substr($f['to'], 0, 7)])
            ->when($f['contact'], fn ($q, $v) => $q->where('e.contact_id', $v))->when($f['cost_center'], fn ($q, $v) => $q->where('e.cost_center_id', $v));
    }

    private static function payrollItem(string|array $concepts, string $field = 'value', string $agg = 'sum'): string
    {
        $in = implode(', ', array_map(fn ($c) => "'$c'", (array) $concepts));

        return "coalesce((select $agg(x.$field) from payroll_items x where x.payroll_document_id = pd.id and x.concept in ($in)), 0)";
    }

    private static function docSums(): string
    {
        return 'sum(d.subtotal * '.self::SIGN.') subtotal, sum(d.tax_total * '.self::SIGN.') impuestos, sum(d.total * '.self::SIGN.') total';
    }

    private static function monthColumns(): array
    {
        $cols = [];
        foreach (self::MONTHS as $i => $m) {
            $cols['m'.($i + 1)] = [$m, 'money'];
        }

        return [...$cols, 'total' => ['Total', 'money']];
    }

    /** Los comparativos suman facturas y notas débito, y opcionalmente restan las notas crédito. */
    private static function comparativeTypes(array $f): array
    {
        return $f['nc'] ? ['FV', 'ND', 'NC'] : ['FV', 'ND'];
    }

    private static function comparativeValue(array $f, string $alias): string
    {
        return ($f['tax'] ? "$alias.total" : "$alias.subtotal").' * '.self::SIGN;
    }

    private static function monthSums(string $expr, string $date = 'd.date'): string
    {
        $parts = [];
        for ($m = 1; $m <= 12; $m++) {
            $parts[] = "sum(case when month($date) = $m then $expr else 0 end) m$m";
        }

        return implode(', ', $parts).", sum($expr) total";
    }

    private static function levelLength(int $level): int
    {
        return [1 => 1, 2 => 2, 3 => 4, 4 => 6, 5 => 8][$level] ?? 4;
    }
}
