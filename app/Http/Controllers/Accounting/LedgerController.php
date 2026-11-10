<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Models\LedgerLine;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Reports\TablePdf;
use App\Services\Reports\XlsxStream;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Reportes del libro contable: auxiliar, balance de prueba, libro mayor, libro diario y estados financieros. */
class LedgerController extends Controller
{
    /** Longitudes de codigo por nivel PUC: clase, grupo, cuenta, subcuenta, auxiliar. */
    private const LEVELS = [1 => 1, 2 => 2, 3 => 4, 4 => 6, 5 => 8];

    private const LEVEL_NAMES = [1 => 'Clase', 2 => 'Grupo', 3 => 'Cuenta', 4 => 'Subcuenta', 5 => 'Auxiliar'];

    private function range(Request $request): array
    {
        $year = (int) date('Y');

        return [$request->input('desde', "$year-01-01"), $request->input('hasta', date('Y-m-d'))];
    }

    private function filtered(Request $request): Builder
    {
        return LedgerLine::query()
            ->when($request->input('cuenta_desde'), fn ($q, $c) => $q->where('account_code', '>=', $c))
            ->when($request->input('cuenta_hasta'), fn ($q, $c) => $q->where('account_code', '<=', $c.'99999999'))
            ->when($request->input('tercero'), fn ($q, $t) => $q->where(fn ($w) => $w->where('identification', $t)->orWhere('contact_name', 'like', "%$t%")))
            ->when($request->input('tipo'), fn ($q, $t) => $q->where('document_type', $t))
            ->when($request->integer('vendedor'), fn ($q, $id) => $q->whereExists(function ($w) use ($id) {
                $w->from('sales_documents as d')->whereColumn('d.company_id', 'ledger_lines.company_id')->whereColumn('d.name', 'ledger_lines.document')
                    ->where(fn ($s) => $s->where('d.seller_id', $id)->orWhereExists(fn ($i) => $i->from('sales_document_items as i')->whereColumn('i.sales_document_id', 'd.id')->where('i.seller_id', $id)));
            }));
    }

    /** Vendedor del comprobante de venta que originó la línea (encabezado o, si no tiene, el del ítem). */
    private function sellerNameSql(): string
    {
        return "(select s.name from sales_documents d join sellers s on s.id = coalesce(d.seller_id, (select i.seller_id from sales_document_items i where i.sales_document_id = d.id and i.seller_id is not null order by i.position limit 1)) where d.company_id = ledger_lines.company_id and d.name = ledger_lines.document limit 1)";
    }

    private function names(): array
    {
        return Account::pluck('name', 'code')->all();
    }

    private function base(Request $request, string $title): array
    {
        [$from, $to] = $this->range($request);

        return [
            'title' => $title,
            'tabs' => DashboardController::tabs('contabilidad'),
            'filters' => [...$request->only('cuenta_desde', 'cuenta_hasta', 'tercero', 'tipo', 'nivel', 'por_tercero'), 'desde' => $from, 'hasta' => $to, 'vendedor' => $request->input('vendedor') ?: ''],
            'sellers' => Seller::query()->orderBy('name')->get(['id', 'name']),
            'types' => LedgerLine::query()->distinct()->orderBy('document_type')->pluck('document_type'),
            'years' => LedgerLine::query()->selectRaw('MIN(YEAR(date)) a, MAX(YEAR(date)) b')->first(),
        ];
    }

    public function auxiliary(Request $request): Response|BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $query = $this->filtered($request)->whereBetween('date', [$from, $to])
            ->select('*')->selectRaw($this->sellerNameSql().' as seller')
            ->selectRaw('SUM(debit - credit) OVER (PARTITION BY account_code, identification, branch ORDER BY date, id) AS running')
            ->orderBy('account_code')->orderBy('identification')->orderBy('date')->orderBy('id');

