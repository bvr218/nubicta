<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\BankAccount;
use App\Models\CostCenter;
use App\Models\DocumentType;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\Tax;
use App\Models\Warehouse;
use App\Services\Dian\DianService;
use App\Services\Documents\DocumentLocator;
use App\Services\Documents\DocumentReverser;
use App\Services\Documents\InventoryRegistrar;
use App\Services\Documents\Numbering;
use App\Services\Documents\JournalRegistrar;
use App\Services\Documents\ReceiptRegistrar;
use App\Services\Documents\TradeRegistrar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formularios de creacion del menu "Crear".
 * Guardar registra el documento, mueve inventario y saldos, contabiliza y, si es electronico, lo envia a la DIAN.
 */
class DocumentFormController extends Controller
{
    /** Tipos de documento que Siigo no expone por API: se muestran con la numeracion que tiene Siigo. */
    public const FALLBACK_TYPES = [
        'DS' => [['code' => '1', 'name' => 'Documento soporte', 'consecutive' => 297]],
        'NA' => [['code' => '1', 'name' => 'Nota de ajuste documento soporte', 'consecutive' => 1]],
        'ND' => [['code' => '1', 'name' => 'Nota débito', 'consecutive' => 1]],
        'CE' => [['code' => '1', 'name' => 'Comprobante de Egreso x PC', 'consecutive' => 1]],
        'CT' => [['code' => '1', 'name' => 'Cotización', 'consecutive' => 1]],
        'RM' => [['code' => '1', 'name' => 'Remisión No', 'consecutive' => 5]],
        'OC' => [['code' => '1', 'name' => 'Orden de compra principal', 'consecutive' => 1]],
        'NE' => [['code' => '1', 'name' => 'Nota de ensamble', 'consecutive' => 5]],
    ];

    private const PREFIX = ['CE' => 'RP', 'CT' => 'C', 'ND' => 'DF'];

