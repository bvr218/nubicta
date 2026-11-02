<?php

namespace App\Http\Controllers\Contacts;

use App\Exports\ContactsExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\Contact;
use App\Models\Municipality;
use App\Models\PriceList;
use App\Models\Seller;
use App\Support\ColombianNit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ContactController extends Controller
{
    private const TITLES = ['cliente' => 'Clientes', 'proveedor' => 'Proveedores', 'empleado' => 'Empleados', 'otro' => 'Otros'];

    public function index(Request $request): Response
    {
        $role = $request->input('tipo');
        $contacts = $this->query($request)
            ->with('municipality:code,name')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString()
            ->through(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'identification' => $c->identificationLabel(),
                'identification_type' => config('erp.identification_types')[$c->identification_type] ?? $c->identification_type,
                'branch_office' => $c->branch_office,
                'roles' => $c->rolesLabel(),
                'phone' => $c->mobile ?: $c->phone,
                'email' => $c->email,
                'city' => $c->municipality?->name,
                'active' => $c->active,
            ]);

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'filters' => $request->only('q', 'tipo', 'estado'),
            'title' => self::TITLES[$role] ?? 'Clientes, proveedores y otros',
            'module' => match ($role) {
                'cliente' => ['title' => 'Ventas', 'tabs' => DashboardController::tabs('ventas'), 'active' => 'Clientes'],
                'proveedor' => ['title' => 'Compras y gastos', 'tabs' => DashboardController::tabs('compras'), 'active' => 'Proveedores'],
                'empleado' => ['title' => 'Nómina', 'tabs' => DashboardController::tabs('nomina'), 'active' => 'Empleados'],
                default => null,
            },
            'counts' => [
                'cliente' => Contact::where('is_customer', true)->count(),
                'proveedor' => Contact::where('is_supplier', true)->count(),
                'empleado' => Contact::where('is_employee', true)->count(),
                'otro' => Contact::where('is_other', true)->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $role = $request->input('tipo', 'cliente');

        return $this->form(null, array_key_exists($role, Contact::ROLES) ? $role : 'cliente');
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = null;
        $contact = DB::transaction(function () use ($request, &$employee) {
            $data = $this->validated($request);
            $contact = Contact::create($this->attributes($data));
            $this->syncPeople($contact, $data['people'] ?? []);
            $employee = $contact->is_employee ? \App\Models\Employee::enroll($contact) : null;

            return $contact;
        });
        if ($employee && ! $request->input('volver')) {
            return redirect("/nomina/empleados/{$employee->id}/editar")->with('success', 'Empleado creado. Completa su contrato y su información de nómina.');
        }

        return redirect($request->input('volver') ?: "/terceros/{$contact->id}/editar")->with('success', 'Tercero creado correctamente.');
    }

    /** Perfil del tercero con las pestañas de Siigo: Perfil, Cuentas por cobrar, Cuentas por pagar, Contactos y Tareas. */
    public function show(Request $request, Contact $contact): Response
    {
        $tab = in_array($request->input('pestana'), ['cobrar', 'pagar', 'contactos', 'tareas'], true) ? $request->input('pestana') : 'perfil';
        $todos = $request->input('todos') === '1';
        $city = Municipality::with('department')->find($contact->municipality_code);
        $phone = fn ($ind, $num) => trim(($ind ? "$ind " : '').($num ?? ''));

        return Inertia::render('Contacts/Profile', [
            'tab' => $tab,
            'todos' => $todos,
            'contact' => [
                'id' => $contact->id, 'name' => $contact->name, 'identification' => $contact->identificationLabel(), 'number' => $contact->identification,
                'dv' => $contact->dv, 'branch' => (string) ($contact->branch_office ?? 0), 'active' => (bool) $contact->active,
                'person' => $contact->person_type === 'juridica' ? 'Empresa' : 'Es persona',
                'id_type' => config('erp.identification_types')[$contact->identification_type] ?? $contact->identification_type,
                'business_name' => $contact->person_type === 'juridica' ? $contact->business_name : null,
                'first_name' => $contact->first_name, 'last_name' => $contact->last_name, 'trade_name' => $contact->trade_name,
                'city' => $city?->name, 'address' => $contact->address, 'phone_indicative' => $contact->phone_indicative, 'phone_number' => $contact->phone,
                'phone_extension' => $contact->phone_extension, 'mobile' => $contact->mobile,
                'phone' => $phone($contact->phone_indicative, $contact->phone), 'email' => $contact->email,
                'billing_first_name' => $contact->billing_first_name, 'billing_last_name' => $contact->billing_last_name,
                'regime' => $contact->vat_responsible ? 'Responsable de IVA' : 'No responsable de IVA', 'postal_code' => $contact->postal_code,
                'responsibilities' => collect($contact->fiscal_responsibilities ?? [])->map(fn ($r) => $r.' - '.(config('erp.fiscal_responsibilities')[$r] ?? $r))->values(),
                'seller' => $contact->seller?->name, 'collector' => $contact->collector?->name, 'observations' => $contact->observations,
                'is_customer' => (bool) $contact->is_customer, 'is_supplier' => (bool) $contact->is_supplier, 'is_other' => (bool) ($contact->is_other || $contact->is_employee),
                'created' => $contact->created_at?->format('j/m/Y H:i').' hrs', 'updated' => $contact->updated_at?->format('j/m/Y H:i').' hrs',
            ],
            'docs' => in_array($tab, ['cobrar', 'pagar'], true)
                ? app(\App\Http\Controllers\Sales\PortfolioController::class)->dues($tab === 'cobrar' ? 'clientes' : 'proveedores', $contact, $todos) : [],
            'people' => $tab === 'contactos' ? $contact->people()->orderBy('id')->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => trim("$p->first_name $p->last_name"), 'position' => $p->position, 'office' => null,
                'mobile' => $p->phone ? '('.($p->phone_indicative ?: '000').') '.$p->phone : null, 'email' => $p->email,
            ]) : [],
            'tasks' => $tab === 'tareas' ? \App\Models\CrmTask::where('contact_id', $contact->id)->orderByDesc('id')->get()->map(fn ($t) => [
                'id' => $t->id, 'category' => $t->category, 'stage' => $t->stage, 'description' => $t->description, 'due' => $t->due_date?->format('d/m/Y'),
                'created' => $t->created_at?->format('d/m/Y'), 'updated' => $t->updated_at?->format('d/m/Y'), 'status' => $t->status,
            ]) : [],
        ]);
    }

    public function edit(Contact $contact): Response
    {
        return $this->form($contact->load('people'), null);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        DB::transaction(function () use ($request, $contact) {
            $data = $this->validated($request, $contact);
            $contact->update($this->attributes($data));
            $this->syncPeople($contact, $data['people'] ?? []);
            $contact->is_employee && \App\Models\Employee::enroll($contact);
        });

        return back()->with('success', 'Tercero actualizado correctamente.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $title = self::TITLES[$request->input('tipo')] ?? 'Terceros';

        return \App\Support\Download::export(new ContactsExport($this->query($request)), $title.'.xlsx', $title);
    }

    /** Busqueda rapida para selectores de documentos. */
    public function search(Request $request): JsonResponse
    {
        return response()->json(
            Contact::query()->search($request->input('q'))->role($request->input('tipo'))->where('active', true)
                ->orderBy('name')->limit(20)
                ->get(['id', 'name', 'identification', 'dv', 'identification_type', 'branch_office', 'email', 'price_list_id', 'seller_id', 'credit_days'])
        );
    }

    /** Datos DV para "Autocompletar" del formulario. */
    public function dv(Request $request): JsonResponse
    {
        $id = preg_replace('/\D/', '', (string) $request->input('identificacion'));

        return response()->json(['dv' => $id !== '' ? ColombianNit::verificationDigit($id) : null]);
    }

    private function query(Request $request)
    {
        return Contact::query()
            ->search($request->input('q'))
            ->role($request->input('tipo'))
            ->when($request->input('estado') === 'activos', fn ($q) => $q->where('active', true))
            ->when($request->input('estado') === 'inactivos', fn ($q) => $q->where('active', false));
    }

    private function form(?Contact $contact, ?string $role): Response
    {
        return Inertia::render('Contacts/Form', [
            'contact' => $contact,
            'role' => $role,
            'identificationTypes' => config('erp.identification_types'),
            'responsibilities' => config('erp.fiscal_responsibilities'),
            'cityLabel' => ($m = Municipality::with('department')->find($contact ? $contact->municipality_code : auth()->user()?->company?->municipality_code))
                ? "{$m->name} - {$m->department?->name}" : '',
            'defaultMunicipality' => $contact ? null : auth()->user()?->company?->municipality_code,
            'sellers' => Seller::where('active', true)->orderBy('name')->get(['id', 'name']),
            'priceLists' => PriceList::where('active', true)->orderBy('position')->get(['id', 'name']),
        ]);
    }

    private function validated(Request $request, ?Contact $contact = null): array
    {
        $companyId = $request->user()->company_id;

        return $request->validate([
            'is_customer' => ['boolean'], 'is_supplier' => ['boolean'], 'is_employee' => ['boolean'], 'is_other' => ['boolean'],
            'person_type' => ['required', Rule::in(['natural', 'juridica'])],
            'identification_type' => ['required', Rule::in(array_map('strval', array_keys(config('erp.identification_types'))))],
            'identification' => ['required', 'string', 'max:20',
                Rule::unique('contacts')->where('company_id', $companyId)->where('branch_office', (int) $request->input('branch_office', 0))->ignore($contact?->id)],
            'dv' => ['nullable', 'string', 'size:1'],
            'branch_office' => ['nullable', 'integer', 'min:0'],
            'first_name' => ['required_if:person_type,natural', 'nullable', 'string', 'max:120'],
            'last_name' => ['required_if:person_type,natural', 'nullable', 'string', 'max:120'],
            'business_name' => ['required_if:person_type,juridica', 'nullable', 'string', 'max:250'],
            'trade_name' => ['nullable', 'string', 'max:250'],
            'vat_responsible' => ['boolean'],
            'fiscal_responsibilities' => ['array', 'min:1'],
            'fiscal_responsibilities.*' => [Rule::in(array_keys(config('erp.fiscal_responsibilities')))],
            'address' => ['nullable', 'string', 'max:250'],
            'department_code' => ['nullable', 'exists:departments,code'],
            'municipality_code' => ['nullable', 'exists:municipalities,code'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'phone_indicative' => ['nullable', 'string', 'max:6'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_extension' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'billing_first_name' => ['nullable', 'string', 'max:120'],
            'billing_last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'seller_id' => ['nullable', Rule::exists('sellers', 'id')->where('company_id', $companyId)],
            'collector_id' => ['nullable', Rule::exists('sellers', 'id')->where('company_id', $companyId)],
            'price_list_id' => ['nullable', Rule::exists('price_lists', 'id')->where('company_id', $companyId)],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:999'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'active' => ['boolean'],
            'people' => ['array'],
            'people.*.first_name' => ['required', 'string', 'max:120'],
            'people.*.last_name' => ['nullable', 'string', 'max:120'],
            'people.*.email' => ['nullable', 'email'],
            'people.*.phone_indicative' => ['nullable', 'string', 'max:6'],
            'people.*.phone' => ['nullable', 'string', 'max:30'],
            'people.*.position' => ['nullable', 'string', 'max:120'],
        ], [
            'identification.unique' => $request->boolean('is_employee') && ! $contact
                ? 'Ya existe un tercero con esta identificación. Para volverlo empleado ve a Nómina > Empleados > "Agregar tercero existente".'
                : 'Ya existe un tercero con esta identificación y código de sucursal.',
            'first_name.required_if' => 'Los nombres son obligatorios.',
            'last_name.required_if' => 'Los apellidos son obligatorios.',
            'business_name.required_if' => 'La razón social es obligatoria.',
        ], ['identification' => 'identificación', 'email' => 'correo electrónico']);
    }

    private function attributes(array $data): array
    {
        $attrs = collect($data)->except('people')->all();
        if (! ($data['is_customer'] ?? false) && ! ($data['is_supplier'] ?? false) && ! ($data['is_employee'] ?? false) && ! ($data['is_other'] ?? false)) {
            $attrs['is_customer'] = true;
        }
        if ($data['identification_type'] === '31' && empty($data['dv'])) {
            $attrs['dv'] = (string) ColombianNit::verificationDigit($data['identification']);
        }
        $attrs['branch_office'] = (int) ($data['branch_office'] ?? 0);
        $attrs['credit_limit'] = $data['credit_limit'] ?? 0;
        $attrs['credit_days'] = $data['credit_days'] ?? 0;

        return $attrs;
    }

    private function syncPeople(Contact $contact, array $people): void
    {
        $contact->people()->delete();
        foreach ($people as $p) {
            $contact->people()->create($p);
        }
    }
}
