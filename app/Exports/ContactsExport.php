<?php

namespace App\Exports;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContactsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    /** @var list<array{0: string, 1: string}> */
    private const COLUMNS = [
        ['Tipo de persona', 'text'],
        ['Tipo de identificación', 'text'],
        ['Identificación', 'text'],
        ['DV', 'text'],
        ['Sucursal', 'int'],
        ['Nombres', 'text'],
        ['Apellidos', 'text'],
        ['Razón social', 'text'],
        ['Nombre', 'text'],
        ['Nombre comercial', 'text'],
        ['Tipo de tercero', 'text'],
        ['Responsable de IVA', 'text'],
        ['Responsabilidades fiscales', 'text'],
        ['Dirección', 'text'],
        ['País', 'text'],
        ['Departamento', 'text'],
        ['Ciudad', 'text'],
        ['Código ciudad', 'text'],
        ['Código postal', 'text'],
        ['Indicativo', 'text'],
        ['Teléfono', 'text'],
        ['Extensión', 'text'],
        ['Celular', 'text'],
        ['Correo electrónico', 'text'],
        ['Nombres facturación', 'text'],
        ['Apellidos facturación', 'text'],
        ['Vendedor', 'text'],
        ['Cobrador', 'text'],
        ['Lista de precios', 'text'],
        ['Cupo de crédito', 'money'],
        ['Plazo (días)', 'int'],
        ['Observaciones', 'text'],
        ['Estado', 'text'],
        ['Contactos', 'text'],
    ];

    public function __construct(private Builder $query) {}

    public function query(): Builder
    {
        return $this->query->with([
            'municipality:code,name,department_code',
            'department:code,name',
            'seller:id,name',
            'collector:id,name',
            'priceList:id,name',
            'people',
        ])->orderBy('name');
    }

    public function headings(): array
    {
        return array_column(self::COLUMNS, 0);
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_column(self::COLUMNS, 1);
    }

    /** @param Contact $c */
    public function map($c): array
    {
        $responsibilities = collect($c->fiscal_responsibilities ?? [])
            ->map(fn ($r) => $r.' - '.(config('erp.fiscal_responsibilities')[$r] ?? $r))
            ->implode(', ');
        $people = $c->people->map(function ($p) {
            $phone = trim(($p->phone_indicative ? $p->phone_indicative.' ' : '').(string) $p->phone);
            $bits = array_filter([
                trim($p->first_name.' '.$p->last_name),
                $p->position,
                $phone !== '' ? $phone : null,
                $p->email,
                $p->is_payer ? 'Pagador' : null,
            ], fn ($v) => $v !== null && $v !== '');

            return implode(', ', $bits);
        })->implode(' | ');

        return [
            $c->person_type === 'juridica' ? 'Empresa' : 'Persona',
            config('erp.identification_types')[$c->identification_type] ?? $c->identification_type,
            (string) $c->identification,
            (string) ($c->dv ?? ''),
            (int) $c->branch_office,
            $c->first_name,
            $c->last_name,
            $c->business_name,
            $c->name,
            $c->trade_name,
            $c->rolesLabel(),
            $c->vat_responsible ? 'Sí' : 'No',
            $responsibilities,
            $c->address,
            $c->country_code === 'CO' ? 'Colombia' : $c->country_code,
            $c->department?->name,
            $c->municipality?->name,
            $c->municipality_code,
            $c->postal_code,
            $c->phone_indicative,
            $c->phone,
            $c->phone_extension,
            $c->mobile,
            $c->email,
            $c->billing_first_name,
            $c->billing_last_name,
            $c->seller?->name,
            $c->collector?->name,
            $c->priceList?->name,
            (float) $c->credit_limit,
            (int) $c->credit_days,
            $c->observations,
            $c->active ? 'Activo' : 'Inactivo',
            $people,
        ];
    }
}
