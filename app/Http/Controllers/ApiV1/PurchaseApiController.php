<?php

namespace App\Http\Controllers\ApiV1;

use App\Http\Controllers\DocumentFormController;
use App\Models\DocumentType;
use App\Models\PurchaseDocument;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\DocumentShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Facturas de compra y documentos soporte: /compras y /documentos-soporte. */
class PurchaseApiController extends ApiBaseController
{
    use BuildsTradeDocuments;

    public const KINDS = [
        'compras' => ['doc' => 'FC', 'form' => 'compras/facturas/crear', 'label' => 'factura de compra'],
        'documentos-soporte' => ['doc' => 'DS', 'form' => 'compras/documentos-soporte/crear', 'label' => 'documento soporte'],
    ];

    public function index(Request $request, string $clase): JsonResponse
    {
        $this->authorizeApi('compras.ver');
        $q = PurchaseDocument::query()->with('contact')->where('type', self::KINDS[$clase]['doc'])
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('date', '>=', $request->input('desde')))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('date', '<=', $request->input('hasta')))
            ->when($request->filled('numero'), fn ($q) => $q->where('name', $request->input('numero')))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('contact_id', $request->integer('proveedor_id')))
            ->when($request->filled('identificacion'), fn ($q) => $q->whereHas('contact', fn ($c) => $c->where('identification', preg_replace('/\D/', '', (string) $request->input('identificacion')))))
            ->when($request->filled('factura_proveedor'), fn ($q) => $q->where('supplier_number', $request->input('factura_proveedor')))
            ->when($request->filled('estado'), fn ($q) => $request->input('estado') === 'anulado' ? $q->where('status', 'annulled') : $q->where('status', '!=', 'annulled'))
            ->when($request->boolean('con_saldo'), fn ($q) => $q->where('balance', '>', 0)->where('status', '!=', 'annulled'))
            ->when($request->filled('creado_desde'), fn ($q) => $q->where('created_at', '>=', $request->input('creado_desde')))
            ->when($request->filled('actualizado_desde'), fn ($q) => $q->where('updated_at', '>=', $request->input('actualizado_desde')));

        return $this->paged($q->orderByDesc('date')->orderByDesc('number')->paginate($this->perPage($request)), fn ($d) => ApiPresenter::purchase($d));
    }

    public function show(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('compras.ver');

        return response()->json(ApiPresenter::purchase($this->find($clase, $id)->load('items', 'contact'), true));
    }

    public function store(Request $request, string $clase): JsonResponse
    {
        $this->authorizeApi('compras.crear');
        [$doc, $dian] = $this->save($request, $clase);

        return response()->json(['mensaje' => "Se creó {$doc->name}.", 'dian' => $dian, 'data' => ApiPresenter::purchase($doc->fresh(['items', 'contact']), true)], 201);
    }

    public function update(Request $request, int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('compras.editar');
        $current = $this->find($clase, $id);
        if ($reason = app(DocumentReverser::class)->blocker($current, true)) {
            return response()->json(['message' => $reason], 409);
        }
        [$doc, $dian] = $this->save($request, $clase, $current);

        return response()->json(['mensaje' => "Se actualizó {$doc->name}.", 'dian' => $dian, 'data' => ApiPresenter::purchase($doc->fresh(['items', 'contact']), true)]);
    }

    public function annul(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('compras.anular');
        $doc = $this->find($clase, $id);
        $this->attempt(fn () => app(DocumentReverser::class)->annul($doc));

        return response()->json(['mensaje' => "Se anuló {$doc->name}.", 'data' => ApiPresenter::purchase($doc->fresh('contact'))]);
    }

    public function xml(Request $request, int $id, string $clase): StreamedResponse|JsonResponse
    {
        $this->authorizeApi('compras.ver');
        $doc = $this->find($clase, $id);
        $path = $request->input('tipo') === 'respuesta' ? $doc->dian_response_path : $doc->xml_path;
        $disk = DocumentShare::disk();
        if (! $path || ! $disk->exists($path)) {
            return response()->json(['message' => "{$doc->name} no tiene XML. Solo existe para documentos soporte electrónicos."], 404);
        }

        return $disk->download($path, basename($path), ['Content-Type' => 'application/xml']);
    }

    public function sendDian(int $id, string $clase): JsonResponse
    {
        $this->authorizeApi('compras.crear');
        $doc = $this->find($clase, $id);
        if (! DocumentType::query()->find($doc->document_type_id)?->isElectronic()) {
            return response()->json(['message' => "{$doc->name} no es un documento electrónico."], 422);
        }
        if ($doc->dian_status === 'Accepted') {
            return response()->json(['message' => "La DIAN ya aceptó {$doc->name}."], 409);
        }
        $message = app(DocumentFormController::class)->emit($doc);

        return response()->json(['mensaje' => $message, 'data' => ApiPresenter::purchase($doc->fresh('contact'))]);
    }

    /** @return array{0: PurchaseDocument, 1: ?string} */
    private function save(Request $request, string $clase, ?PurchaseDocument $current = null): array
    {
        $kind = self::KINDS[$clase];
        $input = $request->all();
        $data = $this->tradeData($input, $kind['doc'], true);
        $data['document_type'] = $current?->document_type_id ?? $this->documentType($input, $kind['doc']);
        $data['supplier_prefix'] = $input['factura_proveedor']['prefijo'] ?? null;
        $data['supplier_number'] = $input['factura_proveedor']['numero'] ?? null;
        $doc = $this->registerTrade($kind['form'], $data, $current);

        return [$doc, $this->emitIfElectronic($doc, $request->input('enviar_dian', true) !== false && $request->input('enviar_dian') !== 'false')];
    }

    private function find(string $clase, int $id): PurchaseDocument
    {
        return PurchaseDocument::query()->where('type', self::KINDS[$clase]['doc'])->find($id)
            ?? abort(404, 'No existe el documento '.self::KINDS[$clase]['label']." con id {$id}.");
    }
}