    public const FORMS = [
        'ventas/facturas/crear' => [
            'page' => 'Trade', 'perm' => 'ventas.crear', 'title' => 'Nueva factura de venta / Ingresos', 'doc' => 'FV', 'party' => 'cliente',
            'seller' => true, 'payments' => true, 'retentions' => ['ReteICA'], 'buttons' => ['Guardar', 'Enviar'], 'logo' => true, 'dian_banner' => true,
        ],
        'ventas/notas-credito/crear' => [
            'page' => 'Trade', 'perm' => 'ventas.crear', 'title' => 'Nueva nota crédito', 'doc' => 'NC', 'party' => 'cliente', 'related' => 'ventas',
            'related_label' => 'Factura', 'seller' => true, 'payments' => true, 'item_taxes' => true, 'net' => true, 'observations' => true,
            'item_withholdings' => ['Retefuente'], 'buttons' => ['Guardar', 'Guardar y enviar'], 'dian_reason' => \App\Services\Dian\CorrectionConcepts::NC,
        ],
        'ventas/notas-debito/crear' => [
            'page' => 'Trade', 'perm' => 'ventas.crear', 'title' => 'Nueva nota débito (Ventas)', 'doc' => 'ND', 'party' => 'cliente', 'related' => 'ventas',
            'related_label' => 'Factura', 'seller' => true, 'payments' => true, 'item_taxes' => true, 'net' => true, 'observations' => true,
            'item_withholdings' => ['Retefuente'], 'buttons' => ['Guardar', 'Guardar y enviar'], 'dian_reason' => \App\Services\Dian\CorrectionConcepts::ND,
        ],
        'ventas/cotizaciones/crear' => [
            'page' => 'Trade', 'perm' => 'ventas.crear', 'title' => 'Nueva Cotización', 'doc' => 'CT', 'party' => 'cliente', 'seller' => true,
            'seller_label' => 'Responsable de la cotización', 'header_text' => 'Encabezado', 'footer_text' => 'Condiciones Comerciales', 'net' => true,
            'item_taxes' => true, 'item_withholdings' => ['Retefuente'], 'sorted_taxes' => 'all', 'seller_left' => true, 'number_text' => 'Numeración Automática', 'buttons' => ['Guardar', 'Guardar y enviar por email'],
        ],
        'ventas/remisiones/crear' => [
            'page' => 'Trade', 'perm' => 'ventas.crear', 'title' => 'Nueva Remisión', 'doc' => 'RM', 'party' => 'cliente', 'seller' => true,
            'reason' => ['Operación constituye venta', 'Ventas por efectuar', 'Consignaciones', 'Productos en demostración', 'Traslados internos', 'Otros traslados no venta', 'Guía de devolución electrónica'], 'item_taxes' => true,
            'item_withholdings' => ['Retefuente'], 'sorted_taxes' => 'all', 'seller_left' => true, 'net' => true, 'observations' => true,
            'buttons' => ['Guardar', 'Guardar y enviar por mail'],
        ],
        'compras/facturas/crear' => [
            'page' => 'Trade', 'perm' => 'compras.crear', 'title' => 'Nueva factura de compra', 'doc' => 'FC', 'party' => 'proveedor', 'purchase' => true,
            'import_xml' => true, 'supplier_number' => true, 'cost_center' => true,
            'checks' => ['Proveedor por ítem', 'IVA / Impoconsumo incluido', 'Descuento en porcentaje'],
            'item_type' => true, 'item_taxes' => true, 'payments' => true, 'credit_toggle' => true, 'observations' => true, 'buttons' => ['Guardar'],
        ],
        'compras/documentos-soporte/crear' => [
            'page' => 'Trade', 'perm' => 'compras.crear', 'title' => 'Nuevo documento soporte', 'doc' => 'DS', 'party' => 'proveedor', 'purchase' => true,
            'supplier_number' => 'No. comprobante proveedor', 'checks' => ['Descuento en porcentaje'], 'item_type' => true, 'item_taxes' => true,
            'payments' => true, 'credit_toggle' => true, 'net' => true,
            'observations' => true, 'buttons' => ['Guardar', 'Guardar y enviar'],
        ],
        'compras/notas-debito/crear' => [
            'page' => 'Trade', 'perm' => 'compras.crear', 'title' => 'Nueva nota débito / Nota de ajuste Documento Soporte', 'doc' => 'NA', 'party' => 'proveedor',
            'purchase' => true, 'related' => 'compras', 'related_label' => 'No. de compra / Doc. Soporte', 'supplier_number_readonly' => true,
            'checks' => ['IVA / Impoconsumo incluido', 'Incluye otros Impuestos cargo', 'Proveedor por item'], 'item_type' => true, 'item_taxes' => true,
            'payments' => true, 'observations' => true, 'buttons' => ['Guardar'],
        ],
        'compras/notas-ajuste/crear' => [
            'page' => 'Trade', 'perm' => 'compras.crear', 'title' => 'Nueva nota débito / Nota de ajuste Documento Soporte', 'doc' => 'NA', 'party' => 'proveedor',
            'purchase' => true, 'related' => 'compras', 'related_label' => 'No. de compra / Doc. Soporte', 'supplier_number_readonly' => true,
            'checks' => ['IVA / Impoconsumo incluido', 'Incluye otros Impuestos cargo', 'Proveedor por item'], 'item_type' => true, 'item_taxes' => true,
            'payments' => true, 'observations' => true, 'buttons' => ['Guardar'],
        ],
        'compras/ordenes/crear' => [
            'page' => 'Trade', 'perm' => 'compras.crear', 'title' => 'Nueva orden de compra', 'doc' => 'OC', 'party' => 'proveedor', 'purchase' => true,
            'checks' => ['Proveedor por ítem', 'Impuestos cargo incluidos', 'Descuento en porcentaje'], 'item_type' => true, 'item_taxes' => true,
            'retentions' => ['ReteIVA', 'ReteICA'], 'net' => true, 'observations' => true, 'buttons' => ['Guardar y enviar por mail'], 'classic' => true,
        ],
        'ventas/recibos/crear' => ['page' => 'CashReceipt', 'perm' => 'ventas.crear', 'title' => 'Crear recibo de caja', 'doc' => 'RC', 'party' => 'cliente'],
        'compras/egresos/crear' => ['page' => 'PaymentReceipt', 'perm' => 'compras.crear', 'title' => 'Nuevo recibo de pago / egreso', 'doc' => 'CE', 'party' => 'proveedor'],
        'contabilidad/comprobantes/crear' => ['page' => 'JournalEntry', 'perm' => 'contabilidad.crear', 'title' => 'Nuevo comprobante contable', 'doc' => 'CC'],
        'contabilidad/ajustes-cartera/crear' => ['page' => 'PortfolioAdjustment', 'perm' => 'contabilidad.crear', 'title' => 'Ajuste de cartera / Proveedor', 'doc' => 'CC', 'party' => 'otro'],
        'bancos/traslados/crear' => ['page' => 'MoneyTransfer', 'perm' => 'bancos.crear', 'title' => 'Nuevo traslado de dinero', 'doc' => 'CC', 'party' => 'otro'],
        'inventario/traslados/crear' => ['page' => 'Inventory', 'perm' => 'inventario.crear', 'title' => 'Nueva nota de traslado entre bodegas', 'kind' => 'traslado', 'buttons' => ['Guardar', 'Guardar y descargar']],
        'inventario/ajustes/crear' => ['page' => 'Inventory', 'perm' => 'inventario.crear', 'title' => 'Nuevo ajuste de inventario', 'kind' => 'ajuste', 'buttons' => ['Guardar']],
        'inventario/conteos/crear' => ['page' => 'Inventory', 'perm' => 'inventario.crear', 'title' => 'Nuevo conteo físico / Ajuste', 'kind' => 'conteo', 'buttons' => ['Guardar']],
        'inventario/ensambles/crear' => ['page' => 'Assembly', 'perm' => 'inventario.crear', 'title' => 'Nueva nota de ensamble', 'doc' => 'NE'],
    ];

