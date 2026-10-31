<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\AccountsExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Account;
use App\Support\Download;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountController extends Controller
{
    private const CATEGORIES = [
        'Activos fijos', 'Caja - Bancos', 'Costo de ventas', 'Cuentas por cobrar', 'Cuentas por pagar', 'Gasto - Nómina',
        'Gastos', 'Ingresos', 'Inventarios', 'Orden', 'Otros activos', 'Otros gastos', 'Otros ingresos', 'Otros pasivos', 'Patrimonio',
    ];

    public function index(): Response
    {
        return Inertia::render('Accounting/Accounts', [
            'roots' => Account::whereNull('parent_id')->orderBy('code')->get()->map(fn ($a) => $this->node($a)),
            'categories' => self::CATEGORIES,
            'tabs' => DashboardController::tabs('contabilidad'),
        ]);
    }

    public function children(Account $account): JsonResponse
    {
        return response()->json($account->children()->withCount('children')->get()->map(fn ($a) => $this->node($a)));
    }

    /** "Expandir todos los detalles": el plan de cuentas completo anidado. */
    public function tree(): JsonResponse
    {
        $byParent = Account::query()->orderBy('code')->get(['id', 'parent_id', 'code', 'name', 'level', 'transactional', 'active'])->groupBy(fn ($a) => (int) $a->parent_id);
        $build = function (int $parent) use (&$build, $byParent) {
            return ($byParent[$parent] ?? collect())->map(fn ($a) => ['children' => $build($a->id)] + $this->node($a))
                ->map(fn ($n) => ['has_children' => (bool) $n['children']] + $n)->values()->all();
        };

        return response()->json($build(0));
    }

    public function search(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code'));
        $name = trim((string) $request->input('name'));

        $rows = Account::query()
            ->when($code !== '', fn ($q) => $q->where('code', 'like', "$code%"))
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%$name%"))
            ->orderBy('code')->limit(200)->get();

        return response()->json($rows->map(fn ($a) => $this->node($a)));
    }

    public function show(Account $account): JsonResponse
    {
        $codeChangeReason = $this->codeChangeReason($account);

        return response()->json([
            ...$account->toArray(),
            'level_name' => Account::LEVEL_NAMES[$account->level],
            'parent' => $account->parent?->label(),
            'can_change_code' => $codeChangeReason === null,
            'can_delete' => $codeChangeReason === null,
            'code_change_reason' => $codeChangeReason,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $parent = Account::findOrFail($data['parent_id']);

        if (! str_starts_with($data['code'], $parent->code) || strlen($data['code']) <= strlen($parent->code)) {
            throw ValidationException::withMessages(['code' => "El código debe iniciar con {$parent->code} y ser más largo."]);
        }
        if (Account::where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Ya existe una cuenta con este código.']);
        }

        $account = Account::create([
            ...$data,
            'level' => Account::levelForCode($data['code']),
            'nature' => Account::natureForCode($data['code']),
        ]);
        if ($parent->transactional) {
            $parent->update(['transactional' => false]);
        }

        return back()->with('success', 'Cuenta creada correctamente.')->with('account_tree_focus_id', $account->id);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);
        if (isset($data['code']) && $data['code'] !== $account->code) {
            if ($reason = $this->codeChangeReason($account)) {
                throw ValidationException::withMessages(['code' => $reason]);
            }
            if ($account->parent && (! str_starts_with($data['code'], $account->parent->code) || strlen($data['code']) <= strlen($account->parent->code))) {
                throw ValidationException::withMessages(['code' => "El código debe iniciar con {$account->parent->code} y ser más largo."]);
            }
            $data['level'] = Account::levelForCode($data['code']);
            $data['nature'] = Account::natureForCode($data['code']);
        } else {
            unset($data['code']);
        }
        unset($data['parent_id']);
        if ($account->children()->exists()) {
            $data['transactional'] = false;
        }
        $account->update($data);

        return back()->with('success', 'Cuenta actualizada correctamente.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        try {
            DB::transaction(function () use ($account) {
                $account = Account::query()->lockForUpdate()->findOrFail($account->id);
                if ($reason = $this->codeChangeReason($account)) {
                    throw ValidationException::withMessages(['account' => str_replace('cambiar el código', 'borrar la cuenta', $reason)]);
                }
                $account->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            throw ValidationException::withMessages(['account' => 'No se puede borrar la cuenta porque está vinculada a otra configuración.']);
        }

        return back()->with('success', 'Cuenta borrada correctamente.');
    }

    public function export(): BinaryFileResponse
    {
        return Download::export(new AccountsExport, 'Cuentas contables.xlsx', 'Listado de cuentas contables');
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        $codeRules = ['required', 'regex:/^\d{1,12}$/'];
        if ($account) {
            $codeRules[] = Rule::unique('accounts', 'code')->where('company_id', $account->company_id)->ignore($account->id);
        }

        return $request->validate([
            'parent_id' => [$account ? 'nullable' : 'required', 'exists:accounts,id'],
            'code' => $codeRules,
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'due_type' => ['nullable', Rule::in(['cartera', 'proveedores'])],
            'fiscal_difference' => ['boolean'],
            'transactional' => ['boolean'],
            'requires_third_party' => ['boolean'],
            'requires_cost_center' => ['boolean'],
            'active' => ['boolean'],
        ], [], ['code' => 'código', 'name' => 'nombre']);
    }

    private function codeChangeReason(Account $account): ?string
    {
        if (DB::table('ledger_lines')->where('company_id', $account->company_id)->where(function ($q) use ($account) {
            $q->where('account_id', $account->id)->orWhere('account_code', $account->code);
        })->exists() || DB::table('journal_lines')->where('company_id', $account->company_id)->where('account_id', $account->id)->exists()) {
            return 'No se puede cambiar el código porque la cuenta ya tiene movimientos contables.';
        }
        if ($account->children()->exists()) {
            return 'No se puede cambiar el código porque la cuenta tiene subcuentas.';
        }

        return null;
    }

    private function node(Account $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'name' => $a->name,
            'level' => $a->level,
            'transactional' => $a->transactional,
            'active' => $a->active,
            'has_children' => isset($a->children_count) ? $a->children_count > 0 : ! $a->transactional,
        ];
    }
}
