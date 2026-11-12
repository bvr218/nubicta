<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\DianSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Inicio > Indicadores y Habilitaciones electronicas. */
class IndicatorsController extends Controller
{
    public const PERIODS = ['mes' => 'Mes actual', 'trimestre' => 'Trimestre actual', 'semestre' => 'Semestre actual', 'anio' => 'Año actual'];

    private const BUCKETS = [
        'hoy' => '0 días (hoy)', '1_15' => 'Vencido - de 1 a 15 días', '16_30' => 'Vencido - de 16 a 30 días', '31_60' => 'Vencido - de 31 a 60 días',
        '61_90' => 'Vencido - de 61 a 90 días', '91_120' => 'Vencido - de 91 a 120 días', '121' => 'Vencido - más de 121 días', 'por_vencer' => 'Por vencer',
    ];

    public function index(Request $request): Response
    {
        $company = $request->user()->company_id;
        $p = fn (string $key) => array_key_exists($request->input($key), self::PERIODS) ? $request->input($key) : 'semestre';
        $periods = ['ventas' => $p('ventas'), 'compras' => $p('compras'), 'top' => $p('top'), 'vs' => $p('vs'), 'gastos' => $p('gastos')];

        $sales = fn (string $period) => $this->monthly($company, $period, fn ($from, $to) => DB::table('sales_documents')
            ->where('company_id', $company)->whereIn('type', ['FV', 'NC', 'ND'])->where('status', '!=', 'annulled')->whereBetween('date', [$from, $to])
            ->groupByRaw("DATE_FORMAT(date, '%Y-%m')")
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS m, SUM(CASE WHEN type = 'NC' THEN -(subtotal - discount) ELSE subtotal - discount END) AS v")
            ->pluck('v', 'm'));

        $purchasesByType = fn (string $period) => $this->monthlyMulti($company, $period, fn ($from, $to) => DB::table('purchase_documents AS p')
            ->join('purchase_document_items AS i', 'i.purchase_document_id', '=', 'p.id')
            ->where('p.company_id', $company)->whereIn('p.type', ['FC', 'DS', 'NC', 'NA'])->where('p.status', '!=', 'annulled')->whereBetween('p.date', [$from, $to])
            ->groupByRaw("DATE_FORMAT(p.date, '%Y-%m'), i.item_type")
            ->selectRaw("DATE_FORMAT(p.date, '%Y-%m') AS m, i.item_type AS k, SUM(CASE WHEN p.type IN ('NC', 'NA') THEN -i.subtotal ELSE i.subtotal END) AS v")
            ->get(), ['Product' => 'Productos', 'Account' => 'Gastos', 'FixedAsset' => 'Activos fijos']);

        $compras = $purchasesByType($periods['compras']);
        $vsSales = $sales($periods['vs']);
        $vsPurchases = $purchasesByType($periods['vs']);

        return Inertia::render('Indicators', [
            'periods' => self::PERIODS,
            'selected' => $periods,
            'updatedAt' => now()->format('n/j/Y, g:i:s A'),
            'ventas' => $sales($periods['ventas']),
            'compras' => $compras,
            'top' => $this->topProducts($company, $periods['top']),
            'vs' => [
                'labels' => $vsSales['labels'],
                'series' => ['Ventas' => $vsSales['values'], 'Compras/ Gastos/ Doc. Soporte' => array_map(fn (...$v) => array_sum($v), ...array_values($vsPurchases['series']))],
            ],
            'cartera' => $this->aging($company, 'sales_documents', ['FV', 'ND'], 'RC'),
            'pagar' => $this->aging($company, 'purchase_documents', ['FC', 'DS', 'ND'], 'RP'),
            'gastos' => $this->expenses($company, $periods['gastos']),
            'disponible' => BankAccount::query()->with('account:id,code')->where('active', true)->get()->sortBy(fn (BankAccount $b) => $b->account?->code)->values()->map(fn (BankAccount $b) => ['name' => $b->name, 'value' => $b->balance()])->all(),
            'tabs' => self::homeTabs(),
        ]);
    }

    public function enablement(): Response
    {
        $settings = DianSetting::current();
        $status = fn (string $scope) => $settings->habilitationFor($scope)['status'] ?? 'pendiente';

        return Inertia::render('Enablement', [
            'tabs' => self::homeTabs(),
            'cards' => [
                ['title' => 'Facturación electrónica', 'text' => 'Emite cómodamente tus facturas directamente desde tu computador.', 'action' => 'Crear Factura E.', 'href' => '/ventas/facturas/crear', 'status' => $status('fe'), 'icon' => 'file-invoice-dollar'],
                ['title' => 'Documento soporte electrónico', 'text' => 'Registra transacciones electrónicas para sujetos no obligados a facturar.', 'action' => 'Crear Doc. Soporte', 'href' => '/compras/documentos-soporte/crear', 'status' => $status('ds'), 'icon' => 'file-lines'],
                ['title' => 'Nómina electrónica', 'text' => 'Envía tus nóminas de manera electrónica a la DIAN sin complicaciones.', 'action' => 'Continuar', 'href' => '/nomina/documentos', 'status' => $status('nomina'), 'icon' => 'user-tie'],
            ],
        ]);
    }

