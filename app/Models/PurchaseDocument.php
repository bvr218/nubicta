<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseDocument extends Model
{
    use Auditable, BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = ['FC' => 'Factura de compra', 'DS' => 'Documento soporte', 'NC' => 'Nota crédito compras', 'ND' => 'Nota débito compras', 'NA' => 'Nota ajuste documento soporte', 'SI' => 'Saldo inicial'];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'due_date' => 'date',
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'tax_total' => 'decimal:2',
            'withholding_total' => 'decimal:2', 'total' => 'decimal:2', 'balance' => 'decimal:2',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseDocumentItem::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchaseDocumentPayment::class);
    }
}
