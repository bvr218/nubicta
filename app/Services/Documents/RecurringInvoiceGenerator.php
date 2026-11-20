<?php

namespace App\Services\Documents;

use App\Http\Controllers\DocumentFormController;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Elabora la factura de venta de una recurrencia copiando la factura modelo (mismos items, precios, impuestos y forma de pago). */
class RecurringInvoiceGenerator
{
    public function __construct(private readonly TradeRegistrar $trade) {}

    /** Genera todas las recurrencias activas vencidas hasta hoy. @return array{ok:int, errors:int} */
    public function due(?string $today = null): array
    {
        $today ??= now()->toDateString();
        $ok = $errors = 0;
        foreach (RecurringInvoice::query()->where('active', true)->whereDate('next_date', '<=', $today)->get() as $r) {
            while ($r->active && $r->next_date->toDateString() <= $today && ! $r->finished()) {
                try {
                    $this->generate($r, $r->next_date->toDateString());
                    $ok++;
                } catch (\Throwable $e) {
                    report($e);
                    $r->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
                    $errors++;
                    break;
                }
            }
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    public function generate(RecurringInvoice $r, string $date): SalesDocument
    {
        $template = SalesDocument::query()->findOrFail($r->sales_document_id);
        $data = $this->data($template, $date);
        $form = DocumentFormController::FORMS['ventas/facturas/crear'];

        return DB::transaction(function () use ($r, $form, $data, $date) {
            $doc = $this->trade->register($form, $data);
            $generated = $r->generated + 1;
            $next = Carbon::parse($date)->addMonthsNoOverflow(RecurringInvoice::FREQUENCIES[$r->frequency] ?? 1);
            $r->update([
                'generated' => $generated, 'last_document' => $doc->name, 'last_error' => null,
                'next_date' => $next->toDateString(), 'active' => $r->times === null || $generated < $r->times,
            ]);

            return $doc;
        });
    }

    /** Datos con la forma que recibe TradeRegistrar a partir de la factura modelo. */
    public function data(SalesDocument $t, string $date): array
    {
        $items = $t->items()->orderBy('position')->get();
        $taxes = DB::table('sales_document_item_taxes')->whereIn('sales_document_item_id', $items->pluck('id'))->get()->groupBy('sales_document_item_id');
        $withholdings = \App\Models\Tax::WITHHOLDINGS;
        $payments = DB::table('sales_document_payments')->where('sales_document_id', $t->id)->get();
        $credit = $payments->isEmpty() || $payments->every(fn ($p) => ! $p->payment_method_id);

        return [
            'document_type' => $t->document_type_id, 'date' => $date, 'contact_id' => $t->contact_id,
            'seller_id' => $t->seller_id, 'cost_center_id' => $t->cost_center_id, 'observations' => $t->observations,
            'items' => $items->map(function ($i) use ($taxes, $withholdings) {
                $tx = $taxes[$i->id] ?? collect();

                return [
                    'product_id' => $i->product_id, 'account_id' => $i->product_id ? null : ($i->account_id ?? null),
                    'description' => $i->description, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
                    'discount' => TradeRegistrar::discountPercent((float) $i->quantity, (float) $i->price, (float) $i->discount),
                    'tax_id' => $tx->first(fn ($x) => ! in_array($x->type, $withholdings, true))?->tax_id,
                    'withholding_id' => $tx->first(fn ($x) => in_array($x->type, $withholdings, true))?->tax_id,
                ];
            })->all(),
            'credit' => $credit,
            'payments' => $credit ? [] : [['method_id' => $payments->first()->payment_method_id, 'value' => (float) $t->total]],
        ];
    }
}