        $openings = fn (array $keys) => $keys ? $this->filtered($request)->where('date', '<', $from)
            ->where(fn ($w) => collect($keys)->each(fn ($k) => $w->orWhere(fn ($x) => $x->where('account_code', $k[0])->where('identification', $k[1]))))
            ->groupBy('account_code', 'identification', 'branch')
            ->selectRaw('account_code, identification, branch, SUM(debit - credit) AS bal')->get()
            ->mapWithKeys(fn ($r) => ["$r->account_code|$r->identification|$r->branch" => (float) $r->bal])->all() : [];

        $names = $this->names();
        $seen = [];
        $map = function ($lines, ?array $open = null) use ($openings, $names, &$seen) {
            $open ??= $openings($lines->map(fn ($l) => [$l->account_code, $l->identification])->unique()->values()->all());

            return $lines->map(function ($l) use ($open, $names, &$seen) {
                $key = "$l->account_code|$l->identification|$l->branch";
                $opening = $open[$key] ?? 0;
                $first = ! isset($seen[$key]);
                $seen[$key] = true;

                return [
                    'identification' => $l->identification, 'branch' => $l->branch, 'contact' => $l->contact_name, 'seller' => $l->seller,
                    'code' => $l->account_code, 'account' => $names[$l->account_code] ?? '', 'document' => $l->document,
                    'date' => $l->date->format('d/m/Y'), 'opening' => $first ? $opening : null,
                    'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'balance' => $opening + (float) $l->running,
                ];
            });
        };

        if ($format = $this->format($request)) {
            $columns = [['Identificación', 'text'], ['Sucursal', 'text'], ['Nombre tercero', 'text'], ['Vendedor', 'text'], ['Código cuenta contable', 'text'], ['Cuenta contable', 'text'],
                ['Comprobante', 'text'], ['Fecha elaboración', 'date'], ['Saldo inicial', 'money'], ['Débito', 'money'], ['Crédito', 'money'], ['Nuevo saldo', 'money']];
            $open = $this->filtered($request)->where('date', '<', $from)->groupBy('account_code', 'identification', 'branch')
                ->selectRaw('account_code, identification, branch, SUM(debit - credit) AS bal')->toBase()->get()
                ->mapWithKeys(fn ($r) => ["$r->account_code|$r->identification|$r->branch" => (float) $r->bal])->all();
            $lines = (clone $query)->reorder()->select('id', 'account_code', 'identification', 'branch', 'contact_name', 'document', 'date', 'debit', 'credit')
                ->selectRaw($this->sellerNameSql().' as seller')
                ->selectRaw('SUM(debit - credit) OVER (PARTITION BY account_code, identification, branch ORDER BY date, id) AS running')
                ->orderBy('account_code')->orderBy('identification')->orderBy('date')->orderBy('id');
            $rows = function () use ($lines, $map, $open) {
                foreach ($lines->cursor()->chunk(1000) as $chunk) {
                    yield from $map($chunk, $open)->all();
                }
            };
            $t = $this->filtered($request)->whereBetween('date', [$from, $to])->selectRaw('SUM(debit) d, SUM(credit) c')->first();

            return $this->export($request, $format, 'libro_auxiliar', 'Movimiento auxiliar por cuenta contable', $columns, $rows(),
                [null, null, null, null, null, null, null, null, null, (float) $t->d, (float) $t->c, null]);
        }

        $page = $query->paginate($request->integer('per_page', 50))->withQueryString();
        $page->setCollection($map($page->getCollection()));
        $totals = $this->filtered($request)->whereBetween('date', [$from, $to])->selectRaw('SUM(debit) d, SUM(credit) c')->first();

