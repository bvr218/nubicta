<?php

namespace App\Http\Controllers\ApiV1;

use App\Models\Contact;
use App\Models\Employee;
use App\Services\Dian\DocumentMapper;
use App\Support\ColombianNit;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Terceros (clientes, proveedores, empleados y otros): equivale a /v1/customers de Siigo. */
class ContactApiController extends ApiBaseController
{
    /** Campo de la API => columna. */
    private const FIELDS = [
        'tipo_persona' => 'person_type', 'tipo_identificacion' => 'identification_type', 'identificacion' => 'identification', 'sucursal' => 'branch_office',
        'nombres' => 'first_name', 'apellidos' => 'last_name', 'razon_social' => 'business_name', 'nombre_comercial' => 'trade_name',
        'responsable_iva' => 'vat_responsible', 'responsabilidades_fiscales' => 'fiscal_responsibilities', 'direccion' => 'address', 'ciudad' => 'municipality_code',
        'codigo_postal' => 'postal_code', 'indicativo' => 'phone_indicative', 'telefono_fijo' => 'phone', 'telefono' => 'mobile', 'correo' => 'email',
        'vendedor_id' => 'seller_id', 'cobrador_id' => 'collector_id', 'lista_precio_id' => 'price_list_id', 'cupo_credito' => 'credit_limit',
        'dias_credito' => 'credit_days', 'observaciones' => 'observations', 'activo' => 'active',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeApi('terceros.ver');
        $page = Contact::query()->search($request->input('q'))->role($request->input('tipo'))
            ->when($request->input('identificacion'), fn ($q, $id) => $q->where('identification', preg_replace('/\D/', '', $id) ?: $id))
            ->when($request->has('activo'), fn ($q) => $q->where('active', $request->boolean('activo')))
            ->when($request->input('creado_desde'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('actualizado_desde'), fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->orderBy('name')->paginate($this->perPage($request));

        return $this->paged($page, fn (Contact $c) => ApiPresenter::contact($c));
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeApi('terceros.ver');

        return response()->json(ApiPresenter::contact(Contact::query()->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeApi('terceros.crear');
        $contact = DB::transaction(function () use ($request) {
            $attrs = $this->validated($request->all());
            $contact = Contact::create($attrs);
            $contact->is_employee && Employee::enroll($contact);

            return $contact;
        });

        return response()->json(ApiPresenter::contact($contact->fresh()), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeApi('terceros.editar');
        $contact = Contact::query()->findOrFail($id);
        DB::transaction(function () use ($request, $contact) {
            $current = collect(self::FIELDS)->mapWithKeys(fn ($col, $field) => [$field => $contact->{$col}])->all();
            $current['roles'] = array_keys(array_filter(array_map(fn ($col) => (bool) $contact->{$col}, Contact::ROLES)));
            $contact->update($this->validated(array_merge($current, $request->all()), $contact));
            $contact->is_employee && Employee::enroll($contact);
        });

        return response()->json(ApiPresenter::contact($contact->fresh()));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeApi('terceros.eliminar');
        $contact = Contact::query()->findOrFail($id);
        foreach (['sales_documents', 'purchase_documents', 'receipts', 'journal_lines', 'employees'] as $table) {
            if (DB::table($table)->where('contact_id', $contact->id)->exists()) {
                return response()->json(['message' => "El tercero {$contact->name} tiene documentos o movimientos; inactívalo con PUT /terceros/{$contact->id} y \"activo\": false."], 409);
            }
        }
        $contact->people()->delete();
        $contact->delete();

        return response()->json(['mensaje' => "Se eliminó el tercero {$contact->name}."]);
    }

    private function validated(array $input, ?Contact $contact = null): array
    {
        $companyId = CurrentCompany::id();
        $branch = (int) ($input['sucursal'] ?? 0);
        $data = Validator::make($input, [
            'tipo_persona' => ['required', Rule::in(['natural', 'juridica'])],
            'tipo_identificacion' => ['required', Rule::in(array_map('strval', array_keys(config('erp.identification_types'))))],
            'identificacion' => ['required', 'string', 'max:20'],
            'sucursal' => ['nullable', 'integer', 'min:0'],
            'nombres' => ['required_if:tipo_persona,natural', 'nullable', 'string', 'max:120'],
            'apellidos' => ['required_if:tipo_persona,natural', 'nullable', 'string', 'max:120'],
            'razon_social' => ['required_if:tipo_persona,juridica', 'nullable', 'string', 'max:250'],
            'nombre_comercial' => ['nullable', 'string', 'max:250'],
            'responsable_iva' => ['nullable', 'boolean'],
            'responsabilidades_fiscales' => ['nullable', 'array'],
            'responsabilidades_fiscales.*' => [Rule::in(array_keys(config('erp.fiscal_responsibilities')))],
            'direccion' => ['nullable', 'string', 'max:250'],
            'pais' => ['nullable', Rule::in(array_keys(config('countries')))],
            'ciudad' => ['nullable', 'exists:municipalities,code'],
            'ciudad_extranjera' => ['nullable', 'string', 'max:80'],
            'codigo_postal' => ['nullable', 'string', 'max:10'],
            'indicativo' => ['nullable', 'string', 'max:6'],
            'telefono_fijo' => ['nullable', 'string', 'max:30'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:190'],
            'vendedor_id' => ['nullable', Rule::exists('sellers', 'id')->where('company_id', $companyId)],
            'cobrador_id' => ['nullable', Rule::exists('sellers', 'id')->where('company_id', $companyId)],
            'lista_precio_id' => ['nullable', Rule::exists('price_lists', 'id')->where('company_id', $companyId)],
            'cupo_credito' => ['nullable', 'numeric', 'min:0'],
            'dias_credito' => ['nullable', 'integer', 'min:0', 'max:999'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'activo' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => [Rule::in(array_keys(Contact::ROLES))],
        ], [
            'nombres.required_if' => 'Los nombres son obligatorios para persona natural.',
            'apellidos.required_if' => 'Los apellidos son obligatorios para persona natural.',
            'razon_social.required_if' => 'La razón social es obligatoria para persona jurídica.',
        ])->validate();

        $country = strtoupper((string) ($data['pais'] ?? $input['pais'] ?? $contact?->country_code ?? 'CO'));
        $foreign = $country !== 'CO' || in_array((string) $data['tipo_identificacion'], DocumentMapper::NON_RESIDENT_IDS, true);
        if ($country === 'CO' && empty($data['ciudad']) && ! $contact?->municipality_code) {
            throw ValidationException::withMessages(['ciudad' => 'Elige la ciudad de Colombia.']);
        }
        if ($country !== 'CO' && ! filled($data['ciudad_extranjera'] ?? $contact?->foreign_city)) {
            throw ValidationException::withMessages(['ciudad_extranjera' => 'Escribe la ciudad del exterior.']);
        }
        $id = $foreign ? trim($data['identificacion']) : (preg_replace('/\D/', '', $data['identificacion']) ?: $data['identificacion']);
        $duplicate = Contact::query()->where('identification', $id)->where('branch_office', $branch)->when($contact, fn ($q) => $q->whereKeyNot($contact->id))->first();
        if ($duplicate) {
            throw ValidationException::withMessages(['identificacion' => "Ya existe el tercero {$duplicate->name} (id {$duplicate->id}) con la identificación {$id} y sucursal {$branch}."]);
        }

        $attrs = [];
        foreach (self::FIELDS as $field => $col) {
            if (array_key_exists($field, $data)) {
                $attrs[$col] = $data[$field];
            }
        }
        $natural = $data['tipo_persona'] === 'natural';
        $roles = $data['roles'] ?? ['cliente'];

        return array_merge($attrs, [
            'identification' => $id, 'branch_office' => $branch,
            'first_name' => $natural ? $data['nombres'] : null, 'last_name' => $natural ? $data['apellidos'] : null, 'business_name' => $natural ? null : $data['razon_social'],
            'dv' => $data['tipo_identificacion'] === '31' ? (string) ColombianNit::verificationDigit($id) : null,
            'department_code' => $country === 'CO' && ! empty($data['ciudad']) ? substr($data['ciudad'], 0, 2) : ($country === 'CO' ? $contact?->department_code : null),
            'municipality_code' => $country === 'CO' ? ($data['ciudad'] ?? $contact?->municipality_code) : null,
            'country_code' => $country, 'foreign_city' => $country === 'CO' ? null : trim((string) ($data['ciudad_extranjera'] ?? $contact?->foreign_city ?? '')),
            'fiscal_responsibilities' => $data['responsabilidades_fiscales'] ?? $contact?->fiscal_responsibilities ?? ['R-99-PN'],
            'vat_responsible' => (bool) ($data['responsable_iva'] ?? false), 'credit_limit' => $data['cupo_credito'] ?? 0, 'credit_days' => $data['dias_credito'] ?? 0,
            'active' => (bool) ($data['activo'] ?? true),
            ...collect(Contact::ROLES)->mapWithKeys(fn ($col, $role) => [$col => in_array($role, $roles ?: ['cliente'], true)])->all(),
        ]);
    }
}
