<?php

namespace App\Http\Controllers;

use App\Models\InventoryCosting;
use App\Models\SalesDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestanas que son listados sencillos (muchos vacios en la empresa): cabecera, filtros y columnas.
 * Las que tienen datos propios usan un "source".
 */
class ModuleViewController extends Controller
{
    private const VIEWS = [
        'ventas/cotizaciones' => [
            'title' => 'Ventas', 'module' => 'ventas', 'tab' => 'Cotizaciones', 'search_label' => 'Usuario', 'search' => 'Usuario',
            'this_month' => true,
            'buttons' => [
                ['label' => 'Descargar', 'href' => '/ventas/cotizaciones/excel', 'style' => 'outline', 'icon' => 'download', 'external' => true],
                ['label' => 'Crear cotización', 'href' => '/ventas/cotizaciones/crear'],
            ],
            'selects' => [
                ['name' => 'estado', 'label' => 'Estado', 'multiple' => true, 'options' => ['Pendientes', 'Aprobada', 'Descartada', 'Sin correo', 'Correo pendiente', 'Correo enviado', 'Fallido', 'Correo bloqueado']],
                ['name' => 'remisionada', 'label' => 'Remisionada', 'options' => ['Sí', 'No']],
                ['name' => 'facturada', 'label' => 'Facturada', 'options' => ['Sí', 'No']],
            ],
            'columns' => ['Comprobante', 'Fecha elaboración', 'Identificación', 'Sucursal', 'Cliente', 'Estado', 'Remisionada', 'Facturada', 'Total', 'Moneda'],
            'empty' => ['No se encontraron resultados para tu búsqueda.', 'Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo.'], 'clear_url' => '/ventas/cotizaciones',
            'source' => 'quotes',
        ],
        'ventas/remisiones' => [
            'title' => 'Ventas', 'module' => 'ventas', 'tab' => 'Remisiones', 'search_label' => 'Cliente', 'classic' => true,
            'columns' => ['Tipo de transacción', 'Comprobante', 'Fecha elaboración', 'Identificación', 'Sucursal', 'Cliente', 'Estado envío de correo', 'Total'],
            'source' => 'remissions',
        ],
        'productos/documentos' => [
            'title' => 'Productos y servicios', 'module' => 'productos', 'tab' => 'Documentos de productos / servicios', 'heading' => 'Inventarios',
            'search_label' => 'Comprobante', 'search' => 'Buscar por comprobante u observación', 'dates' => true, 'default_days' => 30,
            'buttons' => [
                ['label' => 'Importar', 'style' => 'outline', 'menu' => [
                    ['label' => 'Importar comprobantes de ajuste', 'href' => '/inventario/ajustes/crear?importar=1'],
                    ['label' => 'Importar saldos iniciales de inventario', 'href' => '/contabilidad/saldos-iniciales/inventario'],
                    ['label' => 'Importar conteo físico', 'href' => '/inventario/conteos/crear?importar=1'],
                ]],
                ['label' => 'Nuevo documento', 'menu' => [
                    ['label' => 'Ajuste de inventario', 'href' => '/inventario/ajustes/crear'], ['label' => 'Ensamble de producto', 'href' => '/inventario/ensambles/crear'],
                    ['label' => 'Conteo físico / Ajustes', 'href' => '/inventario/conteos/crear'], ['label' => 'Remisión', 'href' => '/ventas/remisiones/crear'],
                    ['label' => 'Traslado entre bodegas', 'href' => '/inventario/traslados/crear'],
                ]],
                ['label' => 'Descargar', 'icon' => 'download', 'style' => 'outline', 'download' => '/productos/documentos/excel'],
            ],
            'columns' => ['Tipo transacción', 'Comprobante', 'Fecha elaboración', 'Identificación', 'Sucursal', 'Cliente', 'Total'],
            'empty' => ['No se encontraron resultados para tu búsqueda.', 'Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo.'],
            'source' => 'inventoryDocs',
        ],
        'productos/costeo' => [
            'title' => 'Productos y servicios', 'module' => 'productos', 'tab' => 'Costeo mensual',
            'info' => 'Para garantizar que el costo de ventas sea preciso, registra todos los documentos que impacten el inventario, como facturas de venta y compra, ensambles, conteos físicos y ajustes de inventario.',
            'year' => true, 'buttons' => [['label' => 'Crear documento', 'menu' => [
                ['label' => 'Ajuste de inventario', 'href' => '/inventario/ajustes/crear'], ['label' => 'Ensamble de producto', 'href' => '/inventario/ensambles/crear'],
                ['label' => 'Conteo físico / Ajustes', 'href' => '/inventario/conteos/crear'], ['label' => 'Factura de venta', 'href' => '/ventas/facturas/crear'], ['label' => 'Factura de compra', 'href' => '/compras/facturas/crear'],
            ]]],
            'columns' => ['Mes', 'Ultima ejecución', 'Estado', 'Acciones'],
            'source' => 'costing',
        ],
    ];