    public static function homeTabs(): array
    {
        return [
            ['label' => 'Accesos directos', 'href' => '/'],
            ['label' => 'Indicadores', 'href' => '/inicio/indicadores'],
            ['label' => 'Habilitaciones electrónicas', 'href' => '/inicio/habilitaciones'],
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'mes' => [$now->startOfMonth(), $now->endOfMonth()],
            'trimestre' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'anio' => [$now->startOfYear(), $now->endOfYear()],
            default => $now->month <= 6 ? [$now->startOfYear(), $now->startOfYear()->addMonths(5)->endOfMonth()] : [$now->startOfYear()->addMonths(6), $now->endOfYear()],
        };
    }

    private function months(string $period): array
    {
        [$from, $to] = $this->range($period);
        $out = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $out[$m->format('Y-m')] = ucfirst($m->translatedFormat('F'));
        }

        return $out;
    }

    private function monthly(int $company, string $period, callable $query): array
    {
        [$from, $to] = $this->range($period);
        $data = $query($from->toDateString(), $to->toDateString());
        $months = $this->months($period);

        return ['labels' => array_values($months), 'values' => array_map(fn ($k) => round((float) ($data[$k] ?? 0), 2), array_keys($months))];
    }

    private function monthlyMulti(int $company, string $period, callable $query, array $kinds): array
    {
        [$from, $to] = $this->range($period);
        $rows = $query($from->toDateString(), $to->toDateString());
        $months = $this->months($period);
        $series = [];
        foreach ($kinds as $k => $label) {
            $series[$label] = array_map(fn ($m) => round((float) $rows->where('m', $m)->where('k', $k)->sum('v'), 2), array_keys($months));
        }

        return ['labels' => array_values($months), 'series' => $series];
    }

    private function topProducts(int $company, string $period): array
    {
        [$from, $to] = $this->range($period);
        $items = DB::table('sales_document_items AS i')->join('sales_documents AS d', 'd.id', '=', 'i.sales_document_id')
            ->where('d.company_id', $company)->where('d.type', 'FV')->where('d.status', '!=', 'annulled')
            ->whereBetween('d.date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('i.code')->orderByDesc(DB::raw('SUM(i.subtotal)'))->limit(5)
            ->selectRaw('i.code, MAX(i.description) AS description, SUM(i.subtotal) AS v')->get()
            ->map(fn ($r) => ['label' => trim($r->code.' - '.$r->description, ' -'), 'value' => round((float) $r->v, 2)]);

        return ['items' => $items->all(), 'total' => round($items->sum('value'), 2)];
    }

    private function aging(int $company, string $table, array $types, string $receiptType): array
    {
        $today = now()->toDateString();
        $row = (array) DB::table($table)->where('company_id', $company)->whereIn('type', $types)->where('status', '!=', 'annulled')->where('balance', '>', 0)
            ->selectRaw(
                'SUM(CASE WHEN due_date = ? THEN balance ELSE 0 END) AS hoy,
                 SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 1 AND 15 THEN balance ELSE 0 END) AS `1_15`,
                 SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 16 AND 30 THEN balance ELSE 0 END) AS `16_30`,
                 SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN balance ELSE 0 END) AS `31_60`,
                 SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN balance ELSE 0 END) AS `61_90`,
                 SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 91 AND 120 THEN balance ELSE 0 END) AS `91_120`,
                 SUM(CASE WHEN DATEDIFF(?, due_date) >= 121 THEN balance ELSE 0 END) AS `121`,
                 SUM(CASE WHEN due_date IS NULL OR due_date > ? THEN balance ELSE 0 END) AS por_vencer',
                array_fill(0, 8, $today))
            ->first();

        $advances = (float) DB::table('receipts')->where('company_id', $company)->where('type', $receiptType)
            ->where('receipt_type', 'AdvancePayment')->where('status', '!=', 'annulled')->sum('total');

        return collect(self::BUCKETS)->map(fn ($label, $key) => ['label' => $label, 'value' => round((float) ($row[$key] ?? 0), 2)])->values()
            ->push(['label' => 'Anticipos', 'value' => -round($advances, 2)])->all();
    }

    private function expenses(int $company, string $period): array
    {
        [$from, $to] = $this->range($period);
        $range = [$from->toDateString(), $to->toDateString()];
        $fromPurchases = DB::table('purchase_document_items AS i')->join('purchase_documents AS p', 'p.id', '=', 'i.purchase_document_id')
            ->leftJoin('accounts AS a', 'a.id', '=', 'i.account_id')
            ->where('p.company_id', $company)->where('p.status', '!=', 'annulled')->where('i.item_type', 'Account')->whereBetween('p.date', $range)
            ->groupByRaw('COALESCE(a.name, i.description)')->selectRaw('COALESCE(a.name, i.description) AS name, SUM(i.subtotal) AS v');
        $fromPayments = DB::table('receipt_items AS i')->join('receipts AS r', 'r.id', '=', 'i.receipt_id')
            ->join('accounts AS a', 'a.id', '=', 'i.account_id')
            ->where('r.company_id', $company)->where('r.type', 'RP')->where('r.status', '!=', 'annulled')->where('a.code', 'like', '5%')->whereBetween('r.date', $range)
            ->groupBy('a.name')->selectRaw('a.name, SUM(i.value) AS v');

        $rows = $fromPurchases->unionAll($fromPayments)->get()->groupBy('name')->map(fn ($g) => $g->sum('v'))->sortDesc();

        return [
            'items' => $rows->take(5)->map(fn ($v, $name) => ['label' => $name, 'value' => round((float) $v, 2)])->values()->all(),
            'total' => round((float) $rows->sum(), 2),
        ];
    }
}