    /** ?copiar=kind:id abre el formulario con los datos de otro documento (copia con fecha de hoy y numero nuevo). */
    public function create(Request $request): Response
    {
        $key = $request->route()->defaults['form'];
        $initial = null;
        if ($ref = $request->input('copiar')) {
            [$kind, $id] = explode(':', (string) $ref) + [null, null];
            $doc = isset(DocumentLocator::KINDS[$kind]) ? DocumentLocator::KINDS[$kind]::query()->find((int) $id) : null;
            if ($doc && DocumentReverser::formKey($doc) === $key) {
                \Illuminate\Support\Facades\Gate::authorize(DocumentLocator::module($doc).'.ver');
                $initial = ['date' => now()->toDateString()] + DocumentLocator::payload($doc);
            }
        }
        $sources = ['ventas/facturas/crear' => ['CT', 'RM'], 'ventas/remisiones/crear' => ['CT']];
        if (isset($sources[$key]) && ($src = \App\Models\SalesDocument::query()->whereIn('type', $sources[$key])->where('status', '!=', 'annulled')->find($request->integer('desde')))) {
            $initial = collect(DocumentLocator::payload($src))->except(['document_type', 'date', 'related_id', 'related_label', 'payments', 'credit', 'due_date'])->all()
                + ['date' => now()->toDateString(), 'source_id' => $src->id];
        }
                if ($key === 'contabilidad/comprobantes/crear' && in_array($request->input('cruce'), ['compras', 'ventas'], true) && $request->integer('tercero')) {
            $lines = app(\App\Services\Documents\AdvanceCrossing::class)->journalLines($request->input('cruce'), $request->integer('tercero'));
            if ($lines) {
                $initial = array_filter([
                    'document_type' => $request->integer('tipo') ?: null, 'date' => $request->date('fecha')?->toDateString(), 'items' => $lines,
                    'observations' => $request->input('cruce') === 'ventas' ? 'Cruce cartera con anticipos' : 'Cruce deuda con anticipos',
                ]);
            }
        }
        if (in_array($key, ['ventas/notas-credito/crear', 'ventas/notas-debito/crear'], true)
            && ($invoice = \App\Models\SalesDocument::query()->where('type', 'FV')->where('status', '!=', 'annulled')->with('contact:id,name,identification')->find($request->integer('factura')))) {
            \Illuminate\Support\Facades\Gate::authorize('ventas.ver');
            $initial = ['related_id' => $invoice->id, 'related_label' => "{$invoice->name} - ".($invoice->contact?->name ?? ''), 'contact_id' => $invoice->contact_id,
                'contact_label' => $invoice->contact ? "{$invoice->contact->identification} - {$invoice->contact->name}" : '', 'seller_id' => $invoice->seller_id, 'items' => []];
        }
        if ($key === 'compras/egresos/crear' && ($bill = \App\Models\PurchaseDocument::query()->where('status', '!=', 'annulled')->where('balance', '>', 0)->find($request->integer('factura')))) {
            $supplier = \App\Models\Contact::query()->find($bill->contact_id);
            $initial = ['contact_id' => $bill->contact_id, 'contact_label' => $supplier ? "{$supplier->identification} - {$supplier->name}" : '', 'mode' => 'deuda',
                'value' => (float) $bill->balance, 'items' => [['id' => $bill->id, 'pay' => (float) $bill->balance, 'balance' => (float) $bill->balance]]];
        }
        if ($key === 'compras/facturas/crear' && ($inbox = \App\Models\DianInboxDocument::query()->where('status', 'Pendiente')->find((int) $request->input('bandeja')))) {
            $initial = app(\App\Services\Purchases\PurchaseInbox::class)->initial($inbox);
        }
        if ((self::FORMS[$key]['purchase'] ?? false) && ($support = \App\Models\ExpenseSupport::query()->where('status', 'Sin causar')->find((int) $request->input('soporte')))) {
            $initial = [...($initial ?? []), 'support_id' => $support->id, 'observations' => "Soporte: {$support->name}"];
        }

        return $this->render($key, [], $initial);
    }