        return Inertia::render('Accounting/Ledger/Auxiliary', [
            ...$this->base($request, 'Movimiento auxiliar por cuenta contable'),
            'lines' => $page,
            'totals' => ['debit' => (float) $totals->d, 'credit' => (float) $totals->c],
        ]);
    }

    /** Saldos por cuenta (y tercero) rodados a los niveles PUC hasta el nivel pedido. */
    public function balances(Request $request, int $level, bool $byContact, bool $allLevels = true): array
    {
        [$from, $to] = $this->range($request);
        $rows = $this->filtered($request)->where('date', '<=', $to)
            ->groupBy('account_code')->when($byContact, fn ($q) => $q->groupBy('identification', 'contact_name'))
            ->selectRaw('account_code'.($byContact ? ', identification, contact_name' : '').',
                SUM(CASE WHEN date < ? THEN debit - credit ELSE 0 END) AS opening,
                SUM(CASE WHEN date >= ? THEN debit ELSE 0 END) AS debit,
                SUM(CASE WHEN date >= ? THEN credit ELSE 0 END) AS credit', [$from, $from, $from])
            ->get();

        $names = $this->names();
        $out = [];
        foreach ($rows as $r) {
            $levels = $allLevels ? range(1, $level) : [$level];
            foreach ($levels as $lv) {
                $len = self::LEVELS[$lv];
                if ($lv < 5 && strlen($r->account_code) < $len) {
                    continue;
                }
                $code = $lv === 5 ? $r->account_code : substr($r->account_code, 0, $len);
                $leaf = $lv === $level;
                $key = $code.($byContact && $leaf ? '|'.$r->identification : '');
                $out[$key] ??= [
                    'code' => $code, 'name' => $names[$code] ?? '', 'level' => $lv,
                    'identification' => $byContact && $leaf ? $r->identification : null,
                    'contact' => $byContact && $leaf ? $r->contact_name : null,
                    'opening' => 0, 'debit' => 0, 'credit' => 0,
                ];
                $out[$key]['opening'] += (float) $r->opening;
                $out[$key]['debit'] += (float) $r->debit;
                $out[$key]['credit'] += (float) $r->credit;
            }
        }
        ksort($out, SORT_STRING);

        return array_values(array_filter(array_map(function ($r) {
            $r['balance'] = round($r['opening'] + $r['debit'] - $r['credit'], 2);

            return $r;
        }, $out), fn ($r) => abs($r['opening']) > 0.004 || $r['debit'] || $r['credit']));
    }

    public function trialBalance(Request $request): Response|BinaryFileResponse
    {
        $level = max(1, min(5, $request->integer('nivel', 4)));
        $byContact = $request->boolean('por_tercero');
        $rows = $this->balances($request, $level, $byContact);

        $classes = array_filter($rows, fn ($r) => $r['level'] === 1);
        if ($format = $this->format($request)) {
            $columns = [['Nivel', 'text'], ['Código cuenta contable', 'text'], ['Nombre cuenta contable', 'text'], ['Identificación', 'text'], ['Nombre tercero', 'text'],
                ['Saldo inicial', 'money'], ['Movimiento débito', 'money'], ['Movimiento crédito', 'money'], ['Saldo final', 'money']];
            $sum = fn ($k) => array_sum(array_column($classes, $k));

            return $this->export($request, $format, 'balance_de_prueba', 'Balance de prueba general', $columns,
                array_map(fn ($r) => [self::LEVEL_NAMES[$r['level']], $r['code'], $r['name'], $r['identification'], $r['contact'], $r['opening'], $r['debit'], $r['credit'], $r['balance']], $rows),
                [null, null, null, null, null, $sum('opening'), $sum('debit'), $sum('credit'), $sum('balance')]);
        }

        return Inertia::render('Accounting/Ledger/TrialBalance', [
            ...$this->base($request, 'Balance de prueba general'),
            'rows' => $rows,
            'levels' => self::LEVEL_NAMES,
            'totals' => [
                'opening' => array_sum(array_column($classes, 'opening')), 'debit' => array_sum(array_column($classes, 'debit')),
                'credit' => array_sum(array_column($classes, 'credit')), 'balance' => array_sum(array_column($classes, 'balance')),
            ],
        ]);
    }

    public function generalLedger(Request $request): Response|BinaryFileResponse
    {
        $rows = $this->balances($request, 3, false, false);
        if ($format = $this->format($request)) {
            $columns = [['Código', 'text'], ['Cuenta', 'text'], ['Saldo anterior débito', 'money'], ['Saldo anterior crédito', 'money'], ['Movimiento débito', 'money'],
                ['Movimiento crédito', 'money'], ['Nuevo saldo débito', 'money'], ['Nuevo saldo crédito', 'money']];
            $data = array_map(fn ($r) => [$r['code'], $r['name'], max($r['opening'], 0), max(-$r['opening'], 0), $r['debit'], $r['credit'], max($r['balance'], 0), max(-$r['balance'], 0)], $rows);
            $sum = fn ($i) => array_sum(array_column($data, $i));

            return $this->export($request, $format, 'libro_mayor_y_balance', 'Libro Mayor y Balance', $columns, $data,
                [null, null, $sum(2), $sum(3), $sum(4), $sum(5), $sum(6), $sum(7)]);
        }

        return Inertia::render('Accounting/Ledger/GeneralLedger', [
            ...$this->base($request, 'Libro Mayor y Balance'),
            'rows' => $rows,
        ]);
    }

    public function journalBook(Request $request): Response|BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $query = $this->filtered($request)->whereBetween('date', [$from, $to])
            ->groupBy('date', 'document_type', 'account_code')
            ->selectRaw('date, document_type, account_code, SUM(debit) AS debit, SUM(credit) AS credit, COUNT(DISTINCT document) AS documents')
            ->orderBy('date')->orderBy('document_type')->orderBy('account_code');
        $names = $this->names();
        $map = fn ($r) => [
            'date' => \Illuminate\Support\Carbon::parse($r->date)->format('d/m/Y'), 'type' => $r->document_type,
            'code' => $r->account_code, 'account' => $names[$r->account_code] ?? '', 'documents' => (int) $r->documents,
            'debit' => (float) $r->debit, 'credit' => (float) $r->credit,
        ];
        if ($format = $this->format($request)) {
            $columns = [['Fecha', 'date'], ['Tipo comprobante', 'text'], ['Código cuenta', 'text'], ['Cuenta', 'text'], ['Comprobantes', 'count'], ['Débito', 'money'], ['Crédito', 'money']];
            $t = $this->filtered($request)->whereBetween('date', [$from, $to])->selectRaw('SUM(debit) d, SUM(credit) c')->first();
            $rows = function () use ($query, $map) {
                foreach ($query->cursor() as $r) {
                    yield array_values($map($r));
                }
            };

            return $this->export($request, $format, 'libro_diario', 'Libro diario', $columns, $rows(), [null, null, null, null, null, (float) $t->d, (float) $t->c]);
        }
        $page = $query->paginate($request->integer('per_page', 50))->withQueryString()->through($map);
        $totals = $this->filtered($request)->whereBetween('date', [$from, $to])->selectRaw('SUM(debit) d, SUM(credit) c')->first();

        return Inertia::render('Accounting/Ledger/JournalBook', [
            ...$this->base($request, 'Libro diario'),
            'lines' => $page,
            'totals' => ['debit' => (float) $totals->d, 'credit' => (float) $totals->c],
        ]);
    }

    public function financialPosition(Request $request): Response
    {
        $request->merge(['desde' => '1900-01-01']);
        $rows = array_values(array_filter($this->balances($request, 3, false), fn ($r) => in_array($r['code'][0], ['1', '2', '3'], true)));
        $result = -array_sum(array_map(fn ($r) => $r['balance'], array_filter($this->balances($request, 1, false), fn ($r) => in_array($r['code'], ['4', '5', '6', '7'], true))));
        $total = fn ($c) => array_sum(array_map(fn ($r) => $r['balance'], array_filter($rows, fn ($r) => $r['level'] === 1 && $r['code'] === $c)));

        return Inertia::render('Accounting/Ledger/Statement', [
            ...$this->base($request, 'Estado de situación financiera'),
            'kind' => 'situacion',
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total activo', 'value' => $total('1')],
                ['label' => 'Total pasivo', 'value' => -$total('2')],
                ['label' => 'Total patrimonio', 'value' => -$total('3')],
                ['label' => 'Resultado del ejercicio', 'value' => $result],
                ['label' => 'Total pasivo + patrimonio + resultado', 'value' => -$total('2') - $total('3') + $result],
            ],
        ]);
    }

    public function incomeStatement(Request $request): Response
    {
        $all = $this->balances($request, 3, false);
        $rows = array_values(array_filter($all, fn ($r) => in_array($r['code'][0], ['4', '5', '6', '7'], true)));
        foreach ($rows as &$r) {
            $r['period'] = round($r['debit'] - $r['credit'], 2);
        }
        unset($r);
        $sum = fn ($c) => array_sum(array_map(fn ($r) => $r['period'], array_filter($rows, fn ($r) => $r['level'] === 1 && $r['code'] === $c)));

        return Inertia::render('Accounting/Ledger/Statement', [
            ...$this->base($request, 'Estado de resultado integral'),
            'kind' => 'resultados',
            'rows' => $rows,
            'summary' => [
                ['label' => 'Ingresos', 'value' => -$sum('4')],
                ['label' => 'Gastos', 'value' => $sum('5')],
                ['label' => 'Costos de ventas', 'value' => $sum('6')],
                ['label' => 'Costos de producción', 'value' => $sum('7')],
                ['label' => 'Utilidad (pérdida) del ejercicio', 'value' => -$sum('4') - $sum('5') - $sum('6') - $sum('7')],
            ],
        ]);
    }

    /** Formato de descarga pedido: ?formato=xlsx|pdf (export=1 se mantiene como Excel). */
    private function format(Request $request): ?string
    {
        $f = $request->input('formato') ?? ($request->boolean('export') ? 'xlsx' : null);

        return in_array($f, ['xlsx', 'pdf'], true) ? $f : null;
    }

    /**
     * Descarga en Excel (escrito fila a fila, sin limite practico) o PDF con el encabezado de la empresa.
     *
     * @param  list<array{0:string,1:string}>  $columns
     * @param  list<float|null>  $totals  alineado con las columnas
     */
    private function export(Request $request, string $format, string $file, string $title, array $columns, iterable $rows, array $totals): BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $d = fn ($v) => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y');
        $filters = array_filter([
            'Fecha' => $d($from).' a '.$d($to),
            'Cuenta desde' => $request->input('cuenta_desde'), 'Cuenta hasta' => $request->input('cuenta_hasta'),
            'Tercero' => $request->input('tercero'), 'Tipo de comprobante' => $request->input('tipo'),
            'Vendedor' => $request->integer('vendedor') ? Seller::query()->whereKey($request->integer('vendedor'))->value('name') : null,
            'Nivel' => $request->filled('nivel') ? (self::LEVEL_NAMES[(int) $request->input('nivel')] ?? null) : null,
        ], fn ($v) => filled($v));
        \Illuminate\Support\Facades\DB::table('report_exports')->insert([
            'company_id' => \App\Support\CurrentCompany::id(), 'user_id' => auth()->id(), 'slug' => $file, 'title' => $title,
            'format' => $format, 'url' => mb_substr($request->fullUrl(), 0, 2000), 'rows_count' => is_array($rows) ? count($rows) : 0, 'created_at' => now(),
        ]);
        if ($format === 'pdf') {
            return TablePdf::download("$file.pdf", $title, $columns, $rows, $totals, $filters);
        }
        $book = new XlsxStream($title, $columns, $title, implode('   ', array_map(fn ($k, $v) => "$k: $v", array_keys($filters), $filters)));
        foreach ($rows as $r) {
            $book->add($r);
        }
        $book->totals(array_filter($totals, fn ($v) => $v !== null));

        return $book->download("$file.xlsx");
    }
}
