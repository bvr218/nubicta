<?php

namespace App\Jobs;

use App\Models\DianInboxSync;
use App\Models\DianInboxSyncItem;
use App\Services\Dian\DianService;
use App\Services\Purchases\PurchaseInbox;
use App\Support\CurrentCompany;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ImportDianInboxKey implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(
        public readonly int $companyId,
        public readonly int $itemId,
    ) {}

    public function handle(): void
    {
        CurrentCompany::set($this->companyId);
        try {
            $item = DianInboxSyncItem::query()->where('company_id', $this->companyId)->findOrFail($this->itemId);
            if (in_array($item->status, ['Importado', 'Omitido', 'Fallido'], true)) {
                return;
            }
            $item->update(['status' => 'Procesando']);
            DianInboxSync::query()->whereKey($item->sync_id)->update(['status' => 'Procesando']);

            try {
                $xml = DianService::forCurrent()->xmlByDocumentKey($item->cufe);
                if ($xml === null) {
                    throw new RuntimeException('La DIAN no devolvió el XML para este CUFE/CUDE.');
                }
                $document = app(PurchaseInbox::class)->ingest($xml, 'listado');
                $status = $document ? 'Importado' : 'Omitido';
                $message = $document
                    ? 'Documento agregado a la bandeja para causación.'
                    : 'Documento no aplicable o ya registrado.';
            } catch (Throwable $e) {
                report($e);
                $status = 'Fallido';
                $message = mb_substr($e->getMessage(), 0, 500);
            }

            $this->finish($item->id, $status, $message);
        } finally {
            CurrentCompany::set(null);
        }
    }

    public function failed(?Throwable $exception): void
    {
        CurrentCompany::set($this->companyId);
        try {
            $this->finish($this->itemId, 'Fallido', mb_substr($exception?->getMessage() ?? 'No se pudo procesar el documento.', 0, 500));
        } finally {
            CurrentCompany::set(null);
        }
    }

    private function finish(int $itemId, string $status, string $message): void
    {
        DB::transaction(function () use ($itemId, $status, $message) {
            $item = DianInboxSyncItem::query()->where('company_id', $this->companyId)->lockForUpdate()->findOrFail($itemId);
            if (in_array($item->status, ['Importado', 'Omitido', 'Fallido'], true)) {
                return;
            }
            $item->update(['status' => $status, 'message' => $message]);

            $sync = DianInboxSync::query()->where('company_id', $this->companyId)->lockForUpdate()->findOrFail($item->sync_id);
            $sync->processed = min($sync->total, $sync->processed + 1);
            $sync->received += $status === 'Importado' ? 1 : 0;
            $sync->errors += $status === 'Fallido' ? 1 : 0;
            if ($sync->processed >= $sync->total) {
                $sync->status = $sync->errors === 0 ? 'Exitosa' : ($sync->received > 0 ? 'Parcial' : 'Fallida');
                $sync->observations = "Procesados {$sync->processed} de {$sync->total}; nuevos: {$sync->received}; omitidos: ".($sync->processed - $sync->received - $sync->errors)."; errores: {$sync->errors}.";
                if ($sync->errors > 0) {
                    $details = $sync->items()->where('status', 'Fallido')->limit(3)->get(['cufe', 'message'])
                        ->map(fn (DianInboxSyncItem $failed) => mb_substr($failed->cufe, 0, 12).'…: '.$failed->message)->implode(' | ');
                    $sync->observations = mb_substr($sync->observations.' '.$details, 0, 1000);
                }
            } else {
                $sync->status = 'Procesando';
                $sync->observations = "Procesados {$sync->processed} de {$sync->total}; nuevos: {$sync->received}; errores: {$sync->errors}.";
            }
            $sync->save();
        });
    }
}