    /** Abre el formulario de creacion con los datos del documento; al guardar conserva su numero. */
    public function edit(string $kind, int $id): Response|RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        $key = DocumentReverser::formKey($doc);
        if (! $key) {
            return back()->with('error', 'Este documento no se puede editar; anúlalo y créalo de nuevo.');
        }
        if ($reason = app(DocumentReverser::class)->blocker($doc)) {
            return back()->with('error', $reason);
        }
        \Illuminate\Support\Facades\Gate::authorize(DocumentLocator::module($doc).'.editar');

        return $this->render($key, [
            'title' => "Editar {$doc->name}", 'url' => "/documentos/$kind/$id/editar", 'back' => "/documentos/$kind/$id",
        ], DocumentLocator::payload($doc));
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        $doc = DocumentLocator::find($kind, $id);
        $key = DocumentReverser::formKey($doc) ?? abort(404);
        \Illuminate\Support\Facades\Gate::authorize(DocumentLocator::module($doc).'.editar');
        $request->merge(['document_type' => $doc->document_type_id]);

        $saved = DB::transaction(function () use ($request, $key, $doc) {
            [$type, $id] = [$doc::class, $doc->id];
            [$number, $name] = app(DocumentReverser::class)->prepareEdit($doc);
            app(Numbering::class)->reuse($number, $name);
            $result = $this->persist($request, $key);
            Attachment::where('attachable_type', $type)->where('attachable_id', $id)
                ->update(['attachable_type' => $result[0]::class, 'attachable_id' => $result[0]->id]);

            return $result;
        });
        [$saved, $message] = $saved;

