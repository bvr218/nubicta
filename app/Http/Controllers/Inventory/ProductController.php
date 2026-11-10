<?php

namespace App\Http\Controllers\Inventory;

use App\Exports\ProductsExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\AccountGroup;
use App\Models\InventoryMovement;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductComboItem;
use App\Models\ProductLine;
use App\Models\Tax;
use App\Models\Warehouse;
use App\Support\DianUnitCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $lists = PriceList::where('active', true)->orderBy('position')->get(['id', 'name']);

        $products = $this->query($request)
            ->with(['taxes:id,name', 'prices', 'line:id,name'])
            ->when($request->input('orden'), fn ($q, $o) => $q->orderBy($o === 'codigo' ? 'code' : 'name'), fn ($q) => $q->latest('id'))
            ->paginate($request->integer('per_page', 10))
            ->withQueryString()
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'type' => Product::TYPES[$p->type] ?? $p->type,
                'name' => $p->name,
                'code' => $p->code,
                'unit' => $p->unit_label,
                'line' => $p->line?->name,
                'prices' => $lists->map(fn ($l) => (float) $p->prices->firstWhere('price_list_id', $l->id)?->value)->filter()->values(),
                'taxes' => $p->taxes->pluck('name')->implode(', '),
                'stock' => $p->stock_control ? (float) $p->stock : null,
                'active' => $p->active,
            ]);

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'tabs' => DashboardController::tabs('productos'),
            'priceListNames' => $lists->pluck('name'),
            'filters' => [
                ...$request->only('q', 'linea', 'grupo', 'tipo', 'estado', 'orden'),
                'saldo' => $request->input('saldo') ?: ($request->boolean('bajo_minimo') ? 'bajo_minimo' : ''),
            ],
            'lines' => ProductLine::orderByRaw('CAST(code AS UNSIGNED)')->get(['id', 'code', 'name']),
            'groups' => AccountGroup::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        if ($source = Product::find($request->integer('copiar') ?: null)) {
            $copy = $this->payload($source->load(['taxes:id', 'prices', 'comboItems.product:id,code,name']));
            $copy = [...collect($copy)->except(['id', 'siigo_id', 'stock', 'average_cost', 'created_at', 'updated_at'])->all(), 'code' => '', 'name' => "{$source->name} (copia)"];

            return $this->form(null, $source->type, $copy);
        }

        return $this->form(null, match ($request->input('tipo')) { 'servicio' => 'Service', 'combo' => 'Combo', default => 'Product' });
    }

    /** Inactivar / activar desde el listado. */
    public function toggle(Product $product): RedirectResponse
    {
        $product->update(['active' => ! $product->active]);

        return back()->with('success', $product->active ? 'Producto activado.' : 'Producto inactivado.');
    }

    /** Eliminar solo si nunca se ha usado en documentos ni movimientos de inventario. */
    public function destroy(Product $product): RedirectResponse
    {
        $used = $product->movements()->exists() || ProductComboItem::where('product_id', $product->id)->exists()
            || DB::table('sales_document_items')->where('product_id', $product->id)->exists()
            || DB::table('purchase_document_items')->where('product_id', $product->id)->exists()
            || DB::table('journal_lines')->where('product_id', $product->id)->exists();
        if ($used) {
            return back()->with('error', 'El producto tiene documentos o movimientos asociados; puedes inactivarlo en lugar de eliminarlo.');
        }
        try {
            $product->delete();
        } catch (\Illuminate\Database\QueryException) {
            return back()->with('error', 'El producto está en uso y no se puede eliminar; puedes inactivarlo.');
        }

        return back()->with('success', 'Producto eliminado.');
    }

    /** Gestion de combos: productos tipo Combo con sus componentes. */
    public function combos(Request $request): Response
    {
        $rows = Product::query()->where('type', 'Combo')->search($request->input('q'))->with(['taxes:id,name', 'prices'])
            ->withCount('comboItems')->orderBy('name')->paginate(20)->withQueryString()
            ->through(fn (Product $p) => [
                ['text' => $p->name, 'href' => "/productos/{$p->id}/editar"], $p->code, $p->unit_label,
                ['text' => number_format($p->priceFor(null), 2, '.', ','), 'align' => 'right'], $p->taxes->pluck('name')->implode(', ') ?: '-',
                ['text' => $p->active ? 'Activo' : 'Inactivo', 'badge' => $p->active ? 'ok' : 'off'],
                ['actions' => [['text' => 'Editar', 'href' => "/productos/{$p->id}/editar"], ['text' => "{$p->combo_items_count} productos", 'href' => "/productos/{$p->id}/editar"]]],
            ]);

        return Inertia::render('GenericList', [
            'view' => [
                'title' => 'Productos y servicios', 'tab' => 'Gestión combos', 'url' => '/productos/combos', 'search' => 'Buscar por nombre o código',
                'buttons' => [['label' => 'Ver reportes', 'href' => '/reportes', 'style' => 'outline'], ['label' => 'Crear combo', 'href' => '/productos/crear?tipo=combo']],
                'columns' => ['Nombre', 'Código', 'Unidad', 'Precios', 'Impuestos', 'Estado', 'Acciones'],
                'empty' => ['Aún no tienes combos registrados', 'Crea un combo para venderlo como un solo producto'],
            ],
            'rows' => $rows,
            'tabs' => \App\Http\Controllers\DashboardController::tabs('productos', 'Gestión combos'),
            'filters' => $request->only('q'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $product = Product::create($this->attributes($data));
            $this->syncRelations($product, $data);

            return $product;
        });

        return $product->type === 'Combo'
            ? redirect('/productos/combos')->with('success', 'Combo creado correctamente.')
            : redirect("/productos/{$product->id}/editar")->with('success', 'Producto creado correctamente.');
    }

    /** Perfil del producto: datos generales, stock, lista de precios, impuestos y costo promedio mensual. */
    public function show(Request $request, Product $product): Response
    {
        $product->load(['accountGroup:id,name', 'taxes:id,name,type', 'prices.priceList:id,name,position']);
        $year = min(max($request->integer('anio', (int) now()->year), 2000), (int) now()->year);
        $snap = DB::table('product_monthly_costs')->where('company_id', $product->company_id)->where('code', $product->code)
            ->where('year', '<=', $year)->orderBy('year')->orderBy('month')->get(['year', 'month', 'average_cost']);
        $last = null;
        $months = [];
        foreach (range(1, 12) as $m) {
            $key = sprintf('%04d%02d', $year, $m);
            $last = $snap->filter(fn ($s) => sprintf('%04d%02d', $s->year, $s->month) <= $key)->last()?->average_cost ?? $last;
            $months[] = $key > now()->format('Ym') ? null : ($last !== null ? (float) $last : ($key === now()->format('Ym') ? (float) $product->average_cost : null));
        }
        $units = app(DianUnitCatalog::class)->options();

        return Inertia::render('Inventory/Products/Profile', [
            'product' => [
                'id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'type' => Product::TYPES[$product->type] ?? $product->type,
                'stock' => $product->stock_control ? (float) $product->stock : null, 'unit' => $product->unit_label, 'active' => (bool) $product->active,
                'inventoriable' => (bool) $product->stock_control, 'visible' => (bool) $product->visible_in_sales, 'group' => $product->accountGroup?->name,
                'barcode' => $product->barcode, 'dian_unit' => $product->unit_code ? $product->unit_code.' - '.($product->unit_label ?: ($units[$product->unit_code] ?? '')) : null,
                'reference' => $product->reference, 'min_stock' => (float) $product->min_stock, 'description' => $product->description,
                'brand' => $product->brand, 'model' => $product->model, 'tariff' => $product->tariff,
                'prices' => $product->prices->sortBy(fn ($p) => $p->priceList?->position)->map(fn ($p) => ['name' => $p->priceList?->name, 'value' => (float) $p->value, 'currency' => $p->currency ?: 'COP'])->values(),
                'charge' => $product->taxes->whereIn('type', ['IVA', 'Impoconsumo'])->pluck('name')->implode(', ') ?: null,
                'withholding' => $product->taxes->whereIn('type', ['Retefuente', 'ReteIVA', 'ReteICA'])->pluck('name')->implode(', ') ?: null,
                'created' => $product->created_at?->format('d/m/Y H:i:s').' hrs', 'updated' => $product->updated_at?->format('d/m/Y H:i:s').' hrs',
            ],
            'costYear' => $year,
            'costYears' => range((int) now()->year, max(2000, (int) ($snap->min('year') ?? now()->year))),
            'costs' => $request->user()?->can('inventario.ver_costos') ? $months : [],
            'showCosts' => $request->user()?->can('inventario.ver_costos') && $request->has('anio'),
            'canSeeCosts' => (bool) $request->user()?->can('inventario.ver_costos'),
        ]);
    }

    public function edit(Product $product): Response
    {
        return $this->form($product->load(['taxes:id', 'prices', 'comboItems.product:id,code,name']), $product->type);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product) {
            $data = $this->validated($request, $product);
            $product->update($this->attributes($data));
            $this->syncRelations($product, $data);
        });

        return back()->with('success', 'Producto actualizado correctamente.');
    }

    /** Kardex del producto: movimientos con saldo, filtrable por bodega y fechas. */
    public function kardex(Request $request, Product $product): Response
    {
        $movements = InventoryMovement::where('product_id', $product->id)
            ->when($request->input('bodega'), fn ($q, $w) => $q->where('warehouse_id', $w))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->with('warehouse:id,name')
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(50)->withQueryString()
            ->through(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'date' => $m->date->format('d/m/Y'),
                'document' => $m->document,
                'concept' => InventoryMovement::CONCEPTS[$m->concept] ?? $m->concept,
                'warehouse' => $m->warehouse?->name,
                'in' => $m->quantity > 0 ? (float) $m->quantity : null,
                'out' => $m->quantity < 0 ? (float) -$m->quantity : null,
                'unit_cost' => (float) $m->unit_cost,
                'balance_quantity' => (float) $m->balance_quantity,
                'balance_cost' => (float) $m->balance_cost,
            ]);

        return Inertia::render('Inventory/Products/Kardex', [
            'product' => $product->only('id', 'code', 'name', 'unit_label', 'stock', 'average_cost'),
            'stocks' => $product->stocks()->with('warehouse:id,name')->get()->map(fn ($s) => ['warehouse' => $s->warehouse?->name, 'quantity' => (float) $s->quantity]),
            'movements' => $movements,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('bodega', 'desde', 'hasta'),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        return \App\Support\Download::export(new ProductsExport($this->query($request)), 'productos.xlsx', 'Productos');
    }

    /** Siguiente codigo libre de una linea (herramienta del modulo de deposito). */
    public function nextCode(ProductLine $line): JsonResponse
    {
        return response()->json($line->nextCode());
    }

    public function search(Request $request): JsonResponse
    {
        $listId = $request->integer('lista') ?: null;

        return response()->json(
            Product::query()->search($request->input('q'))->where('active', true)->where('visible_in_sales', true)
                ->with(['taxes:id,name,type,percentage', 'prices'])
                ->orderBy('name')->limit(20)->get()
                ->map(fn (Product $p) => [
                    ...$p->only('id', 'code', 'name', 'type', 'unit_code', 'unit_label', 'stock_control', 'tax_included'),
                    'stock' => (float) $p->stock,
                    'price' => $p->priceFor($listId),
                    'taxes' => $p->taxes,
                ])
        );
    }

    private function query(Request $request)
    {
        return Product::query()
            ->search($request->input('q'))
            ->when($request->input('linea'), fn ($q, $v) => $q->where('product_line_id', $v))
            ->when($request->input('grupo'), fn ($q, $v) => $q->where('account_group_id', $v))
            ->when($request->input('tipo'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->input('estado') === 'activos', fn ($q) => $q->where('active', true))
            ->when($request->input('estado') === 'inactivos', fn ($q) => $q->where('active', false))
            ->when($request->input('saldo') === 'negativo', fn ($q) => $q->where('stock_control', true)->where('stock', '<', 0))
            ->when($request->input('saldo') === 'bajo_minimo' || $request->boolean('bajo_minimo'), fn ($q) => $q->where('stock_control', true)->where('min_stock', '>', 0)->whereColumn('stock', '<', 'min_stock'));
    }

    private function payload(Product $product): array
    {
        return [
            ...$product->toArray(),
            'tax_ids' => $product->taxes->pluck('id'),
            'prices' => $product->prices->pluck('value', 'price_list_id'),
            'components' => in_array($product->type, ['Combo', 'Product'], true) ? $product->comboItems->map(fn ($c) => [
                'product_id' => $c->product_id, 'product_id_label' => "{$c->product?->code} - {$c->product?->name}", 'quantity' => (float) $c->quantity,
            ])->all() : [],
        ];
    }

    private function form(?Product $product, string $type, ?array $copy = null): Response
    {
        $taxes = Tax::where('active', true)->orderBy('name')->get(['id', 'name', 'type', 'percentage']);

        return Inertia::render('Inventory/Products/Form', [
            'product' => $product ? $this->payload($product) : null,
            'copy' => $copy,
            'type' => $type,
            'groups' => AccountGroup::where('active', true)->orderBy('name')->get(['id', 'name']),
            'lines' => ProductLine::where('active', true)->orderByRaw('CAST(code AS UNSIGNED)')->get(['id', 'code', 'name']),
            'units' => collect(app(DianUnitCatalog::class)->options())->map(fn ($label, $code) => ['code' => (string) $code, 'label' => $label])->values(),
            'chargeTaxes' => $taxes->whereIn('type', ['IVA', 'Impoconsumo'])->values(),
            'withholdingTaxes' => $taxes->whereIn('type', ['Retefuente', 'ReteIVA', 'ReteICA'])->values(),
            'priceLists' => PriceList::where('active', true)->orderBy('position')->get(['id', 'name']),
            'ivaResponsible' => $this->ivaResponsible(),
        ]);
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'type' => ['required', Rule::in(array_keys(Product::TYPES))],
            'account_group_id' => ['required', Rule::exists('account_groups', 'id')->where('company_id', $companyId)],
            'product_line_id' => ['nullable', Rule::exists('product_lines', 'id')->where('company_id', $companyId)],
            'code' => ['required', 'string', 'max:40', Rule::unique('products')->where('company_id', $companyId)->ignore($product?->id)],
            'name' => ['required', 'string', 'max:250'],
            'barcode' => ['nullable', 'string', 'max:60'],
            'unit_code' => ['required', 'string', 'max:6'],
            'stock_control' => ['boolean'],
            'visible_in_sales' => ['boolean'],
            'tax_ids' => ['array'],
            'tax_ids.*' => [Rule::exists('taxes', 'id')->where('company_id', $companyId)],
            'tax_classification' => ['required', Rule::in(array_keys(Product::TAX_CLASSIFICATIONS))],
            'tax_included' => ['boolean'],
            'reference' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:4000'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'tariff' => ['nullable', 'string', 'max:20'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'prices' => ['array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
            'components' => [Rule::requiredIf($request->input('type') === 'Combo'), 'array'],
            'components.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('company_id', $companyId)->where('type', 'Product')],
            'components.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ], [
            'components.required' => 'Agrega al menos un producto al combo.',
            'components.*.product_id.exists' => 'Los componentes del combo deben ser productos (no servicios ni combos).',
            'code.unique' => 'Ya existe un producto con este código.',
            'account_group_id.required' => 'La categoría del producto es obligatoria.',
        ], ['code' => 'código', 'name' => 'nombre', 'unit_code' => 'unidad de medida']);
    }

    private function attributes(array $data): array
    {
        $attrs = collect($data)->except(['tax_ids', 'prices', 'components'])->all();
        $label = app(DianUnitCatalog::class)->options()[$data['unit_code']] ?? $data['unit_code'];
        $attrs['unit_label'] = mb_strtolower(trim(explode('—', $label)[1] ?? $label));
        $attrs['min_stock'] = $data['min_stock'] ?? 0;
        if ($data['type'] !== 'Product') {
            $attrs['stock_control'] = false;
        }
        if (empty($data['product_line_id'])) {
            $lines = ProductLine::pluck('id', 'code')->all();
            $attrs['product_line_id'] = ($c = ProductLine::codeFor($data['code'], $lines)) ? $lines[$c] : null;
        }

        return $attrs;
    }

    private function syncRelations(Product $product, array $data): void
    {
        $product->taxes()->sync($data['tax_ids'] ?? []);
        if (in_array($product->type, ['Combo', 'Product'], true) && array_key_exists('components', $data)) {
            $items = collect($data['components'] ?? [])->filter(fn ($c) => ! empty($c['product_id']) && (int) $c['product_id'] !== $product->id && (float) ($c['quantity'] ?? 0) > 0)
                ->groupBy('product_id')->map(fn ($g) => $g->sum(fn ($c) => (float) $c['quantity']));
            if ($items->isEmpty() && $product->type === 'Combo') {
                throw \Illuminate\Validation\ValidationException::withMessages(['components' => 'Agrega al menos un producto con cantidad al combo.']);
            }
            $product->comboItems()->delete();
            foreach ($items as $productId => $qty) {
                $product->comboItems()->create(['company_id' => $product->company_id, 'product_id' => $productId, 'quantity' => $qty]);
            }
        }
        $product->prices()->delete();
        foreach ($data['prices'] ?? [] as $listId => $value) {
            if ($value !== null && $value !== '') {
                $product->prices()->create(['price_list_id' => $listId, 'value' => $value]);
            }
        }
    }

    private function ivaResponsible(): bool
    {
        $company = auth()->user()?->company;

        return (string) $company?->tax_regime === '48' || in_array('O-48', (array) ($company?->fiscal_responsibilities ?? []), true);
    }
}
