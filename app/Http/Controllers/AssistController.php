<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Barra superior: buscador global, notificaciones del negocio y centro de ayuda. */
class AssistController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $kind = (string) $request->input('tipo', 'Todos');
        if ($kind !== 'Todos') {
            return response()->json($this->searchKind($request, $kind, $q));
        }
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $c = CurrentCompany::id();
        $like = "%$q%";
        $user = $request->user();
        $out = [];

        if ($user->can('terceros.ver')) {
            foreach (DB::table('contacts')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('identification', 'like', "$q%"))
                ->orderBy('name')->limit(6)->get(['id', 'name', 'identification']) as $r) {
                $out[] = ['group' => 'Terceros', 'label' => $r->name, 'sub' => $r->identification, 'href' => "/terceros/{$r->id}/editar"];
            }
        }
        if ($user->can('inventario.ver')) {
            foreach (DB::table('products')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', "$q%")->orWhere('barcode', $q))
                ->orderBy('name')->limit(6)->get(['id', 'code', 'name', 'stock']) as $r) {
                $out[] = ['group' => 'Productos', 'label' => $r->name, 'sub' => "{$r->code} · saldo ".rtrim(rtrim(number_format((float) $r->stock, 2, ',', '.'), '0'), ','), 'href' => "/productos/{$r->id}/editar"];
            }
        }
        $docName = strtoupper(str_replace(' ', '', $q));
        if (preg_match('/^[A-Z]{1,3}-?\d/', $docName)) {
            $sources = [
                ['sales_documents', 'ventas', 'ventas.ver'], ['purchase_documents', 'compras', 'compras.ver'],
                ['receipts', 'recibos', 'ventas.ver'], ['journal_entries', 'comprobantes', 'contabilidad.ver'],
            ];
            foreach ($sources as [$table, $kind, $perm]) {
                if (! $user->can($perm)) {
                    continue;
                }
                foreach (DB::table($table)->where('company_id', $c)->where('name', 'like', "$docName%")->orderByDesc('date')->limit(5)->get(['id', 'name', 'date']) as $r) {
                    $out[] = ['group' => 'Documentos', 'label' => $r->name, 'sub' => substr((string) $r->date, 0, 10), 'href' => "/documentos/$kind/{$r->id}"];
                }
            }
        }
        if ($user->can('contabilidad.ver') && preg_match('/^\d{1,10}$/', $q)) {
            foreach (DB::table('accounts')->where('company_id', $c)->where('code', 'like', "$q%")->orderBy('code')->limit(5)->get(['id', 'code', 'name']) as $r) {
                $out[] = ['group' => 'Cuentas contables', 'label' => "{$r->code} {$r->name}", 'sub' => '', 'href' => "/contabilidad/libro-auxiliar?cuenta_desde={$r->code}&cuenta_hasta={$r->code}"];
            }
        }
        foreach (collect(config('erp.create_menu'))->flatten(1)->merge(collect(config('erp.tab_links'))->map(fn ($u, $k) => ['label' => str_replace('.', ' › ', $k), 'url' => $u])->values())
            ->filter(fn ($i) => mb_stripos($i['label'], $q) !== false)->take(5) as $i) {
            $out[] = ['group' => 'Pantallas', 'label' => $i['label'], 'sub' => $i['url'], 'href' => $i['url']];
        }

        return response()->json($out);
    }

    /** Documentos comerciales del buscador por categoria: tabla, kind de /documentos/{kind}/{id}, permiso y tipos. */
    private const DOCUMENT_KINDS = [
        'Facturas' => ['sales_documents', 'ventas', 'ventas.ver', ['FV']],
        'Factura de Compra / Gasto' => ['purchase_documents', 'compras', 'compras.ver', ['FC']],
        'Documento soporte' => ['purchase_documents', 'compras', 'compras.ver', ['DS']],
        'Orden de Compra' => ['purchase_documents', 'compras', 'compras.ver', ['OC']],
        'Cotizaciones' => ['sales_documents', 'ventas', 'ventas.ver', ['CT']],
        'Recibos de caja' => ['receipts', 'recibos', 'ventas.ver', ['RC']],
        'Remisiones' => ['sales_documents', 'ventas', 'ventas.ver', ['RM']],
        'Recibo de Pago / Egreso' => ['receipts', 'recibos', 'compras.ver', ['RP']],
        'Notas crédito' => ['sales_documents', 'ventas', 'ventas.ver', ['NC']],
        'Nota débito' => ['purchase_documents', 'compras', 'compras.ver', ['ND', 'NA']],
        'Nota débito (Ventas)' => ['sales_documents', 'ventas', 'ventas.ver', ['ND']],
    ];

    /** Comprobantes del libro: permiso, source_type del ERP y codigo del tipo CC con que llegan de Siigo. */
    private const JOURNAL_KINDS = [
        'Comprobantes contables' => ['contabilidad.ver', [], null],
        'Ajuste de cartera / proveedores' => ['contabilidad.ver', ['portfolio_adjustment'], '777'],
        'Ajuste / Saldo inicial de inventario' => ['inventario.ver', ['inventory_ajuste', 'inventory_conteo', 'inventory_saldo'], null],
        'Nota de traslado entre bodegas' => ['inventario.ver', ['inventory_traslado'], '10'],
        'Notas de ensamble' => ['inventario.ver', ['inventory_ensamble'], null],
        'Traslado de dinero' => ['bancos.ver', ['money_transfer'], '9901'],
    ];

    /** @return array{columns: list<string>, rows: list<array{cells: list<string>, href: ?string}>, more: bool} */
    private function searchKind(Request $request, string $kind, string $q): array
    {
        $c = CurrentCompany::id();
        $user = $request->user();
        $limit = min(max($request->integer('limite', 9), 1), 200);
        $like = "%$q%";
        $docLike = '%'.strtoupper(str_replace(' ', '', $q)).'%';
        $contactIds = fn () => DB::table('contacts')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('identification', 'like', "$q%"))->select('id');

        [$columns, $perm, $query, $row] = match (true) {
            isset(self::DOCUMENT_KINDS[$kind]) => (function () use ($kind, $c, $docLike, $contactIds) {
                [$table, $docKind, $perm, $types] = self::DOCUMENT_KINDS[$kind];
                $query = DB::table("$table as d")->leftJoin('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $c)->whereIn('d.type', $types)
                    ->where(fn ($w) => $w->where('d.name', 'like', $docLike)->orWhereIn('d.contact_id', $contactIds()))
                    ->orderByRaw('d.name like ? desc', [$docLike])->orderBy('d.number')->orderBy('d.name')->select('d.id', 'd.name', 'c.identification');

                return [['Comprobante', 'Identificación'], $perm, $query, fn ($r) => [[$r->name, (string) $r->identification], "/documentos/$docKind/{$r->id}"]];
            })(),
            isset(self::JOURNAL_KINDS[$kind]) => (function () use ($kind, $c, $docLike, $contactIds) {
                [$perm, $sources, $code] = self::JOURNAL_KINDS[$kind];
                $identification = DB::table('journal_lines as l')->join('contacts as lc', 'lc.id', '=', 'l.contact_id')->whereColumn('l.journal_entry_id', 'j.id')->limit(1)->select('lc.identification');
                $query = DB::table('journal_entries as j')->leftJoin('document_types as t', 't.id', '=', 'j.document_type_id')->where('j.company_id', $c)
                    ->when($sources, fn ($w) => $w->where(fn ($s) => $s->whereIn('j.source_type', $sources)
                        ->when($code, fn ($o) => $o->orWhere(fn ($i) => $i->whereNull('j.source_type')->where('t.type', 'CC')->where('t.code', $code)))
                        ->when($kind === 'Notas de ensamble', fn ($o) => $o->orWhere('t.type', 'NE'))))
                    ->where(fn ($w) => $w->where('j.name', 'like', $docLike)->orWhereIn('j.id', DB::table('journal_lines')->whereIn('contact_id', $contactIds())->select('journal_entry_id')))
                    ->orderByRaw('j.name like ? desc', [$docLike])->orderBy('j.number')->orderBy('j.name')->select('j.id', 'j.name')->selectSub($identification, 'identification');

                return [['Comprobante', 'Identificación'], $perm, $query, fn ($r) => [[$r->name, (string) $r->identification], "/documentos/comprobantes/{$r->id}"]];
            })(),
            $kind === 'Terceros' => [['Nombre', 'Identificación'], 'terceros.ver',
                DB::table('contacts')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('identification', 'like', "$q%"))->orderBy('name')->select('id', 'name', 'identification'),
                fn ($r) => [[$r->name, (string) $r->identification], "/terceros/{$r->id}"]],
            $kind === 'Productos / Servicios' => [['Código', 'Nombre'], 'inventario.ver',
                DB::table('products')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', "$q%")->orWhere('barcode', $q))->orderBy('name')->select('id', 'code', 'name'),
                fn ($r) => [[$r->code, $r->name], "/productos/{$r->id}"]],
            $kind === 'Activos fijos' => [['Código', 'Nombre'], 'contabilidad.ver',
                DB::table('fixed_assets')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', "$q%"))->orderBy('code')->select('id', 'code', 'name'),
                fn ($r) => [[$r->code, $r->name], null]],
            $kind === 'Empleados' => [['Nombre', 'Identificación'], 'nomina.ver',
                DB::table('employees as e')->join('contacts as c', 'c.id', '=', 'e.contact_id')->where('e.company_id', $c)
                    ->where(fn ($w) => $w->where('c.name', 'like', $like)->orWhere('c.identification', 'like', "$q%"))->orderBy('c.name')->select('e.id', 'c.name', 'c.identification'),
                fn ($r) => [[$r->name, (string) $r->identification], "/nomina/empleados/{$r->id}/editar"]],
            $kind === 'Usuarios' => [['Nombre', 'Correo'], 'configuracion.usuarios',
                DB::table('users')->where('company_id', $c)->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('identification', 'like', "$q%"))->orderBy('name')->select('id', 'name', 'email'),
                fn ($r) => [[$r->name, (string) $r->email], "/configuracion/usuarios/{$r->id}/editar"]],
            $kind === 'Tareas' => [['Tarea', 'Tercero', 'Fecha'], 'ventas.ver',
                DB::table('crm_tasks as k')->leftJoin('contacts as c', 'c.id', '=', 'k.contact_id')->where('k.company_id', $c)
                    ->where(fn ($w) => $w->where('k.description', 'like', $like)->orWhere('k.category', 'like', $like)->orWhere('c.name', 'like', $like)->orWhere('c.identification', 'like', "$q%"))
                    ->orderByDesc('k.due_date')->select('k.id', 'k.description', 'k.category', 'k.due_date', 'c.name'),
                fn ($r) => [[(string) ($r->description ?: $r->category), (string) $r->name, substr((string) $r->due_date, 0, 10)], "/ventas/seguimiento-comercial/{$r->id}/editar"]],
            $kind === 'Documentos' => [['Nombre', 'Fecha'], null,
                DB::table('attachments')->where('company_id', $c)->where('name', 'like', $like)->orderByDesc('created_at')->select('id', 'name', 'created_at'),
                fn ($r) => [[$r->name, substr((string) $r->created_at, 0, 10)], "/adjuntos/{$r->id}"]],
            default => [[], false, null, null],
        };

        if (! $query || mb_strlen($q) < 1 || ($perm && ! $user->can($perm))) {
            return ['columns' => $columns, 'rows' => [], 'more' => false];
        }
        $found = $query->limit($limit + 1)->get();

        return [
            'columns' => $columns,
            'rows' => $found->take($limit)->map(function ($r) use ($row) {
                [$cells, $href] = $row($r);

                return ['cells' => $cells, 'href' => $href];
            })->values()->all(),
            'more' => $found->count() > $limit,
        ];
    }

    /** Alertas calculadas al momento con los datos de la empresa. */
    public function notifications(Request $request): JsonResponse
    {
        $c = CurrentCompany::id();
        $today = now()->toDateString();
        $user = $request->user();
        $money = fn ($v) => '$ '.number_format((float) $v, 0, ',', '.');
        $items = [];
        $add = function (bool $show, string $icon, string $level, string $title, string $text, string $href) use (&$items) {
            if ($show) {
                $items[] = compact('icon', 'level', 'title', 'text', 'href');
            }
        };

        if ($user->can('ventas.ver')) {
            $r = DB::table('sales_documents')->where('company_id', $c)->where('type', 'FV')->where('status', '!=', 'annulled')
                ->where('balance', '>', 0)->where('due_date', '<', $today)->selectRaw('count(*) n, COALESCE(sum(balance),0) v')->first();
            $add($r->n > 0, 'file-invoice-dollar', 'warn', 'Cartera vencida', "{$r->n} facturas vencidas por cobrar ({$money($r->v)})", '/ventas/clientes');
            $rej = DB::table('sales_documents')->where('company_id', $c)->where('dian_status', 'Rejected')->where('status', '!=', 'annulled')->count();
            $add($rej > 0, 'triangle-exclamation', 'danger', 'Documentos rechazados por la DIAN', "$rej documentos electrónicos rechazados", '/ventas');
            $tasks = DB::table('crm_tasks')->where('company_id', $c)->where('status', 'Pendiente')->where('due_date', '<', $today)
                ->where(fn ($w) => $w->whereNull('assigned_to')->orWhere('assigned_to', $user->id))->count();
            $add($tasks > 0, 'list-check', 'warn', 'Tareas comerciales vencidas', "$tasks tareas con fecha de compromiso vencida", '/ventas/seguimiento-comercial');
            $rec = DB::table('recurring_invoices')->where('company_id', $c)->where('active', true)->whereNotNull('last_error')->count();
            $add($rec > 0, 'triangle-exclamation', 'danger', 'Facturas recurrentes con error', "$rec facturas recurrentes no se pudieron generar", '/ventas/facturas-recurrentes');
        }
        if ($user->can('compras.ver')) {
            $r = DB::table('purchase_documents')->where('company_id', $c)->whereIn('type', ['FC', 'DS'])->where('status', '!=', 'annulled')
                ->where('balance', '>', 0)->where('due_date', '<', $today)->selectRaw('count(*) n, COALESCE(sum(balance),0) v')->first();
            $add($r->n > 0, 'cart-flatbed', 'warn', 'Cuentas por pagar vencidas', "{$r->n} facturas de proveedor vencidas ({$money($r->v)})", '/compras/proveedores');
            $soon = DB::table('purchase_documents')->where('company_id', $c)->whereIn('type', ['FC', 'DS'])->where('status', '!=', 'annulled')
                ->where('balance', '>', 0)->whereBetween('due_date', [$today, now()->addDays(7)->toDateString()])->count();
            $add($soon > 0, 'calendar-days', 'info', 'Pagos próximos', "$soon facturas de proveedor vencen en los próximos 7 días", '/compras/proveedores');
        }
        if ($user->can('inventario.ver')) {
            $low = DB::table('products')->where('company_id', $c)->where('active', true)->where('stock_control', true)->where('min_stock', '>', 0)
                ->whereColumn('stock', '<', 'min_stock')->count();
            $add($low > 0, 'warehouse', 'warn', 'Inventario bajo el mínimo', "$low productos por debajo de su stock mínimo", '/productos?bajo_minimo=1');
            $neg = DB::table('products')->where('company_id', $c)->where('active', true)->where('stock_control', true)->where('stock', '<', 0)->count();
            $add($neg > 0, 'triangle-exclamation', 'danger', 'Inventario negativo', "$neg productos con saldo negativo", '/productos?saldo=negativo&estado=activos');
        }
        if ($user->can('nomina.ver')) {
            $draft = DB::table('payroll_runs')->where('company_id', $c)->where('source', 'erp')->where('status', 'draft')->count();
            $add($draft > 0, 'user-tie', 'info', 'Nóminas en borrador', "$draft nóminas sin contabilizar", '/nomina');
            $unpaid = DB::table('payroll_runs')->where('company_id', $c)->where('source', 'erp')->where('status', 'posted')->whereNull('payment_document')->count();
            $add($unpaid > 0, 'money-bill', 'warn', 'Nóminas sin pagar', "$unpaid nóminas contabilizadas sin comprobante de pago", '/nomina');
        }
        if ($user->can('bancos.ver')) {
            $pending = DB::table('bank_statement_lines as s')->join('bank_accounts as b', 'b.id', '=', 's.bank_account_id')
                ->where('b.company_id', $c)->whereNull('s.ledger_line_id')->count();
            $add($pending > 0, 'scale-balanced', 'info', 'Conciliación bancaria', "$pending movimientos del extracto sin conciliar", '/bancos/conciliacion');
        }

        return response()->json($items);
    }

    public function help(): Response
    {
        return Inertia::render('Help', ['sections' => config('help')]);
    }
}
