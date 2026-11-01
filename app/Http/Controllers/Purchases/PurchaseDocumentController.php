<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseDocumentController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $last15 = ! $request->hasAny(['q', 'desde', 'hasta', 'saldo', 'tipo', 'page', 'todos']);
        if ($last15) {
            $request->merge(['desde' => now()->subDays(14)->toDateString(), 'hasta' => now()->toDateString()]);
        }
        $cid = CurrentCompany::id();
        $tipo = $request->input('tipo');
        $q = $request->input('q');
        $match = fn ($w, string $name) => $w->where($name, 'like', "%$q%")->orWhere('c.name', 'like', "%$q%")->orWhere('c.identification', 'like', "$q%");
        $docs = DB::table('purchase_documents as d')->join('contacts as c', 'c.id', '=', 'd.contact_id')->where('d.company_id', $cid)
            ->when($tipo, fn ($w) => $w->where('d.type', $tipo), fn ($w) => $w->where('d.type', '!=', 'SI'))
            ->when($q, fn ($w) => $w->where(fn ($x) => $match($x, 'd.name')->orWhere('d.supplier_number', 'like', "%$q%")))
            ->when($request->input('desde'), fn ($w, $d) => $w->whereDate('d.date', '>=', $d))
            ->when($request->input('hasta'), fn ($w, $d) => $w->whereDate('d.date', '<=', $d))
            ->when($request->input('saldo') === 'pendiente', fn ($w) => $w->where('d.balance', '>', 0))
            ->selectRaw("d.id, 'compra' kind, if(d.type = 'DS', 'Documento soporte', 'Compra/Gasto') transaction, if(d.type = 'DS', 2, 1) ord, d.name, timestamp(d.date) date, d.created_at,
                trim(both '-' from concat(coalesce(d.supplier_prefix, ''), '-', coalesce(d.supplier_number, ''))) supplier_invoice, d.contact_id, c.name supplier,
                c.identification, c.branch_office, if(d.type = 'DS', d.dian_status, 'No aplica') dian_status, d.status, d.total, d.currency, d.balance");
        $payments = DB::table('receipts as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')->where('r.company_id', $cid)->where('r.type', 'RP')
            ->when($tipo && $tipo !== 'RP', fn ($w) => $w->whereRaw('1 = 0'))->when($request->input('saldo') === 'pendiente', fn ($w) => $w->whereRaw('1 = 0'))
            ->when($q, fn ($w) => $w->where(fn ($x) => $match($x, 'r.name')))
            ->when($request->input('desde'), fn ($w, $d) => $w->whereDate('r.date', '>=', $d))
            ->when($request->input('hasta'), fn ($w, $d) => $w->whereDate('r.date', '<=', $d))
            ->selectRaw("r.id, 'pago' kind, 'Pagos' transaction, 3 ord, r.name, timestamp(r.date, time(r.created_at)) date, r.created_at, null supplier_invoice, r.contact_id, c.name supplier,
                c.identification, c.branch_office, null dian_status, r.status, r.total, 'COP' currency, null balance");
        $union = $tipo === 'RP' ? $payments : $docs->unionAll($payments);

        $docs = DB::query()->fromSub($union, 't')->orderBy('ord')->orderByDesc('date')->orderBy('created_at')->orderBy('id')
            ->paginate($request->integer('per_page', 50))->withQueryString()
            ->through(fn ($d) => [
                'id' => $d->id,
                'url' => $d->kind === 'pago' ? "/documentos/recibos/{$d->id}" : "/documentos/compras/{$d->id}",
                'date' => Carbon::parse($d->date)->format('d/m/Y'),
                'name' => $d->name,
                'transaction' => $d->transaction,
                'supplier_invoice' => $d->supplier_invoice,
                'supplier' => $d->supplier,
                'contact_id' => $d->contact_id,
                'identification' => $d->identification,
                'branch' => $d->branch_office ?: '-',
                'dian_status' => $d->dian_status,
                'annulled' => $d->status === 'annulled',
                'total' => (float) $d->total,
                'currency' => $d->currency ?: 'COP',
                'balance' => $d->balance === null ? null : (float) $d->balance,
            ]);

        return Inertia::render('Purchases/Index', [
            'documents' => $docs,
            'tabs' => DashboardController::tabs('compras'),
            'filters' => $request->only('q', 'desde', 'hasta', 'saldo', 'tipo'),
            'last15' => $last15,
        ]);
    }
}