        return redirect('/documentos/'.DocumentLocator::kind($saved).'/'.$saved->id)->with('success', str_replace('Se guardó', 'Se actualizó', $message));
    }

    /** Marca como causada la factura de la Bandeja DIAN o el soporte por legalizar desde el que se creo la compra. */
    private function linkPurchaseSources(Request $request, PurchaseDocument $doc): void
    {
        if ($inbox = \App\Models\DianInboxDocument::query()->where('status', 'Pendiente')->find((int) $request->input('inbox_id'))) {
            $inbox->update(['status' => 'Causada', 'purchase_document_id' => $doc->id]);
            Attachment::copyFrom($doc, $inbox->xml_path, "{$inbox->number}.xml");
        }
        if ($support = \App\Models\ExpenseSupport::query()->where('status', 'Sin causar')->find((int) $request->input('support_id'))) {
            $support->update(['status' => 'Causado', 'purchase_document_id' => $doc->id]);
            Attachment::copyFrom($doc, $support->path, $support->name);
        }
    }

    private function render(string $key, array $override = [], ?array $initial = null): Response
    {
        $form = self::FORMS[$key];
        if (($form['doc'] ?? null) === 'FV') {
            $types = DocumentType::query()->where('type', 'FV')->where('active', true)->get();
            if ($types->isNotEmpty() && ! $types->contains(fn ($t) => $t->isElectronic())) {
                $form['dian_banner'] = false;
                $label = $types->first()->title ?: $types->first()->name;
                if ($label && ! isset($override['title'])) {
                    $form['title'] = 'Nuevo '.$label;
                }
            }
        }
        if (($form['purchase'] ?? false) && ! ($form['classic'] ?? false)) {
            $form += [
                'item_types' => ['Producto' => 'Producto', 'Activo fijo' => 'Activo fijo', 'Cuenta contable' => 'Gasto / Cuenta contable'],
                'item_withholdings' => ['Retefuente'], 'sorted_taxes' => true,
            ];
        }

        return Inertia::render('Forms/'.$form['page'], [
            'initial' => $initial,
            'form' => [...$form, 'url' => '/'.$key, 'back' => $this->backUrl($key), ...$override],
            'docTypes' => isset($form['doc']) ? $this->docTypes($form['doc']) : [],
            'paymentMethods' => $this->paymentMethods($form),
            'charges' => Tax::query()->where('active', true)->whereNotIn('type', Tax::WITHHOLDINGS)->orderBy('code')->orderBy('id')->get(['id', 'name', 'type', 'percentage', 'fixed_value']),
            'withholdings' => Tax::query()->where('active', true)->whereIn('type', Tax::WITHHOLDINGS)->orderBy('code')->orderBy('id')->get(['id', 'name', 'type', 'percentage']),
            'sellers' => Seller::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'defaultSeller' => $this->sellerOfUser(),
            'costCenters' => CostCenter::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'concepts' => \App\Models\FrequentConcept::query()->where('active', true)->orderBy('name')->get(['id', 'kind', 'name']),
            'priceLists' => PriceList::query()->where('active', true)->orderBy('position')->get(['id', 'name']),
            'banks' => BankAccount::query()->where('active', true)->with('account:id,code,name')->orderBy('name')->get(['id', 'name', 'account_id']),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $key = $request->route()->defaults['form'];
        [$doc, $message] = $this->persist($request, $key);

        return match ($request->input('after')) {
            'nuevo' => redirect('/'.$key)->with('success', $message),
            'descargar' => redirect(DocumentLocator::url($doc).'/imprimir')->with('success', $message),
            default => redirect(DocumentLocator::url($doc))->with('success', $message),
        };
    }

    /** @return array{0: \Illuminate\Database\Eloquent\Model, 1: string} */
    private function persist(Request $request, string $key): array
    {
        $form = self::FORMS[$key];

        $rules = ['date' => ['required', 'date']];
        if ($form['party'] ?? null) {
            $rules['contact_id'] = ['required', 'integer', 'exists:contacts,id'];
        }
        if (isset($form['doc']) && ! in_array($form['page'], ['MoneyTransfer', 'PortfolioAdjustment'], true)) {
            $rules['document_type'] = ['required'];
        }
        $messages = [
            'contact_id.required' => 'El campo '.match ($form['party'] ?? '') { 'proveedor' => 'proveedor', 'cliente' => 'cliente', default => 'tercero' }.' es obligatorio',
            'document_type.required' => 'El campo tipo es obligatorio',
            'date.required' => 'La fecha de elaboración es obligatoria',
        ];
        if (isset($form['dian_reason'])) {
            $rules['reason'] = ['required', \Illuminate\Validation\Rule::in(array_map('strval', array_keys($form['dian_reason'])))];
            $messages['reason.required'] = 'Selecciona el motivo DIAN';
            $messages['reason.in'] = 'Selecciona el motivo DIAN';
        }
        $rules['attachment'] = ['nullable', 'file', 'max:10240'];
        $messages['attachment.max'] = 'El archivo adjunto no puede superar 10 MB';
        $request->validate($rules, $messages);
        $this->validateLines($request, $form);
        if (isset($form['dian_reason'])) {
            $this->validateCorrection($request, $form);
        }

        $data = $request->all();
        try {
            $doc = match ($form['page']) {
                'Trade' => app(TradeRegistrar::class)->register($form, $data, self::FALLBACK_TYPES[$form['doc']] ?? []),
                'CashReceipt' => app(ReceiptRegistrar::class)->cash($data),
                'PaymentReceipt' => app(ReceiptRegistrar::class)->payment($data, self::FALLBACK_TYPES['CE']),
                'JournalEntry' => app(JournalRegistrar::class)->journal($data),
                'MoneyTransfer' => app(JournalRegistrar::class)->moneyTransfer($data),
                'PortfolioAdjustment' => app(JournalRegistrar::class)->portfolioAdjustment($data),
                'Inventory' => app(InventoryRegistrar::class)->register($form['kind'], $data),
                'Assembly' => app(InventoryRegistrar::class)->register('ensamble', $data, self::FALLBACK_TYPES['NE']),
            };
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
        if ($file = $request->file('attachment')) {
            Attachment::store($doc, $file);
        }
        if ($doc instanceof PurchaseDocument) {
            $this->linkPurchaseSources($request, $doc);
        }

        $message = "Se guardó el documento {$doc->name}".(isset($doc->total) ? ' por $ '.number_format((float) $doc->total, 2, ',', '.') : '').'.';
        if (($doc instanceof SalesDocument || $doc instanceof PurchaseDocument) && DocumentType::query()->find($doc->document_type_id)?->isElectronic()) {
            $message .= ' '.$this->emit($doc);
        }

        return [$doc, $message];
    }

    /** Envia a la DIAN; si falla el documento queda guardado como borrador electronico para reenviarlo. */
    public function emit(SalesDocument|PurchaseDocument $doc): string
    {
        try {
            $dian = DianService::forCurrent();
            $r = $dian->emit($doc);

            return ($dian->settings()->simulated ? 'DIAN (simulado): ' : 'DIAN: ').$r['message']
                .($r['valid'] && $doc instanceof SalesDocument ? ' El envío por correo al cliente quedó en cola.' : '');
        } catch (\App\Services\Dian\DianUnavailableException|\Illuminate\Http\Client\ConnectionException $e) {
            $doc->forceFill(['dian_status' => 'Pending', 'dian_message' => mb_substr($e->getMessage(), 0, 500)])->save();
            \App\Jobs\EmitDianDocument::dispatch($doc::class, $doc->id, $doc->company_id)->delay(now()->addMinute());

            return 'La DIAN no respondió; el documento quedó en cola y se enviará automáticamente.';
        } catch (\Throwable $e) {
            report($e);
            $doc->forceFill(['dian_status' => 'Draft', 'dian_message' => mb_substr($e->getMessage(), 0, 500)])->save();

            return 'No se envió a la DIAN: '.$e->getMessage();
        }
    }

    /** La nota electronica debe referenciar la factura, y una factura electronica solo se corrige con nota electronica. */
    private function validateCorrection(Request $request, array $form): void
    {
        $type = is_numeric($request->input('document_type')) ? DocumentType::query()->find((int) $request->input('document_type')) : null;
        $invoice = $request->integer('related_id') ? \App\Models\SalesDocument::query()->where('type', 'FV')->find($request->integer('related_id')) : null;
        $ext = $request->input('external_invoice');
        $external = $form['doc'] === 'NC' && is_array($ext) && filled($ext['numero'] ?? null) && filled($ext['cufe'] ?? null) && filled($ext['fecha'] ?? null);
        $label = $form['doc'] === 'NC' ? 'nota crédito' : 'nota débito';
        if ($invoice && $external) {
            throw ValidationException::withMessages(['related_id' => 'Indica la factura del sistema o una factura externa, no las dos']);
        }
        if ($type?->isElectronic() && ! $invoice && ! $external) {
            throw ValidationException::withMessages(['related_id' => "La {$label} electrónica debe indicar la factura que corrige"]);
        }
        if ($type && ! $type->isElectronic() && $invoice && (filled($invoice->cufe) || DocumentType::query()->find($invoice->document_type_id)?->isElectronic())) {
            $electronic = DocumentType::query()->where('type', $form['doc'])->where('active', true)->get()->first(fn ($t) => $t->isElectronic());
            throw ValidationException::withMessages(['document_type' => "La factura {$invoice->name} es electrónica: la {$label} debe ser electrónica para enviarse a la DIAN"
                .($electronic ? " (elige el tipo {$form['doc']} - {$electronic->code})." : '. Configura un tipo de documento electrónico.')]);
        }
    }

    /** Reglas por tipo de formulario; devuelve el total calculado cuando aplica. */
    private function validateLines(Request $request, array $form): ?float
    {
        $lines = collect($request->input('items', []));
        $fail = fn (string $msg) => throw \Illuminate\Validation\ValidationException::withMessages(['items' => $msg]);

        switch ($form['page']) {
            case 'Trade':
                $valid = $lines->filter(fn ($l) => (
                    ($l['product_id'] ?? $l['account_id'] ?? $l['fixed_asset_id'] ?? null) || ! empty($l['free'])
                ) && (float) ($l['quantity'] ?? 0) > 0);
                if ($valid->isEmpty()) {
                    $fail('Debes agregar al menos un ítem con cantidad');
                }
                foreach ($valid as $l) {
                    if ((float) ($l['price'] ?? 0) < 0 && ($l['tax_code'] ?? null) !== 'ZZ') {
                        $fail('El precio negativo solo se permite en una línea con impuesto No aplica (ZZ)');
                    }
                    if (($l['tax_code'] ?? null) === 'ZZ' && ! empty($l['tax_id'])) {
                        $fail('No aplica (ZZ) no se combina con otro impuesto');
                    }
                }
                if ($request->boolean('mandate') && $valid->contains(fn ($l) => ($l['role'] ?? null) === 'mandante')) {
                    $principal = $request->input('principal');
                    if (! is_array($principal) || ! filled($principal['identificacion'] ?? null) || ! filled($principal['nombre'] ?? null)) {
                        $fail('Indica el mandante: identificación y nombre');
                    }
                }
                $total = (float) $request->input('total', 0);
                if (! empty($form['payments']) && ! $request->boolean('credit') && $request->input('payment_form') !== 'contado') {
                    $paid = collect($request->input('payments', []))->sum(fn ($p) => (float) ($p['value'] ?? 0));
                    if (abs($paid - $total) > 0.009) {
                        $fail('El total de las formas de pago debe ser igual al total del documento');
                    }
                }

                return $total;
            case 'JournalEntry':
                $debit = $lines->sum(fn ($l) => (float) ($l['debit'] ?? 0));
                $credit = $lines->sum(fn ($l) => (float) ($l['credit'] ?? 0));
                if ($lines->contains(fn ($l) => ((float) ($l['debit'] ?? 0) + (float) ($l['credit'] ?? 0)) > 0 && empty($l['account_id']))) {
                    $fail('Todas las líneas con valor deben tener cuenta contable');
                }
                if ($debit <= 0 || abs($debit - $credit) > 0.009) {
                    $fail('El comprobante debe estar cuadrado: débitos y créditos iguales y mayores a cero');
                }

                return $debit;
            case 'CashReceipt':
            case 'PaymentReceipt':
                $value = (float) $request->input('value', 0);
                if ($value <= 0) {
                    $fail('Ingresa el valor '.($form['page'] === 'CashReceipt' ? 'recibido' : 'pagado'));
                }

                return $value;
            case 'MoneyTransfer':
                if ($lines->isEmpty() || $lines->contains(fn ($l) => empty($l['from_id']) || empty($l['to_id']) || (float) ($l['value'] ?? 0) <= 0)) {
                    $fail('Cada línea debe tener cuenta de salida, de entrada y valor');
                }

                return $lines->sum(fn ($l) => (float) $l['value']);
            default:
                if ($lines->filter(fn ($l) => ! empty($l['product_id']) || ! empty($l['concept']))->isEmpty()) {
                    $fail('Debes agregar al menos una línea');
                }

                return null;
        }
    }

    /** Listado del documento si existe; si no, el del modulo; si no, el inicio. */
    private function backUrl(string $key): string
    {
        if (str_starts_with($key, 'inventario/')) {
            return '/productos/documentos';
        }
        $parts = explode('/', preg_replace('#/crear$#', '', $key));
        while ($parts) {
            $url = '/'.implode('/', $parts);
            try {
                app('router')->getRoutes()->match(Request::create($url));

                return $url;
            } catch (\Throwable) {
                array_pop($parts);
            }
        }

        return '/';
    }

    private function docTypes(string $type): array
    {
        $prefix = self::PREFIX[$type] ?? $type;
        $rows = DocumentType::query()->where('type', $type)->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'consecutive', 'electronic_type'])
            ->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'label' => in_array($type, ['CT', 'RM'], true) ? "{$prefix}-{$d->code}-{$d->name}" : "{$prefix} - {$d->code} - {$d->name}", 'consecutive' => $d->consecutive, 'electronic' => $d->electronic_type !== 'NoElectronic'])
            ->all();

        return $rows ?: array_map(fn ($d) => [
            'id' => "{$type}-{$d['code']}", 'code' => $d['code'], 'label' => in_array($type, ['CT', 'RM'], true) ? "{$prefix}-{$d['code']}-{$d['name']}" : "{$prefix} - {$d['code']} - {$d['name']}", 'consecutive' => $d['consecutive'], 'electronic' => in_array($type, ['DS', 'NA'], true),
        ], self::FALLBACK_TYPES[$type] ?? []);
    }

    /** Se propone como vendedor al vendedor que corresponde al usuario que elabora el documento. */
    private function sellerOfUser(): ?int
    {
        $user = auth()->user();

        return $user ? Seller::query()->where('active', true)
            ->where(fn ($q) => $q->when($user->identification, fn ($w) => $w->where('identification', $user->identification))->orWhereRaw('LOWER(name) = ?', [mb_strtolower($user->name)]))
            ->value('id') : null;
    }

    private function paymentMethods(array $form): array
    {
        $doc = match (true) {
            in_array($form['doc'] ?? '', ['FV', 'NC', 'ND', 'CT', 'RM'], true) => 'FV',
            in_array($form['page'], ['CashReceipt'], true) => 'RC',
            in_array($form['page'], ['PaymentReceipt'], true) => 'CE',
            default => 'FC',
        };

        return PaymentMethod::query()->where('active', true)->with('account:id,code,name')->get(['id', 'name', 'type', 'due_date', 'document_types', 'account_id'])
            ->filter(fn ($p) => in_array($doc, (array) $p->document_types, true))
            ->sortBy(fn ($p) => ($p->account?->code ?? '9').'|'.$p->name)
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'due' => (bool) $p->due_date, 'credit' => (bool) $p->due_date || in_array($p->type, ['Cartera', 'Proveedor'], true),
                'account' => $p->account ? "{$p->account->code} {$p->account->name}" : '',
                'label' => trim($p->name.($p->account ? " - {$p->account->code} {$p->account->name}" : '')),
            ])
            ->values()->all();
    }
}