    /** Pestanas "Mas procesos": tarjetas con los procesos del modulo (url = pantalla del ERP cuando existe). */
    private const PROCESSES = [
        'nomina/mas-procesos' => ['title' => 'Nómina', 'module' => 'nomina', 'tab' => 'Más procesos', 'cards' => [
            ['Planilla pago seguridad social', [['Liquidación planilla integrada de aportes', '/nomina/pila']]],
            ['Históricos', [['Libro de vacaciones', '/nomina/vacaciones']]],
            ['Otros procesos', [['Liquidación de contrato', '/nomina/crear?tipo=liquidacion'], ['Liquidación de prima', '/nomina/crear?tipo=prima'],
                ['Cesantías anuales', '/nomina/crear?tipo=cesantias'], ['Generar certificado de ingresos y retenciones / Formulario 220', '/nomina/certificado-ingresos']]],
        ]],
        'contabilidad/mas-procesos' => ['title' => 'Contabilidad', 'module' => 'contabilidad', 'tab' => 'Más procesos', 'cards' => [
            ['Contables', [['Diferencia en cambio', '/contabilidad/diferencia-cambio'], ['Cierre de año', '/contabilidad/cierre-anio'], ['Cierre cuentas de impuestos y otras cuentas por tercero', '/contabilidad/cierre-impuestos'],
                ['Verifica y bloquea documentos', '/contabilidad/verifica-documentos'], ['Copias de comprobantes favoritos', '/contabilidad/comprobantes-favoritos'], ['Borrado masivo de comprobantes', '/contabilidad/borrado-masivo']]],
            ['Medios Magnéticos', [['Asistente de medios magnéticos', '/contabilidad/medios-magneticos'], ['Informe auxiliar medios magnéticos distritales y/o municipales', '/contabilidad/medios-municipales']]],
            ['Activos fijos', [['Creación de activo fijo', '/configuracion/activos-fijos/crear'], ['Importar activo fijo', '/contabilidad/activos-fijos/importar']]],
            ['Certificados', [['Generar certificado de retención a título de renta, IVA e ICA', '/contabilidad/certificados-retencion'], ['Generar certificado de ingresos y retenciones / Formulario 220', '/nomina/certificado-ingresos']]],
            ['Saldos Iniciales', [['Saldos iniciales de cuentas por cobrar', '/contabilidad/saldos-iniciales/cuentas-por-cobrar'], ['Saldos iniciales de cuentas por pagar', '/contabilidad/saldos-iniciales/cuentas-por-pagar'], ['Saldos iniciales de inventario', '/contabilidad/saldos-iniciales/inventario']]],
            ['Saldos iniciales otras cuentas', [['Saldos iniciales de otras cuentas contables', '/contabilidad/saldos-iniciales/otras-cuentas']]],
        ]],
    ];

    public function processes(Request $request): Response
    {
        $view = self::PROCESSES[trim($request->route()->uri(), '/')];

        return Inertia::render('ProcessMenu', [
            'title' => $view['title'],
            'active' => $view['tab'],
            'tabs' => DashboardController::tabs($view['module']),
            'cards' => collect($view['cards'])->map(fn ($c) => ['title' => $c[0], 'items' => collect($c[1])->map(fn ($i) => ['label' => $i[0], 'url' => $i[1]])->all()])->all(),
        ]);
    }

    public static function processRoutes(): array
    {
        return array_keys(self::PROCESSES);
    }

