<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Contact;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Services\Mail\CompanyMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Pestanas "Clientes" (Ventas) y "Proveedores" (Compras) de Siigo: saldo de cartera por tercero. */
class PortfolioController extends Controller
{
    private const SIDES = [
        'clientes' => ['model' => SalesDocument::class, 'table' => 'sales_documents', 'types' => ['FV', 'ND', 'SI', 'NC'], 'role' => 'is_customer', 'module' => 'ventas', 'tab' => 'Clientes', 'url' => '/ventas/clientes', 'party' => 'Cliente', 'total' => 'Total cartera', 'create' => '/terceros/crear?tipo=cliente', 'create_label' => 'Crear cliente'],
        'proveedores' => ['model' => PurchaseDocument::class, 'table' => 'purchase_documents', 'types' => ['FC', 'DS', 'NC', 'ND', 'NA', 'SI'], 'role' => 'is_supplier', 'module' => 'compras', 'tab' => 'Proveedores', 'url' => '/compras/proveedores', 'party' => 'Proveedor', 'total' => 'Total saldo', 'create' => '/terceros/crear?tipo=proveedor', 'create_label' => 'Crear Proveedor', 'balance_label' => 'Saldo de proveedor', 'action_label' => 'Ver cuentas por pagar'],
    ];

    public function customers(Request $request): Response
    {
        return $this->index($request, 'clientes');
    }

    public function suppliers(Request $request): Response
    {
        return $this->index($request, 'proveedores');
    }

    public function customerStatement(Request $request, Contact $contact): Response
    {
        return $this->statement($request, 'clientes', $contact);
    }

    public function supplierStatement(Request $request, Contact $contact): Response
    {
        return $this->statement($request, 'proveedores', $contact);
    }

