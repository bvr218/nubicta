<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\SalesDocument;
use App\Models\Seller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pestana "Ventas" de Siigo: listado de facturas y notas con estado DIAN, saldo y correo. */
class SalesDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $docs = SalesDocument::query()
            ->with('contact:id,name,identification,email')
            ->when($request->input('tipo'), fn ($q, $t) => $q->where('type', $t), fn ($q) => $q->whereIn('type', ['FV', 'NC', 'ND']))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%$t%")
                ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%$t%")->orWhere('identification', 'like', "$t%"))))
            ->when($request->input('desde'), fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->input('hasta'), fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->input('saldo') === 'pendiente', fn ($q) => $q->where('balance', '>', 0))
            ->when($request->integer('vendedor'), fn ($q, $id) => $q->where(fn ($w) => $w->where('seller_id', $id)->orWhereHas('items', fn ($i) => $i->where('seller_id', $id))))
            ->orderByDesc('date')->orderByDesc('number')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString()
            ->through(fn (SalesDocument $d) => [
                'id' => $d->id,
                'date' => $d->date->translatedFormat('j M Y'),
                'name' => $d->name,
                'type' => SalesDocument::TYPES[$d->type] ?? $d->type,
                'is_invoice' => $d->type === 'FV',
                'customer' => $d->contact?->name,
                'identification' => $d->contact?->identification,
                'total' => (float) $d->total,
                'balance' => (float) $d->balance,
                'currency' => $d->currency,
                'dian_status' => $d->dian_status,
                'mail_status' => $d->mail_status,
                'public_url' => $d->public_url,
                'annulled' => $d->status === 'annulled',
                'contact_id' => $d->contact_id,
                'email' => $d->contact?->email,
            ]);

        return Inertia::render('Sales/Index', [
            'documents' => $docs,
            'tabs' => DashboardController::tabs('ventas'),
            'filters' => [...$request->only('q', 'tipo', 'desde', 'hasta', 'saldo'), 'vendedor' => $request->input('vendedor') ?: ''],
            'sellers' => Seller::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