    public function show(Request $request): Response
    {
        $key = trim($request->route()->uri(), '/');
        if (in_array($key, ['productos/costeo', 'productos/documentos'], true) && ! $request->user()?->can('inventario.ver_costos')) {
            abort(403);
        }
        $view = self::VIEWS[$key] ?? abort(404);
        if (isset($view['default_days']) && ! $request->hasAny(['desde', 'hasta', 'q', 'page', 'todos'])) {
            $request->merge(['desde' => now()->subDays($view['default_days'])->toDateString()]);
            $view['chips'] = [['text' => "Fecha elaboración: Últimos días: {$view['default_days']}", 'clear' => "/$key?todos=1"]];
        }
        if (($view['this_month'] ?? false) && ! $request->hasAny(['desde', 'hasta', 'q', 'page', 'todos'])) {
            $request->merge(['desde' => now()->startOfMonth()->toDateString(), 'hasta' => now()->endOfMonth()->toDateString(), 'q' => $request->user()?->name]);
        }
        if (($view['this_month'] ?? false) && $request->filled('desde') && $request->input('desde') === now()->startOfMonth()->toDateString() && $request->input('hasta') === now()->endOfMonth()->toDateString()) {
            $view['chips'] = [['text' => 'Fecha elaboración: Este mes', 'clear' => "/$key?todos=1"]];
        }
        if (($view['classic'] ?? false) && ! $request->hasAny(['desde', 'hasta', 'q', 'page', 'todos', 'usuario'])) {
            $request->merge(['desde' => now()->subDays(14)->toDateString(), 'hasta' => now()->toDateString(), 'usuario' => $request->user()?->name, 'rango' => 'Últimos 15 días']);
        }
        if (($view['classic'] ?? false) && $request->boolean('todos')) {
            $request->merge(['desde' => now()->subDays(14)->toDateString(), 'hasta' => now()->toDateString(), 'rango' => 'Últimos 15 días']);
        }
        $rows = isset($view['source']) ? $this->{$view['source']}($request) : [];

        return Inertia::render('GenericList', [
            'view' => collect($view)->except(['source', 'default_days', 'this_month'])->all() + ['url' => '/'.$key],
            'rows' => $rows,
            'tabs' => DashboardController::tabs($view['module'], $view['tab']),
            'filters' => $request->only(['q', 'desde', 'hasta', 'sub', 'anio', 'usuario', 'rango', ...collect($view['selects'] ?? [])->pluck('name')->all()]),
        ]);
    }

    public static function routes(): array
    {
        return array_keys(self::VIEWS);
    }

    /** Descarga en Excel de un listado generico con los filtros aplicados. */
    public function excel(Request $request)
    {
        $key = preg_replace('#/excel$#', '', trim($request->route()->uri(), '/'));
        $view = self::VIEWS[$key] ?? abort(404);
        $request->merge(['per_page' => 5000]);
        $rows = $this->{$view['source']}($request);
        $rows = collect(is_array($rows) ? $rows : $rows->items())->map(fn ($r) => collect($r)->map(fn ($c) => is_array($c) ? ($c['text'] ?? '') : $c)->all());

        return \App\Support\Download::array(str_replace('/', '_', $key).'.xlsx', $view['columns'], $rows->all(), $view['heading'] ?? $view['title']);
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', ',');
    }

    public function supportsPage(Request $request): Response
    {
        if (! $request->hasAny(['desde', 'hasta', 'q', 'estado', 'page', 'rango'])) {
            $request->merge(['desde' => now()->subDays(29)->toDateString(), 'hasta' => now()->toDateString(), 'rango' => 'd30']);
        }

        return Inertia::render('Purchases/Supports', [
            'rows' => $this->supports($request),
            'tabs' => DashboardController::tabs('compras', 'Soporte por legalizar'),
            'filters' => $request->only(['q', 'desde', 'hasta', 'estado', 'rango']),
        ]);
    }

    public function supportsExcel(Request $request)
    {
        $request->merge(['per_page' => 5000]);
        $rows = collect($this->supports($request)->items())->map(fn ($r) => collect($r)->take(5)->map(fn ($c) => is_array($c) ? ($c['text'] ?? '') : $c)->all());

        return \App\Support\Download::array('soporte_por_legalizar.xlsx', ['Documento soporte', 'Fecha creación', 'Nombre Comprobante', 'Número de comprobante', 'Estado'], $rows->all(), 'Soporte de compras o gastos por legalizar');
    }