    private function index(Request $request, string $side): Response
    {
        $cfg = self::SIDES[$side];
        $today = now()->toDateString();
        $balances = DB::table($cfg['table'])
            ->where('company_id', $request->user()->company_id)
            ->whereIn('type', $cfg['types'])
            ->where('status', '!=', 'annulled')
            ->where('balance', '!=', 0)
            ->groupBy('contact_id')
            ->selectRaw('contact_id, SUM(CASE WHEN balance > 0 AND due_date <= ? THEN balance ELSE 0 END) AS overdue, SUM(CASE WHEN balance > 0 AND (due_date IS NULL OR due_date > ?) THEN balance ELSE 0 END) AS current_due, SUM(CASE WHEN balance < 0 THEN balance ELSE 0 END) AS credit', [$today, $today]);

        $estado = $request->input('estado', 'activo');
        $saldo = $request->input('saldo', 'todos');

        $rows = Contact::query()
            ->leftJoinSub($balances, 'b', 'b.contact_id', '=', 'contacts.id')
            ->where(fn ($q) => $q->where("contacts.{$cfg['role']}", true)->orWhereNotNull('b.contact_id'))
            ->when($estado !== 'todos', fn ($q) => $q->where('contacts.active', $estado === 'activo'))
            ->when($saldo === 'con_saldo', fn ($q) => $q->whereNotNull('b.contact_id'))
            ->when($saldo === 'vencido', fn ($q) => $q->where('b.overdue', '>', 0))
            ->when($request->input('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('contacts.name', 'like', "%$t%")->orWhere('contacts.identification', 'like', "$t%")))
            ->orderByRaw('b.contact_id IS NULL')
            ->orderBy('contacts.name')
            ->select('contacts.id', 'contacts.name', 'contacts.identification', 'contacts.branch_office', 'contacts.active', DB::raw('COALESCE(b.overdue,0) AS overdue'), DB::raw('COALESCE(b.current_due,0) AS current_due'), DB::raw('COALESCE(b.credit,0) AS credit'))
            ->paginate($request->integer('per_page', 50))
            ->withQueryString()
            ->through(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'identification' => $c->identification,
                'branch' => $c->branch_office ? (string) $c->branch_office : '-',
                'overdue' => (float) $c->overdue,
                'current' => (float) $c->current_due,
                'credit' => (float) $c->credit,
                'total' => round((float) $c->overdue + (float) $c->current_due + (float) $c->credit, 2),
                'active' => (bool) $c->active,
            ]);

        return Inertia::render('Portfolio/Index', [
            'side' => $side,
            'cfg' => collect($cfg)->only(['party', 'total', 'url', 'create', 'create_label', 'tab', 'balance_label', 'action_label'])->all(),
            'rows' => $rows,
            'tabs' => DashboardController::tabs($cfg['module']),
            'filters' => ['q' => $request->input('q', ''), 'estado' => $estado, 'saldo' => $saldo],
        ]);
    }

    private function statement(Request $request, string $side, Contact $contact): Response
    {
        $cfg = self::SIDES[$side];

        return Inertia::render('Portfolio/Statement', [
            'side' => $side,
            'cfg' => collect($cfg)->only(['party', 'url', 'tab'])->all(),
            'contact' => ['id' => $contact->id, 'name' => $contact->name, 'identification' => $contact->identificationLabel(), 'email' => $contact->email, 'phone' => trim(($contact->phone_indicative ?? '').' '.($contact->phone ?? ''))],
            'docs' => $this->dues($side, $contact, $request->input('todos') === '1'),
            'todos' => $request->input('todos') === '1',
            'tabs' => DashboardController::tabs($cfg['module']),
        ]);
    }

    /** Envia por correo el estado de cuenta del cliente (boton "Enviar estado de cuenta"). */
    public function sendStatement(Request $request, Contact $contact): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $companyId = $request->user()->company_id;
        if (! CompanyMailer::configured($companyId)) {
            return back()->with('error', 'Configura el servidor de correo en Configuración > Correos');
        }
        if (CompanyMailer::blocked($companyId, $data['email'])) {
            return back()->with('error', "El correo {$data['email']} está bloqueado");
        }
        $docs = $this->dues('clientes', $contact, false);
        $company = $request->user()->company;
        $from = $company?->business_name ?: trim("{$company?->first_name} {$company?->last_name}");
        [$fromEmail, $fromName] = CompanyMailer::from($companyId, $from);
        $html = view('mail.statement', ['contact' => $contact, 'docs' => $docs, 'company' => $from, 'date' => now()->translatedFormat('j \d\e F \d\e Y'),
            'totals' => ['debt' => $docs->sum('debt'), 'favor' => $docs->sum('favor'), 'overdue' => $docs->sum('overdue'), 'upcoming' => $docs->sum('upcoming')]])->render();
        try {
            CompanyMailer::mailer($companyId)->send([], [], function (Message $m) use ($data, $from, $fromEmail, $fromName, $company, $html) {
                $m->from($fromEmail, $fromName)->to($data['email'])->subject("Estado de cuenta - {$from}");
                if ($company?->email) {
                    $m->replyTo($company->email, $from);
                }
                $m->html($html);
            });
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo enviar: '.mb_substr($e->getMessage(), 0, 200));
        }

        return back()->with('success', "Estado de cuenta enviado a {$data['email']}");
    }

    /** Vencimientos del tercero con las columnas del estado de cuenta (saldo negativo = valor a favor). */
    public function dues(string $side, Contact $contact, bool $all): Collection
    {
        $cfg = self::SIDES[$side];
        $today = now()->startOfDay();

        return $cfg['model']::query()
            ->where('contact_id', $contact->id)
            ->whereIn('type', $cfg['types'])
            ->where('status', '!=', 'annulled')
            ->when(! $all, fn ($q) => $q->where('balance', '!=', 0))
            ->orderByRaw('COALESCE(due_date, date)')->orderBy('number')
            ->get()
            ->map(function ($d) use ($side, $today) {
                $balance = (float) $d->balance;
                $due = $d->due_date ?? $d->date;
                $days = $balance > 0 && $due ? (int) $due->copy()->startOfDay()->diffInDays($today, false) : null;

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'type' => $d->type,
                    'supplier_doc' => $side === 'proveedores' && $d->supplier_number ? trim($d->supplier_prefix.'-'.$d->supplier_number, '-') : null,
                    'date' => $d->date->translatedFormat('j M Y'),
                    'due' => $d->due_date?->translatedFormat('j M Y'),
                    'quote' => 1,
                    'total' => (float) $d->total,
                    'balance' => $balance,
                    'debt' => max(0, $balance),
                    'favor' => min(0, $balance),
                    'overdue' => $days !== null && $days >= 0 ? $balance : 0,
                    'upcoming' => $days !== null && $days < 0 ? $balance : 0,
                    'days' => $days,
                ];
            });
    }
}
