<?php

namespace App\Services\Documents;

use App\Models\DocumentType;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseDocument;
use App\Models\Receipt;
use App\Models\SalesDocument;
use App\Services\Accounting\LedgerEntry;
use App\Services\Inventory\InventoryService;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula o prepara la edicion de un documento del ERP: quita su asiento, devuelve el inventario con movimientos
 * de reverso y restituye los saldos que afecto (factura relacionada, documentos abonados, cartera ajustada).
 */
class DocumentReverser
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Motivo por el que no se puede anular o editar, o null si se puede.
     * Un documento soporte ya aceptado por la DIAN se puede anular (reversa contabilidad y saldos); no se puede editar.
     */
    public function blocker(Model $doc, bool $editing = false): ?string
    {
        if (($doc->source ?? 'erp') !== 'erp') {
            return 'Los documentos importados de Siigo son de solo consulta.';
        }
        if (in_array($doc->status, ['annulled'], true)) {
            return 'El documento ya está anulado.';
        }
        if (($kind = DocumentLocator::kind($doc)) && \App\Http\Controllers\Accounting\VerificationController::isVerified($kind, $doc->id)) {
            return 'El comprobante está verificado y bloqueado. Quita la verificación en Contabilidad › Más procesos › Verifica y bloquea documentos.';
        }
        if ($doc instanceof JournalEntry && $doc->source_type === 'opening_balance'
            && ($paid = DB::table('sales_documents')->where('type', 'SI')->where('related_name', $doc->name)->whereColumn('balance', '<', 'total')->value('name')
                ?? DB::table('purchase_documents')->where('type', 'SI')->where('related_name', $doc->name)->whereColumn('balance', '<', 'total')->value('name'))) {
            return "El saldo inicial $paid ya tiene pagos o cruces. Anúlalos primero.";
        }
        if ($doc instanceof JournalEntry && in_array($doc->source_type, ['payroll', 'payroll_payment'], true)) {
            return 'Comprobante generado por la nómina: para corregirlo reabre la nómina desde Nómina > Nóminas.';
        }
        if (($doc instanceof SalesDocument || $doc instanceof PurchaseDocument) && $doc->dian_status === 'Accepted'
            && ! ($doc instanceof PurchaseDocument && $doc->type === 'DS' && ! $editing)) {
            if ($doc instanceof PurchaseDocument && $doc->type === 'NA') {
                return 'La DIAN ya aceptó esta nota de ajuste. No se puede editar ni anular.';
            }

            return 'La DIAN ya aceptó este documento electrónico: para reversarlo elabora una nota '.($doc instanceof SalesDocument ? 'crédito' : 'de ajuste').'.';
        }
        if ($doc instanceof SalesDocument || $doc instanceof PurchaseDocument) {
            if ($doc instanceof SalesDocument && $doc->type === 'CT') {
                $invoice = SalesDocument::query()->where('related_document_id', $doc->id)->where('type', 'FV')->value('name');
                $remission = SalesDocument::query()->where('related_document_id', $doc->id)->where('type', 'RM')->value('name');
                if ($invoice && $remission) {
                    return "No se puede editar la cotización {$doc->name} porque ya fue facturada ({$invoice}) y remisionada ({$remission}).";
                }
                if ($invoice) {
                    return "No se puede editar la cotización {$doc->name} porque ya fue facturada ({$invoice}).";
                }
                if ($remission) {
                    return "No se puede editar la cotización {$doc->name} porque ya fue remisionada ({$remission}).";
                }
            }
            $fk = $doc instanceof SalesDocument ? 'sales_document_id' : 'purchase_document_id';
            $receipt = DB::table('receipt_items')->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
                ->where("receipt_items.$fk", $doc->id)->where('receipts.status', '!=', 'annulled')->value('receipts.name');
            if ($receipt) {
                return "Tiene pagos asociados ($receipt). Anula primero el recibo.";
            }
            $note = $doc::query()->where('related_document_id', $doc->id)->whereIn('type', ['NC', 'ND', 'NA'])->where('status', '!=', 'annulled')->value('name');
            if ($note) {
                return "Tiene la nota $note asociada. Anula primero la nota.";
            }
            $adjustment = JournalEntry::query()->where('source_type', 'portfolio_adjustment')->where('source_id', $doc->id)
                ->where('status', '!=', 'annulled')->get()->first(fn ($je) => $this->adjustedDocument($je)?->is($doc));
            if ($adjustment) {
                return "Tiene el ajuste de cartera {$adjustment->name}. Anúlalo primero.";
            }
        }

        return null;
    }

    /** Anula el documento. Si es un documento soporte aceptado por la DIAN, deja la nota de ajuste y devuelve su número. */
    public function annul(Model $doc): ?string
    {
        $adjustment = $doc instanceof PurchaseDocument && $doc->type === 'DS' && $doc->dian_status === 'Accepted';
        $this->guard($doc);
        DB::transaction(function () use ($doc) {
            $this->reverse($doc, 'Anulación');
            $doc->forceFill(['status' => 'annulled', 'annulled_at' => now(), 'annulled_by' => Auth::id()]
                + (array_key_exists('balance', $doc->getAttributes()) ? ['balance' => 0] : []))->save();
        });
        if (! $adjustment) {
            return null;
        }
        $note = $this->supportAdjustment($doc->fresh());
        if ($note->dian_status && $note->dian_status !== 'Accepted') {
            app(\App\Http\Controllers\DocumentFormController::class)->emit($note);
        }

        return $note->fresh()->name;
    }

    /** Nota de ajuste del documento soporte. La anulación ya reversó contabilidad e inventario, así que la nota no los vuelve a mover. */
    public function supportAdjustment(PurchaseDocument $doc): PurchaseDocument
    {
        $existing = PurchaseDocument::query()->where('type', 'NA')->where('related_document_id', $doc->id)->where('status', '!=', 'annulled')->first();
        if ($existing) {
            return $existing;
        }
        $type = DocumentType::query()->where('type', 'NA')->where('active', true)->orderBy('code')->first()
            ?? DocumentType::create(['company_id' => $doc->company_id, 'type' => 'NA', 'code' => '1', 'name' => 'Nota de ajuste documento soporte', 'consecutive' => 1, 'automatic_number' => true, 'electronic_type' => 'SupportDocumentAdjustment', 'active' => true]);

        return DB::transaction(function () use ($doc, $type) {
            [$number, $name] = app(Numbering::class)->take($type, 'purchase_documents');
            $note = PurchaseDocument::create([
                'company_id' => $doc->company_id, 'source' => 'erp', 'type' => 'NA', 'document_type_id' => $type->id,
                'number' => $number, 'name' => $name, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(),
                'contact_id' => $doc->contact_id, 'supplier_prefix' => $doc->supplier_prefix, 'supplier_number' => $doc->supplier_number,
                'cost_center_id' => $doc->cost_center_id, 'related_document_id' => $doc->id, 'related_name' => $doc->name,
                'currency' => $doc->currency ?: 'COP', 'exchange_rate' => $doc->exchange_rate ?: 1,
                'subtotal' => $doc->subtotal, 'discount' => $doc->discount, 'tax_total' => $doc->tax_total,
                'withholding_total' => $doc->withholding_total, 'total' => $doc->total, 'balance' => 0,
                'observations' => "Nota de ajuste por anulación de {$doc->name}.", 'status' => 'issued',
                'dian_status' => $type->isElectronic() ? 'Draft' : null, 'created_by' => Auth::id(),
            ]);
            foreach (DB::table('purchase_document_items')->where('purchase_document_id', $doc->id)->orderBy('position')->get() as $item) {
                $newId = DB::table('purchase_document_items')->insertGetId([
                    'purchase_document_id' => $note->id, 'position' => $item->position, 'item_type' => $item->item_type,
                    'product_id' => $item->product_id, 'account_id' => $item->account_id, 'code' => $item->code, 'description' => $item->description,
                    'warehouse_id' => $item->warehouse_id, 'quantity' => $item->quantity, 'price' => $item->price, 'discount' => $item->discount,
                    'subtotal' => $item->subtotal, 'tax_total' => $item->tax_total, 'total' => $item->total, 'tax_percentage' => $item->tax_percentage,
                ]);
                foreach (DB::table('purchase_document_item_taxes')->where('purchase_document_item_id', $item->id)->get() as $tax) {
                    DB::table('purchase_document_item_taxes')->insert([
                        'purchase_document_item_id' => $newId, 'tax_id' => $tax->tax_id, 'name' => $tax->name,
                        'type' => $tax->type, 'percentage' => $tax->percentage, 'value' => $tax->value,
                    ]);
                }
            }
            foreach (DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->get() as $r) {
                DB::table('purchase_document_retentions')->insert([
                    'purchase_document_id' => $note->id, 'tax_id' => $r->tax_id, 'name' => $r->name, 'type' => $r->type,
                    'percentage' => $r->percentage, 'base' => $r->base, 'value' => $r->value,
                ]);
            }
            foreach (DB::table('purchase_document_payments')->where('purchase_document_id', $doc->id)->get() as $p) {
                DB::table('purchase_document_payments')->insert([
                    'purchase_document_id' => $note->id, 'payment_method_id' => $p->payment_method_id, 'name' => $p->name,
                    'value' => $p->value, 'due_date' => $p->due_date,
                ]);
            }

            return $note;
        });
    }

    /** Reversa efectos y borra el documento para registrarlo de nuevo con el mismo numero. */
    public function prepareEdit(Model $doc): array
    {
        $this->guard($doc, true);
        $this->reverse($doc, 'Edición');
        $keep = [$doc->number, $doc->name];
        match (true) {
            $doc instanceof SalesDocument => $this->removeSale($doc),
            $doc instanceof PurchaseDocument => $this->deletePurchase($doc),
            $doc instanceof JournalEntry => tap($doc->lines()->delete(), fn () => $doc->delete()),
            $doc instanceof Receipt => $this->deleteReceipt($doc),
            default => throw ValidationException::withMessages(['items' => 'Este documento no se puede editar; anúlalo y créalo de nuevo.']),
        };

        return $keep;
    }

    private function guard(Model $doc, bool $editing = false): void
    {
        if ($reason = $this->blocker($doc, $editing)) {
            throw ValidationException::withMessages(['items' => $reason]);
        }
        try {
            app(Numbering::class)->assertOpen(substr((string) $doc->date, 0, 10));
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
    }

    private function reverse(Model $doc, string $concept): void
    {
        LedgerEntry::remove($doc->name);
        $this->reverseInventory($doc, $concept);

        if (($doc instanceof SalesDocument || $doc instanceof PurchaseDocument) && $doc->related_document_id && (float) $doc->related_applied > 0) {
            $related = $doc::query()->lockForUpdate()->find($doc->related_document_id);
            $change = $doc instanceof PurchaseDocument && $doc->type === 'ND' ? -(float) $doc->related_applied : (float) $doc->related_applied;
            $related?->forceFill(['balance' => round((float) $related->balance + $change, 2)])->save();
        }
        if ($doc instanceof Receipt) {
            foreach ($doc->items as $item) {
                $paid = $item->sales_document_id ? SalesDocument::query()->find($item->sales_document_id) : ($item->purchase_document_id ? PurchaseDocument::query()->find($item->purchase_document_id) : null);
                $paid?->forceFill(['balance' => round((float) $paid->balance + (float) $item->value + (float) $item->adjustments, 2)])->save();
            }
        }
        if ($doc instanceof JournalEntry && $doc->source_type === null) {
            foreach ($doc->lines()->where('due_prefix', 'like', '%-%')->whereNotNull('due_number')->get() as $l) {
                $dueType = DB::table('accounts')->where('id', $l->account_id)->value('due_type');
                $model = $dueType === 'cartera' ? SalesDocument::class : ($dueType ? PurchaseDocument::class : null);
                $crossed = $model ? $model::query()->where('contact_id', $l->contact_id)->where('name', "{$l->due_prefix}-{$l->due_number}")->first() : null;
                $delta = $dueType === 'cartera' ? (float) $l->debit - (float) $l->credit : (float) $l->credit - (float) $l->debit;
                $crossed?->forceFill(['balance' => round((float) $crossed->balance - $delta, 2)])->save();
            }
        }
        if ($doc instanceof JournalEntry && $doc->source_type === 'opening_balance') {
            foreach (['sales_documents', 'purchase_documents'] as $table) {
                DB::table($table)->where('company_id', $doc->company_id)->where('type', 'SI')->where('related_name', $doc->name)
                    ->update(['status' => 'annulled', 'balance' => 0, 'annulled_at' => now(), 'annulled_by' => Auth::id()]);
            }
        }
        if ($doc instanceof JournalEntry && $doc->source_type === 'portfolio_adjustment' && ($adjusted = $this->adjustedDocument($doc))) {
            $value = (float) $doc->lines()->whereNotNull('due_number')->get()->sum(fn ($l) => (float) $l->debit + (float) $l->credit);
            $adjusted->forceFill(['balance' => round((float) $adjusted->balance + $value, 2)])->save();
        }
    }

    private function reverseInventory(Model $doc, string $concept): void
    {
        $movements = InventoryMovement::query()->where('document', $doc->name)
            ->where('source_type', $doc->getMorphClass())->where('source_id', $doc->id)->orderByDesc('id')->get();
        foreach ($movements as $m) {
            $product = Product::withoutGlobalScopes()->find($m->product_id);
            $qty = -(float) $m->quantity;
            $this->inventory->move($product, $m->warehouse_id, $qty, "$concept {$doc->name}", $m->date, $qty > 0 ? (float) $m->unit_cost : null, null, $doc->name);
        }
    }

    /** Documento de cartera que ajusto un CC-777 (por el prefijo y numero de su linea de contrapartida). */
    private function adjustedDocument(JournalEntry $je): SalesDocument|PurchaseDocument|null
    {
        $line = $je->lines()->whereNotNull('due_number')->first();
        if (! $line) {
            return null;
        }
        $type = explode('-', (string) $line->due_prefix)[0];
        $model = in_array($type, ['FV', 'ND', 'NC'], true) ? SalesDocument::class : PurchaseDocument::class;

        return $model::query()->where('name', $line->due_prefix.'-'.$line->due_number)->first();
    }

    private function removeSale(SalesDocument $doc): void
    {
        $items = $doc->items()->pluck('id');
        DB::table('sales_document_item_taxes')->whereIn('sales_document_item_id', $items)->delete();
        $doc->items()->delete();
        DB::table('sales_document_payments')->where('sales_document_id', $doc->id)->delete();
        DB::table('sales_document_retentions')->where('sales_document_id', $doc->id)->delete();
        DB::table('dian_transmissions')->where('sales_document_id', $doc->id)->update(['sales_document_id' => null]);
        $doc->delete();
    }

    private function deletePurchase(PurchaseDocument $doc): void
    {
        $items = $doc->items()->pluck('id');
        DB::table('purchase_document_item_taxes')->whereIn('purchase_document_item_id', $items)->delete();
        $doc->items()->delete();
        DB::table('purchase_document_payments')->where('purchase_document_id', $doc->id)->delete();
        DB::table('purchase_document_retentions')->where('purchase_document_id', $doc->id)->delete();
        $doc->delete();
    }

    private function deleteReceipt(Receipt $doc): void
    {
        DB::table('receipt_item_adjustments')->whereIn('receipt_item_id', $doc->items()->pluck('id'))->delete();
        $doc->items()->delete();
        $doc->delete();
    }

    /** "Eliminar" para recibos: reversa sus efectos y borra el documento. */
    public function delete(Receipt $doc): void
    {
        $this->guard($doc);
        DB::transaction(function () use ($doc) {
            $this->reverse($doc, 'Eliminación');
            $this->deleteReceipt($doc);
        });
    }

    /**
     * "Eliminar" de un documento de venta que la DIAN no ha aceptado: si esta vigente reversa contabilidad, inventario y saldos;
     * si ya esta anulado solo lo borra (sus efectos se reversaron al anular).
     */
    public function deleteSale(SalesDocument $doc): void
    {
        if ($doc->status === 'annulled') {
            if (($doc->source ?? 'erp') !== 'erp') {
                throw ValidationException::withMessages(['items' => 'Los documentos importados de Siigo son de solo consulta.']);
            }
            if ($doc->dian_status === 'Accepted') {
                throw ValidationException::withMessages(['items' => 'La DIAN ya aceptó este documento electrónico: no se puede eliminar.']);
            }
            if (\App\Http\Controllers\Accounting\VerificationController::isVerified(DocumentLocator::kind($doc), $doc->id)) {
                throw ValidationException::withMessages(['items' => 'El comprobante está verificado y bloqueado.']);
            }
        } else {
            $this->guard($doc);
        }
        DB::transaction(function () use ($doc) {
            if ($doc->status !== 'annulled') {
                $this->reverse($doc, 'Eliminación');
            }
            \App\Models\AuditLog::record('deleted', $doc, $doc->only(['name', 'date', 'total', 'contact_id', 'status', 'dian_status']), []);
            $this->removeSale($doc);
        });
    }

    /**
     * "Borrar" (vigente: reversa contabilidad, inventario y saldos) y "Eliminar" (anulado: sus efectos ya se reversaron al anular)
     * de un comprobante contable.
     */
    public function deleteJournal(JournalEntry $doc): void
    {
        if ($doc->status === 'annulled') {
            if (($doc->source ?? 'erp') !== 'erp') {
                throw ValidationException::withMessages(['items' => 'Los documentos importados de Siigo son de solo consulta.']);
            }
            if (\App\Http\Controllers\Accounting\VerificationController::isVerified('comprobantes', $doc->id)) {
                throw ValidationException::withMessages(['items' => 'El comprobante está verificado y bloqueado.']);
            }
        } else {
            $this->guard($doc);
        }
        DB::transaction(function () use ($doc) {
            if ($doc->status !== 'annulled') {
                $this->reverse($doc, 'Eliminación');
            }
            \App\Models\AuditLog::record('deleted', $doc, $doc->only(['name', 'date', 'debit', 'credit', 'status']), []);
            $doc->lines()->delete();
            $doc->delete();
        });
    }

    /** Formulario del menu Crear con el que se edita el documento. */
    public static function formKey(Model $doc): ?string
    {
        return match (true) {
            $doc instanceof SalesDocument => ['FV' => 'ventas/facturas/crear', 'NC' => 'ventas/notas-credito/crear', 'ND' => 'ventas/notas-debito/crear',
                'CT' => 'ventas/cotizaciones/crear', 'RM' => 'ventas/remisiones/crear'][$doc->type] ?? null,
            $doc instanceof PurchaseDocument => ['FC' => 'compras/facturas/crear', 'DS' => 'compras/documentos-soporte/crear', 'NC' => 'compras/notas-credito/crear', 'NA' => 'compras/notas-ajuste/crear',
                'OC' => 'compras/ordenes/crear'][$doc->type] ?? null,
            $doc instanceof JournalEntry => $doc->source_type === null && DocumentType::query()->whereKey($doc->document_type_id)->value('type') === 'CC' ? 'contabilidad/comprobantes/crear' : null,
            $doc instanceof Receipt => $doc->type === 'RC' ? 'ventas/recibos/crear' : 'compras/egresos/crear',
            default => null,
        };
    }

    public static function companyId(): int
    {
        return (int) CurrentCompany::id();
    }
}