    private function supports(Request $request)
    {
        return \App\Models\ExpenseSupport::query()
            ->when($request->input('q'), fn ($q, $t) => $q->where('name', 'like', "%$t%"))
            ->when($request->input('estado'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('id')->paginate($request->integer('per_page', 20))->withQueryString()
            ->through(function (\App\Models\ExpenseSupport $s) {
                $doc = $s->purchase_document_id ? \App\Models\PurchaseDocument::query()->find($s->purchase_document_id) : null;
                $type = $doc ? \App\Models\DocumentType::query()->find($doc->document_type_id) : null;

                return [
                    ['text' => $s->name, 'download' => "/compras/soporte-legalizar/{$s->id}"],
                    $s->created_at->format('d/m/Y'),
                    $doc ? ($type?->name ?? $doc->type) : '',
                    $doc ? ['text' => $doc->name, 'href' => \App\Services\Documents\DocumentLocator::url($doc)] : '',
                    ['text' => $s->status, 'badge' => $doc ? 'ok' : 'warn'],
                    ['actions' => $doc ? [] : [
                        ['text' => 'Causar', 'href' => "/compras/facturas/crear?soporte={$s->id}"],
                        ['text' => 'Eliminar', 'post' => "/compras/soporte-legalizar/{$s->id}/eliminar", 'danger' => true, 'confirm' => '¿Eliminar este soporte?'],
                    ]],
                ];
            });
    }

    /** Cotizaciones (CT) o remisiones (RM) con filtro de fechas y cliente. */
    private function salesList(Request $request, string $type)
    {
        $all = $request->boolean('todos') && ! $request->hasAny(['desde', 'hasta']);
        $from = $request->input('desde', now()->subDays($type === 'RM' ? 15 : 30)->toDateString());
        $to = $request->input('hasta', now()->toDateString());

        return SalesDocument::query()->with('contact:id,name,identification,branch_office')
            ->when($type === 'CT', fn ($q) => $q->addSelect(['remission_id' => DB::table('sales_documents as remissions')
                ->select('remissions.id')->whereColumn('remissions.related_document_id', 'sales_documents.id')->where('remissions.type', 'RM')->orderByDesc('remissions.id')->limit(1)])
            )
            ->when($type === 'CT', fn ($q) => $q->addSelect(['remission_name' => DB::table('sales_documents as remissions')
                ->select('remissions.name')->whereColumn('remissions.related_document_id', 'sales_documents.id')->where('remissions.type', 'RM')->orderByDesc('remissions.id')->limit(1)])
            )
            ->when($type === 'CT', fn ($q) => $q->addSelect(['invoice_id' => DB::table('sales_documents as invoices')
                ->select('invoices.id')->whereColumn('invoices.related_document_id', 'sales_documents.id')->where('invoices.type', 'FV')->orderByDesc('invoices.id')->limit(1)])
            )
            ->when($type === 'CT', fn ($q) => $q->addSelect(['invoice_name' => DB::table('sales_documents as invoices')
                ->select('invoices.name')->whereColumn('invoices.related_document_id', 'sales_documents.id')->where('invoices.type', 'FV')->orderByDesc('invoices.id')->limit(1)])
            )
            ->where('type', $type)->when(! $all, fn ($q) => $q->whereBetween('date', [$from, $to]))
            ->when($type === 'CT' && $request->filled('estado'), function ($q) use ($request) {
                $statuses = array_intersect((array) $request->input('estado'), ['Pendientes', 'Aprobada', 'Descartada', 'Sin correo', 'Correo pendiente', 'Correo enviado', 'Fallido', 'Correo bloqueado']);
                $q->where(function ($q) use ($statuses) {
                    foreach ($statuses as $status) {
                        $q->orWhere(function ($q) use ($status) {
                            match ($status) {
                                'Pendientes' => $q->where('status', 'issued'),
                                'Aprobada' => $q->where('status', 'approved'),
                                'Descartada' => $q->where('status', 'discarded'),
                                'Correo pendiente' => $q->where('status', 'issued')->where('mail_status', 'queued'),
                                'Correo enviado' => $q->where('status', 'issued')->whereIn('mail_status', ['sent', 'read']),
                                'Fallido' => $q->where('status', 'issued')->whereIn('mail_status', ['failed', 'failed_delivery']),
                                'Correo bloqueado' => $q->where('status', 'issued')->where('mail_status', 'blocked'),
                                'Sin correo' => $q->where('status', 'issued')->where(function ($mail) {
                                    $mail->whereNull('mail_status')->orWhereNotIn('mail_status', ['queued', 'sent', 'read', 'failed', 'failed_delivery', 'blocked']);
                                }),
                                default => $q->whereRaw('1 = 0'),
                            };
                        });
                    }
                    if ($statuses === []) {
                        $q->whereRaw('1 = 0');
                    }
                });
            })
            ->when($type === 'CT' && $request->filled('remisionada'), function ($q) use ($request) {
                $exists = DB::table('sales_documents as remissions')->selectRaw('1')
                    ->whereColumn('remissions.related_document_id', 'sales_documents.id')->where('remissions.type', 'RM');
                $request->input('remisionada') === 'Sí' ? $q->whereExists($exists) : $q->whereNotExists($exists);
            })
            ->when($type === 'CT' && $request->filled('facturada'), function ($q) use ($request) {
                $exists = DB::table('sales_documents as invoices')->selectRaw('1')
                    ->whereColumn('invoices.related_document_id', 'sales_documents.id')->where('invoices.type', 'FV');
                $request->input('facturada') === 'Sí' ? $q->whereExists($exists) : $q->whereNotExists($exists);
            })
            ->when($request->input('q'), fn ($q, $t) => $type === 'CT'
                ? $q->whereIn('created_by', DB::table('users')->where('name', 'like', "%$t%")->pluck('id'))
                : $q->whereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%")))
            ->when($request->input('usuario'), fn ($q, $t) => $q->whereIn('created_by', DB::table('users')->where('name', 'like', "%$t%")->pluck('id')))
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate(min($request->integer('per_page', $type === 'CT' ? 50 : 20), 5000))->withQueryString()
            ->through(fn (SalesDocument $d) => array_values(array_filter([
                $type === 'RM' ? 'Remisión' : null,
                ['text' => $d->name.($type === 'RM' && $d->status === 'annulled' ? ' (Anulada)' : ''), 'href' => "/documentos/ventas/{$d->id}"],
                $d->date->format('d/m/Y'), $d->contact?->identification, $d->contact?->branch_office ?: '-', ['text' => $d->contact?->name, 'truncate' => true],
                $type === 'CT' ? $this->quoteStatusBadge($d) : ($type === 'RM' ? $d->mailBadge() : null),
                $type === 'CT' ? [
                    'text' => $d->remission_id ? 'Sí' : 'No',
                    'icon' => $d->remission_id ? 'circle-check' : 'circle-xmark',
                    'icon_class' => $d->remission_id ? 'text-[#2e7d32]' : 'text-[#9e9e9e]',
                    'icon_only' => true,
                    'title' => 'Remisionada: '.($d->remission_id ? 'Sí' : 'No'),
                    'href' => $d->remission_id ? "/documentos/ventas/{$d->remission_id}" : null,
                    'link_text' => $d->remission_name,
                    'link_title' => $d->remission_name,
                ] : null,
                $type === 'CT' ? [
                    'text' => $d->invoice_id ? 'Sí' : 'No',
                    'icon' => $d->invoice_id ? 'circle-check' : 'circle-xmark',
                    'icon_class' => $d->invoice_id ? 'text-[#2e7d32]' : 'text-[#9e9e9e]',
                    'icon_only' => true,
                    'title' => 'Facturada: '.($d->invoice_id ? 'Sí' : 'No'),
                    'href' => $d->invoice_id ? "/documentos/ventas/{$d->invoice_id}" : null,
                    'link_text' => $d->invoice_name,
                    'link_title' => $d->invoice_name,
                ] : null,
                ['text' => $this->money((float) $d->total), 'align' => 'right'], $type === 'RM' ? null : ($d->currency ?? 'COP'),
            ], fn ($v) => $v !== null)));
    }

    /** Estado visible en la lista de cotizaciones. */
    private function quoteStatusBadge(SalesDocument $doc): array
    {
        return match ($doc->status) {
            'annulled' => ['text' => 'Anulada', 'badge' => 'danger'],
            'approved' => ['text' => 'Aprobada', 'badge' => 'ok', 'icon' => 'circle-check'],
            'discarded' => ['text' => 'Descartada', 'badge' => 'off'],
            default => match ($doc->mail_status) {
                'queued' => ['text' => 'Correo pendiente', 'badge' => 'off'],
                'sent', 'read' => ['text' => 'Correo enviado', 'badge' => 'ok'],
                'failed', 'failed_delivery' => ['text' => 'Fallido', 'badge' => 'danger'],
                'blocked' => ['text' => 'Correo bloqueado', 'badge' => 'danger'],
                default => ['text' => 'Sin correo', 'badge' => 'warn'],
            },
        };
    }

    private function quotes(Request $request)
    {
        return $this->salesList($request, 'CT');
    }

    private function remissions(Request $request)
    {
        return $this->salesList($request, 'RM');
    }

    /** Traslados, ajustes, conteos fisicos y ensambles elaborados en el ERP. */
    private function inventoryDocs(Request $request)
    {
        $kinds = ['inventory_traslado' => 'Traslado entre bodegas', 'inventory_ajuste' => 'Ajuste de inventario', 'inventory_conteo' => 'Conteo físico', 'inventory_ensamble' => 'Nota de ensamble'];

        return \App\Models\JournalEntry::query()->whereIn('source_type', array_keys($kinds))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('observations', 'like', "%$t%")))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 5000))->withQueryString()
            ->through(function ($e) use ($kinds) {
                $contact = \App\Models\Contact::query()->whereKey(\Illuminate\Support\Facades\DB::table('journal_lines')->where('journal_entry_id', $e->id)->whereNotNull('contact_id')->value('contact_id'))->first();

                return [
                    $kinds[$e->source_type], ['text' => $e->name.($e->status === 'annulled' ? ' (Anulado)' : ''), 'href' => "/documentos/comprobantes/{$e->id}"],
                    $e->date->format('d/m/Y'), $contact?->identification ?? '', $contact ? (string) ($contact->branch_office ?? 0) : '', $contact?->name ?? '',
                    ['text' => $this->money((float) $e->debit), 'align' => 'right'],
                ];
            });
    }

    /** Acciones del mes: Descargar detalle con desplegable Ver comprobante / Volver a costear. */
    private function costingActions(?InventoryCosting $c, bool $ok, bool $canRun, string $url, string $month, int $year, int $m): array
    {
        $confirm = "Se recalculará el costo promedio de los movimientos de {$month} {$year} y se contabilizará la diferencia en el costo de ventas. ¿Continuar?";
        if (! $ok) {
            return ['actions' => $canRun ? [['text' => 'Calcular costeo', 'post' => $url, 'confirm' => $confirm]] : []];
        }
        $end = \Illuminate\Support\Carbon::create($year, $m, 1)->endOfMonth()->toDateString();
        $voucher = \App\Models\JournalEntry::query()->where('source_type', 'inventory_costing')->where('source_id', $c->id)->where('status', '!=', 'annulled')->value('id')
            ?? \App\Models\JournalEntry::query()->where('name', 'like', 'CC-3-%')->whereDate('date', $end)->orderBy('number')->value('id');

        return ['split' => ['text' => 'Descargar detalle', 'download' => "$url/excel"], 'menu' => array_values(array_filter([
            $voucher ? ['text' => 'Ver comprobante', 'href' => "/documentos/comprobantes/$voucher"] : null,
            $canRun ? ['text' => 'Volver a costear', 'post' => $url, 'confirm' => $confirm] : null,
        ]))];
    }

    private function costing(Request $request): array
    {
        $year = (int) $request->input('anio', now()->year);
        $done = InventoryCosting::where('year', $year)->get()->keyBy('month');
        $months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $last = InventoryCosting::query()->where('status', 'Costeado')->orderByDesc('year')->orderByDesc('month')->first();

        return collect($months)->map(function ($m, $i) use ($done, $year, $last) {
            $c = $done->get($i + 1);
            $ok = $c && $c->status === 'Costeado';
            $isLast = $last && (int) $last->year === $year && (int) $last->month === $i + 1;
            $next = ! $last || \Illuminate\Support\Carbon::create((int) $last->year, (int) $last->month, 1)->addMonth()->isSameMonth(\Illuminate\Support\Carbon::create($year, $i + 1, 1));
            $url = "/productos/costeo/{$year}/".($i + 1);
            $started = now()->startOfMonth()->gte(\Illuminate\Support\Carbon::create($year, $i + 1, 1));

            return [
                $m,
                $c?->executed_at?->translatedFormat('j M Y') ?? '',
                ['text' => $ok ? 'Costeado' : 'Sin costear', 'badge' => $ok ? 'ok' : 'danger'],
                $this->costingActions($c, $ok, $started && ($ok ? $isLast : $next), $url, $m, $year, $i + 1),
            ];
        })->all();
    }
}
