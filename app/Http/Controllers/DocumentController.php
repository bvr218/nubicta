<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentLocator;
use App\Services\Documents\DocumentReverser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Detalle, anulacion, XML e impresion de cualquier documento (ventas, compras, recibos, comprobantes). */
class DocumentController extends Controller
{
    public function show(string $kind, int $id): Response
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.ver');

        if ($doc instanceof \App\Models\Receipt) {
            return Inertia::render('Documents/ReceiptShow', ['doc' => DocumentLocator::detail($doc), 'company' => self::company($doc->company_id)]);
        }

        if ($doc instanceof \App\Models\SalesDocument) {
            return Inertia::render('Documents/Show', self::sheet($doc) + ['company' => self::company($doc->company_id)]);
        }

        if ($doc instanceof \App\Models\PurchaseDocument) {
            return Inertia::render('Documents/PurchaseShow', ['doc' => DocumentLocator::detail($doc), 'company' => self::company($doc->company_id)]);
        }

        if ($doc instanceof \App\Models\JournalEntry) {
            return Inertia::render('Documents/JournalShow', ['doc' => DocumentLocator::detail($doc), 'company' => self::company($doc->company_id)]);
        }

        return Inertia::render('Documents/Show', ['doc' => DocumentLocator::detail($doc)]);
    }

    public function annul(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        $module = DocumentLocator::module($doc);
        Gate::authorize($module.($module === 'inventario' ? '.editar' : '.anular'));
        $note = app(DocumentReverser::class)->annul($doc);
        $message = "Se anuló el documento {$doc->name}.";
        if ($note) {
            $message .= " La nota de ajuste {$note} quedó en Compras y gastos.";
        }

        return redirect(DocumentLocator::url($doc))->with('success', $message);
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        if ($doc instanceof \App\Models\SalesDocument && $doc->type === 'CT') {
            Gate::authorize('ventas.anular');
            if ($reason = app(DocumentReverser::class)->blocker($doc)) {
                return back()->with('error', $reason);
            }
            if (\Illuminate\Support\Facades\DB::table('sales_documents')->where('related_document_id', $doc->id)->exists()) {
                return back()->with('error', "La cotización {$doc->name} ya tiene comprobantes generados y no se puede borrar.");
            }
            \Illuminate\Support\Facades\DB::transaction(function () use ($doc) {
                $items = \Illuminate\Support\Facades\DB::table('sales_document_items')->where('sales_document_id', $doc->id)->pluck('id');
                \Illuminate\Support\Facades\DB::table('sales_document_item_taxes')->whereIn('sales_document_item_id', $items)->delete();
                \Illuminate\Support\Facades\DB::table('sales_document_items')->where('sales_document_id', $doc->id)->delete();
                \Illuminate\Support\Facades\DB::table('sales_document_payments')->where('sales_document_id', $doc->id)->delete();
                \App\Models\AuditLog::record('deleted', $doc, $doc->only(['name', 'total', 'contact_id']), []);
                $doc->delete();
            });

            return redirect('/ventas/cotizaciones')->with('success', "Se borró la cotización {$doc->name}.");
        }
        if ($doc instanceof \App\Models\SalesDocument && $doc->type !== 'CT') {
            Gate::authorize('ventas.anular');
            app(DocumentReverser::class)->deleteSale($doc);
            \App\Services\Documents\DocumentShare::disk()->delete(\App\Services\Documents\DocumentShare::path($doc));

            return redirect('/ventas')->with('success', "Se eliminó el documento {$doc->name}.");
        }
        if ($doc instanceof \App\Models\JournalEntry) {
            $module = DocumentLocator::module($doc);
            Gate::authorize($module.($module === 'inventario' ? '.editar' : '.anular'));
            app(DocumentReverser::class)->deleteJournal($doc);

            return redirect('/contabilidad/comprobantes')->with('success', "Se borró el comprobante {$doc->name}.");
        }
        abort_unless($doc instanceof \App\Models\Receipt, 404);
        Gate::authorize(DocumentLocator::module($doc).'.anular');
        app(DocumentReverser::class)->delete($doc);

        return redirect($doc->type === 'RC' ? '/ventas/recaudos' : '/compras/pagos')->with('success', "Se eliminó el documento {$doc->name}.");
    }

    /** Cotizacion: Aprobar, Descartar o volver a pendiente. */
    public function quoteStatus(\Illuminate\Http\Request $request, string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument && $doc->type === 'CT' && $doc->status !== 'annulled', 404);
        Gate::authorize('ventas.editar');
        if ($reason = app(DocumentReverser::class)->blocker($doc)) {
            return back()->with('error', $reason);
        }
        $status = ['aprobar' => 'approved', 'descartar' => 'discarded', 'pendiente' => 'issued'][$request->input('estado')] ?? abort(422);
        $doc->forceFill(['status' => $status])->save();

        return back()->with('success', ['approved' => "Se aprobó la cotización {$doc->name}.", 'discarded' => "Se descartó la cotización {$doc->name}.", 'issued' => "La cotización {$doc->name} quedó pendiente."][$status]);
    }

    public function favorite(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\JournalEntry, 404);
        Gate::authorize('contabilidad.ver');
        $doc->forceFill(['favorite' => ! $doc->favorite])->save();

        return back()->with('success', $doc->favorite ? "{$doc->name} quedó en tus comprobantes favoritos." : "{$doc->name} se quitó de favoritos.");
    }

    public function mail(\Illuminate\Http\Request $request, string $kind, int $id, \App\Services\Documents\DocumentMailer $mailer): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument || $doc instanceof \App\Models\Receipt, 404);
        Gate::authorize(DocumentLocator::module($doc).'.ver');
        if (! filter_var($request->input('to'), FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'El correo del destinatario no es válido.');
        }
        try {
            $mailer->send($doc->load('contact'), $request->input('to'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Se envió {$doc->name} a {$request->input('to')}.");
    }

    public function resendDian(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument || $doc instanceof \App\Models\PurchaseDocument, 404);
        Gate::authorize(DocumentLocator::module($doc).'.editar');
        if ($doc->status !== 'issued' || $doc->source !== 'erp' || ! in_array($doc->dian_status, ['Draft', 'Rejected', 'Pending'], true)
            || ! \App\Models\DocumentType::query()->find($doc->document_type_id)?->isElectronic()) {
            return back()->with('error', 'Este documento no está pendiente de envío a la DIAN.');
        }
        $message = app(DocumentFormController::class)->emit($doc);

        return back()->with($doc->fresh()->dian_status === 'Accepted' ? 'success' : 'error', "{$doc->name}: $message");
    }

    /** "Actualizar documento electronico": consulta el estado en la DIAN y recupera respuesta, AttachedDocument y QR. */
    public function refreshDian(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument || $doc instanceof \App\Models\PurchaseDocument, 404);
        Gate::authorize(DocumentLocator::module($doc).'.editar');
        if ($doc->status !== 'issued' || $doc->source !== 'erp' || ! \App\Models\DocumentType::query()->find($doc->document_type_id)?->isElectronic()) {
            return back()->with('error', 'Este documento no es electrónico o es de solo consulta.');
        }
        try {
            $dian = \App\Services\Dian\DianService::forCurrent();
            $r = $dian->refresh($doc);
        } catch (\App\Services\Dian\DianUnavailableException|\Illuminate\Http\Client\ConnectionException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $prefix = $dian->settings()->simulated ? 'DIAN (simulado): ' : 'DIAN: ';

        return back()->with($r['valid'] ? 'success' : 'error', "{$doc->name}: {$prefix}{$r['message']}");
    }

    /** "Copiar enlace": genera el PDF del documento y devuelve el enlace publico firmado. */
    public function shareLink(string $kind, int $id, \App\Services\Documents\DocumentShare $share): \Illuminate\Http\JsonResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument && $doc->status !== 'annulled', 404);
        Gate::authorize('ventas.ver');
        try {
            $url = $share->publish($doc);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        \App\Models\AuditLog::record('shared', $doc, [], ['name' => $doc->name]);

        return response()->json(['url' => $url]);
    }

    /** "Actualizar seguimiento de cobranza": crea o reabre la tarea de cobranza del cliente para esta factura. */
    public function collection(string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        abort_unless($doc instanceof \App\Models\SalesDocument && in_array($doc->type, ['FV', 'ND'], true) && $doc->status !== 'annulled', 404);
        Gate::authorize('ventas.editar');
        $task = \App\Models\CrmTask::query()->where('origin', 'Cobranza')->where('source_document', $doc->name)->first();
        $values = ['contact_id' => $doc->contact_id, 'due_date' => $doc->due_date ?? $doc->date, 'expected_value' => (float) $doc->balance];
        if ($task) {
            $reopen = $task->status !== 'Pendiente';
            $task->update($values + ($reopen ? ['status' => 'Pendiente', 'completed_at' => null] : []));
            $message = $reopen ? "Se reabrió el seguimiento de cobranza de {$doc->name}." : "Se actualizó el seguimiento de cobranza de {$doc->name}.";
        } else {
            \App\Models\CrmTask::create($values + [
                'created_by' => \Illuminate\Support\Facades\Auth::id(), 'assigned_to' => \Illuminate\Support\Facades\Auth::id(), 'category' => '1 - General',
                'description' => "Cobranza de la factura {$doc->name}", 'origin' => 'Cobranza', 'status' => 'Pendiente', 'source_document' => $doc->name,
            ]);
            $message = "Se creó el seguimiento de cobranza de {$doc->name}.";
        }

        return back()->with('success', $message);
    }

    public function attach(\Illuminate\Http\Request $request, string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.editar');
        $request->validate(['attachment' => ['required', 'file', 'max:10240']], ['attachment.max' => 'El archivo adjunto no puede superar 10 MB']);
        \App\Models\Attachment::store($doc, $request->file('attachment'));

        return back()->with('success', 'Se adjuntó el archivo.');
    }

    public function xml(string $kind, int $id): StreamedResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.ver');
        $path = match (request('tipo')) { 'respuesta' => $doc->dian_response_path ?? null, 'contenedor' => $doc->attached_path ?? null, default => $doc->xml_path ?? null };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }

    public function print(string $kind, int $id): Response
    {
        $doc = DocumentLocator::find($kind, $id);
        Gate::authorize(DocumentLocator::module($doc).'.ver');

        return Inertia::render('Documents/Print', self::sheet($doc) + ['company' => self::company($doc->company_id)]);
    }

    /** Impresion por lotes desde Envio masivo (?ids=1,2,3). */
    public function batch(\Illuminate\Http\Request $request): Response
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->input('ids'))));
        abort_if(! $ids || count($ids) > 200, 422, 'Selecciona entre 1 y 200 comprobantes');
        $docs = \App\Models\SalesDocument::query()->whereIn('id', $ids)->orderBy('date')->orderBy('number')->get();

        return Inertia::render('Documents/Print', [
            'docs' => $docs->map(fn ($d) => self::sheet($d, false))->all(),
            'company' => self::company(\App\Support\CurrentCompany::id()),
        ]);
    }

    /** URL firmada sin sesion que usa Chromium para generar el PDF adjunto del correo. */
    public function signedPrint(\Illuminate\Http\Request $request, int $id): Response
    {
        \App\Support\CurrentCompany::set((int) $request->input('c'));
        $doc = DocumentLocator::find((string) $request->input('k', 'ventas'), $id);

        return Inertia::render('Documents/Print', self::sheet($doc) + ['company' => self::company($doc->company_id), 'bare' => true]);
    }

    /** @return array{doc: array, resolution: mixed} */
    public static function sheet(\Illuminate\Database\Eloquent\Model $doc, bool $fixLines = true): array
    {
        if ($fixLines && $doc instanceof \App\Models\SalesDocument && \App\Services\Siigo\LineOrder::pending($doc)) {
            \App\Services\Siigo\LineOrder::make()?->fix($doc);
        }

        return [
            'doc' => DocumentLocator::detail($doc),
            'resolution' => \Illuminate\Support\Facades\DB::table('document_types')->where('id', $doc->document_type_id)
                ->first(['resolution_number', 'resolution_date', 'range_from', 'range_to', 'prefix', 'valid_from', 'valid_to']),
        ];
    }

    public static function company(?int $id): ?array
    {
        $company = $id ? \App\Models\Company::query()->find($id) : null;

        return $company ? [
            'name' => $company->business_name ?: trim("{$company->first_name} {$company->last_name}"), 'trade_name' => $company->show_trade_name ? $company->trade_name : null,
            'nit' => $company->nit.($company->dv !== null ? "-{$company->dv}" : ''), 'address' => $company->address,
            'nit_number' => $company->nit, 'dv' => $company->dv, 'ciiu' => $company->ciiu, 'ciiu_name' => config('ciiu.'.$company->ciiu),
            'iva_responsible' => in_array('O-48', (array) $company->fiscal_responsibilities, true) || (string) $company->tax_regime === '48',
            'phone' => $company->phone, 'phone2' => $company->phone2, 'email' => $company->email, 'logo' => $company->logo_path ? '/storage/'.$company->logo_path : null,
            'city' => \Illuminate\Support\Facades\DB::table('municipalities')->where('code', $company->municipality_code)->value('name'),
        ] : null;
    }
}
