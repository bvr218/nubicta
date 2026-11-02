<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tercero: cliente, proveedor, empleado u otro. */
class Contact extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const ROLES = [
        'cliente' => 'is_customer',
        'proveedor' => 'is_supplier',
        'empleado' => 'is_employee',
        'otro' => 'is_other',
    ];

    protected function casts(): array
    {
        return [
            'is_customer' => 'boolean', 'is_supplier' => 'boolean', 'is_employee' => 'boolean', 'is_other' => 'boolean',
            'vat_responsible' => 'boolean', 'fiscal_responsibilities' => 'array', 'active' => 'boolean',
            'credit_limit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Contact $c) {
            $c->name = $c->person_type === 'juridica'
                ? trim((string) $c->business_name)
                : trim(trim((string) $c->first_name).' '.trim((string) $c->last_name));
            if ($c->name === '') {
                $c->name = (string) $c->identification;
            }
        });
    }

    public function people(): HasMany
    {
        return $this->hasMany(ContactPerson::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'collector_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'municipality_code', 'code');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_code', 'code');
    }

    public function scopeRole(Builder $q, ?string $role): Builder
    {
        $column = self::ROLES[$role] ?? null;

        return $column ? $q->where($column, true) : $q;
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('name', 'like', "%$term%")
            ->orWhere('identification', 'like', "$term%")
            ->orWhere('trade_name', 'like', "%$term%"));
    }

    /** Etiqueta de tipos para listados: "Cliente, Proveedor". */
    public function rolesLabel(): string
    {
        $labels = [];
        foreach (['is_customer' => 'Cliente', 'is_supplier' => 'Proveedor', 'is_employee' => 'Empleado', 'is_other' => 'Otro'] as $col => $label) {
            if ($this->$col) {
                $labels[] = $label;
            }
        }

        return implode(', ', $labels);
    }

    public function identificationLabel(): string
    {
        return $this->identification.($this->dv !== null && $this->dv !== '' && $this->identification_type === '31' ? '-'.$this->dv : '');
    }
}
