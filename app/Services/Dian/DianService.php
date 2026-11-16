<?php

namespace App\Services\Dian;

use App\Models\Company;
use App\Models\DianResolution;
use App\Models\DianSetting;
use App\Models\DianTransmission;
use App\Models\DocumentType;
use App\Models\PayrollDocument;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class DianService
{
    private const TEST_PREFIX = ['fe' => 'SETP', 'nomina' => 'NOMP', 'ds' => 'SEDS'];

    /** Resolucion de la numeracion de pruebas que la DIAN asigna en habilitacion. */
    private const TEST_RESOLUTION = ['number' => '18760000001', 'valid_from' => '2019-01-19', 'valid_to' => '2030-01-19', 'from' => 990000000, 'to' => 995000000];

    private const FILE_PREFIX = ['FV' => 'fv', 'NC' => 'nc', 'ND' => 'nd', 'DS' => 'ds', 'NA' => 'nas'];

    public function __construct(private readonly DianSetting $settings, private readonly Company $company) {}

    public static function forCurrent(): self
    {
        $settings = DianSetting::current();

        return new self($settings, $settings->company);
    }

    public function settings(): DianSetting
    {
        return $this->settings;
    }

    /** Requisitos que faltan para enviar documentos del ámbito indicado. */
    public function missing(string $scope = 'fe'): array
    {
        [$software, $pin, $testSet] = $this->settings->softwareFor($scope);
        $c = $this->company;
        $missing = [];
        if (blank($c->nit) || $c->nit === '000000000') {
            $missing[] = 'NIT de la empresa';
        } elseif ($c->verificationDigit() === '') {
            $missing[] = 'DV del NIT de la empresa';
        }
        if (blank($c->business_name)) {
            $missing[] = 'Razón social de la empresa';
        }
        if (blank($c->address) || blank($c->municipality_code)) {
            $missing[] = 'Dirección y municipio de la empresa';
        }
        if (! $this->settings->hasCertificate()) {
            $missing[] = 'Certificado digital';
        }
        if (blank($software)) {
            $missing[] = 'Identificador del software';
        }
        if (blank($pin)) {
            $missing[] = 'PIN del software';
        }
        if ($this->settings->environment === 'habilitacion' && blank($testSet)) {
            $missing[] = 'TestSetId';
        }

        return $missing;
    }

    public function testConnection(): array
    {
        $r = $this->send('GetStatus', ['trackId' => str_repeat('0', 96)], ['scope' => 'fe']);

        return ['ok' => $r['http'] === 200, 'message' => $r['http'] === 200
            ? 'Conexión exitosa con el servicio web de la DIAN ('.$this->settings->environment.').'
            : 'El servicio de la DIAN respondió con código '.$r['http'].'.'];
    }

    /** @return list<array<string, mixed>> */
    public function numberingRanges(): array
    {
        $ranges = DianResolution::where('kind', 'FV')->whereDate('valid_to', '>=', now())->orderBy('resolution_date')->get()
            ->map(fn (DianResolution $r) => [
                'type' => $r->kind, 'resolution' => $r->resolution_number, 'resolution_date' => $r->resolution_date?->toDateString(),
                'prefix' => $r->prefix, 'from' => $r->range_from, 'to' => $r->range_to,
                'valid_from' => $r->valid_from?->toDateString(), 'valid_to' => $r->valid_to?->toDateString(),
                'technical_key' => $r->technical_key,
            ])->all() ?: config('dian.simulated_ranges');

        $r = $this->send('GetNumberingRange', [
            'accountCode' => $this->company->nit,
            'accountCodeT' => $this->company->nit,
            'softwareCode' => (string) $this->settings->software_id,
        ], ['scope' => 'fe'], $ranges);

        $code = $this->text($r['xml'], 'OperationCode');
        if ($code !== '100') {
            throw new RuntimeException('DIAN: '.($this->text($r['xml'], 'OperationDescription') ?: 'no fue posible consultar los rangos.'));
        }

        $out = [];
        foreach ($this->nodes($r['xml'], 'NumberRangeResponse') as $node) {
            $get = fn (string $tag) => $node->getElementsByTagNameNS('*', $tag)->item(0)?->textContent;
            $out[] = [
                'resolution' => $get('ResolutionNumber'), 'resolution_date' => $get('ResolutionDate'),
                'prefix' => $get('Prefix'), 'from' => (int) $get('FromNumber'), 'to' => (int) $get('ToNumber'),
                'valid_from' => $get('ValidDateFrom'), 'valid_to' => $get('ValidDateTo'), 'technical_key' => $get('TechnicalKey'),
            ];
        }

        return $out;
    }

    /** Aplica un rango devuelto por la DIAN al tipo de documento de factura electrónica. */
    public function applyRange(DocumentType $type, array $range): void
    {
        $type->update([
            'prefix' => $range['prefix'],
            'resolution_number' => $range['resolution'],
            'resolution_date' => $range['resolution_date'],
            'range_from' => $range['from'],
            'range_to' => $range['to'],
            'valid_from' => $range['valid_from'],
            'valid_to' => $range['valid_to'],
            'technical_key' => $range['technical_key'],
            'consecutive' => max((int) $type->consecutive, (int) $range['from']),
        ]);
    }

    /**
     * Emite una factura, nota o documento soporte: arma el UBL, lo firma, lo comprime, lo envia y guarda la respuesta.
     * En produccion (y en modo simulado) usa SendBillSync; en habilitacion real va al set de pruebas (SendTestSetAsync + GetStatusZip).
     *
     * @return array{valid:bool, status:string, message:string, key:string}
     */
    public function emit(SalesDocument|PurchaseDocument $doc): array
    {
        $purchase = $doc instanceof PurchaseDocument;
        $scope = $purchase ? 'ds' : 'fe';
        if (! $this->settings->simulated && ($missing = $this->missing($scope))) {
            throw new RuntimeException('Para emitir a la DIAN completa en Configuración > Facturación electrónica: '.implode(', ', $missing).'.');
        }
        if (! $this->settings->simulated && $this->settings->environment !== 'produccion') {
            throw new RuntimeException('La empresa está en habilitación: el documento queda pendiente y se envía al activar producción (php artisan dian:send-pending).');
        }
        $mapper = new DocumentMapper($this->settings, $this->company);
        $data = $purchase ? $mapper->purchase($doc) : $mapper->sales($doc);
        $built = (new UblBuilder)->build($data);
        $xml = $this->sign($built['xml']);

        $file = UblBuilder::fileName(self::FILE_PREFIX[$doc->type] ?? 'fv', $this->company->nit, (int) $doc->number, (int) $doc->date->format('Y'));
        $zipName = 'z'.substr($file, strlen(self::FILE_PREFIX[$doc->type] ?? 'fv'));
        $dir = 'dian/xml/'.$this->company->id.'/'.$doc->date->format('Y-m');
        Storage::disk('local')->put("$dir/$file.xml", $xml);
        $zip = $this->zip("$file.xml", $xml);
        Storage::disk('local')->put("$dir/$zipName.zip", $zip);

        $log = ['scope' => $scope, 'document_kind' => $doc->type, 'document_number' => $data['number'], ($purchase ? 'purchase_document_id' : 'sales_document_id') => $doc->id];
        $params = ['fileName' => "$zipName.zip", 'contentFile' => base64_encode($zip), 'number' => $data['number'], 'kind' => $doc->type, 'cufe' => $built['key']];

        if ($this->settings->environment === 'produccion' || $this->settings->simulated) {
            $r = $this->send('SendBillSync', $params, $log);
            $trackId = $built['key'];
        } else {
            [, , $testSet] = $this->settings->softwareFor($scope);
            $r = $this->send('SendTestSetAsync', $params + ['testSetId' => (string) $testSet], $log);
            $trackId = $this->text($r['xml'], 'ZipKey');
            if ($trackId) {
                $r = $this->statusZip($trackId, $params, $log);
            }
        }

        if ($r['http'] === 0 || $r['http'] >= 500) {
            throw new DianUnavailableException("El servicio de la DIAN no está disponible (HTTP {$r['http']}). El documento queda en cola y se reintenta automáticamente.");
        }
        $valid = $r['valid'];
        [$responsePath, $attachedPath] = $this->storeResponse($r['xml'], $dir, substr($file, strlen(self::FILE_PREFIX[$doc->type] ?? 'fv')), $data, $xml, $built['key'], $valid);
        $message = (string) ($this->text($r['xml'], 'StatusMessage') ?? $this->text($r['xml'], 'StatusDescription') ?? ($this->nodes($r['xml'], 'ProcessedMessage')[0]->textContent ?? 'Sin respuesta de la DIAN'));
        if (! $valid && ($rules = array_filter($this->errors($r['xml']), fn ($e) => str_contains($e, 'Rechazo')))) {
            $message .= ' '.implode(' ', $rules);
        }
        $status = $valid ? 'Accepted' : ($trackId ? 'Rejected' : 'Sent');
        $doc->forceFill(array_filter([
            $purchase ? 'cuds' : 'cufe' => $built['key'],
            'dian_status' => $status,
            'dian_track_id' => $trackId,
            'xml_path' => "$dir/$file.xml",
            'dian_message' => mb_substr($message, 0, 500),
            'qr_data' => $purchase ? null : $built['qr'],
            'dian_response_path' => $responsePath,
            'attached_path' => $attachedPath,
        ], fn ($v) => $v !== null))->save();
        if ($valid && ! $purchase && ! in_array($doc->mail_status, ['sent', 'read'], true)) {
            try {
                \App\Jobs\SendDocumentMail::enqueue($doc);
            } catch (\Throwable $e) {
                report($e);
                $doc->forceFill(['mail_status' => 'failed'])->save();
            }
        }

        return ['valid' => $valid, 'status' => $status, 'message' => $message, 'key' => $built['key']];
    }

    /**
     * "Actualizar documento electronico": consulta en la DIAN el estado del documento ya firmado (por su CUFE/CUDS) y, si lo
     * valido, deja la respuesta, el AttachedDocument y el QR. Si nunca se envio, o la DIAN no lo tiene y estaba pendiente, lo emite.
     *
     * @return array{valid:bool, status:string, message:string}
     */
    public function refresh(SalesDocument|PurchaseDocument $doc): array
    {
        $purchase = $doc instanceof PurchaseDocument;
        $key = $purchase ? $doc->cuds : $doc->cufe;
        if (blank($key) || blank($doc->xml_path) || ! Storage::disk('local')->exists($doc->xml_path)) {
            return $this->emit($doc);
        }
        $log = ['scope' => $purchase ? 'ds' : 'fe', 'document_kind' => $doc->type, 'document_number' => $doc->name, ($purchase ? 'purchase_document_id' : 'sales_document_id') => $doc->id];
        $r = $this->send('GetStatus', ['trackId' => $key, 'number' => $doc->name, 'kind' => $doc->type, 'cufe' => $key], $log + ['track_id' => $key]);
        if ($r['http'] === 0 || $r['http'] >= 500) {
            throw new DianUnavailableException("El servicio de la DIAN no está disponible (HTTP {$r['http']}). Intenta de nuevo más tarde.");
        }
        $message = (string) ($this->text($r['xml'], 'StatusMessage') ?? $this->text($r['xml'], 'StatusDescription') ?? 'Sin respuesta de la DIAN');
        if (! $r['valid']) {
            if (in_array($doc->dian_status, ['Draft', 'Rejected', 'Pending'], true)) {
                return $this->emit($doc);
            }

            return ['valid' => false, 'status' => (string) $doc->dian_status, 'message' => $message];
        }

        $mapper = new DocumentMapper($this->settings, $this->company);
        $data = $purchase ? $mapper->purchase($doc) : $mapper->sales($doc);
        $prefix = self::FILE_PREFIX[$doc->type] ?? 'fv';
        $file = pathinfo($doc->xml_path, PATHINFO_FILENAME);
        [$responsePath, $attachedPath] = $this->storeResponse($r['xml'], dirname($doc->xml_path), substr($file, strlen($prefix)), $data,
            Storage::disk('local')->get($doc->xml_path), $key, true);
        $doc->forceFill(array_filter([
            'dian_status' => 'Accepted',
            'dian_message' => mb_substr($message, 0, 500),
            'qr_data' => $purchase || filled($doc->qr_data) ? null : ((new UblBuilder)->build($data)['qr'] ?? null),
            'dian_response_path' => $responsePath,
            'attached_path' => $attachedPath,
        ], fn ($v) => $v !== null))->save();
        if (! $purchase && ! in_array($doc->mail_status, ['sent', 'read'], true)) {
            try {
                \App\Jobs\SendDocumentMail::enqueue($doc);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['valid' => true, 'status' => 'Accepted', 'message' => $message];
    }

    /**
     * Firma y envia un documento soporte de pago de nomina electronica o su nota de ajuste (SendNominaSync).
     * En habilitacion real va al set de pruebas de nomina (SendTestSetAsync + GetStatusZip).
     *
     * @return array{valid:bool, status:string, message:string}
     */
    public function emitPayroll(PayrollDocument $doc, array $data): array
    {
        if (! $this->settings->simulated && ($missing = $this->missing('nomina'))) {
            throw new RuntimeException('Para enviar la nómina electrónica completa en Configuración > Facturación electrónica: '.implode(', ', $missing).'.');
        }
        $built = (new NominaBuilder)->build($data);
        $xml = $this->sign($built['xml']);
        $prefix = $doc->note_type ? 'nai' : 'nie';
        $file = UblBuilder::fileName($prefix, $this->company->nit, (int) $doc->number);
        $dir = 'dian/nomina/'.$this->company->id.'/'.$doc->period;
        Storage::disk('local')->put("$dir/$file.xml", $xml);
        $zip = $this->zip("$file.xml", $xml);
        $log = ['scope' => 'nomina', 'document_kind' => $doc->note_type ? 'NA' : 'NE', 'document_number' => $data['number']];
        $params = ['fileName' => 'z'.substr($file, 3).'.zip', 'contentFile' => base64_encode($zip), 'number' => $data['number'], 'kind' => 'NE', 'cufe' => $built['cune']];

        if ($this->settings->environment === 'produccion' || $this->settings->simulated) {
            $r = $this->send('SendNominaSync', $params, $log);
        } else {
            [, , $testSet] = $this->settings->softwareFor('nomina');
            $r = $this->send('SendTestSetAsync', $params + ['testSetId' => (string) $testSet], $log);
            if ($trackId = $this->text($r['xml'], 'ZipKey')) {
                $r = $this->statusZip($trackId, $params, $log);
            }
        }
        if ($r['http'] === 0 || $r['http'] >= 500) {
            throw new DianUnavailableException("El servicio de la DIAN no está disponible (HTTP {$r['http']}). Intenta enviar de nuevo más tarde.");
        }
        $message = (string) ($this->text($r['xml'], 'StatusMessage') ?? $this->text($r['xml'], 'StatusDescription') ?? ($this->nodes($r['xml'], 'ProcessedMessage')[0]->textContent ?? 'Sin respuesta de la DIAN'));
        $status = $r['valid'] ? 'Aprobado' : 'Rechazado';
        $doc->forceFill(['cune' => $built['cune'], 'status' => $status, 'xml_path' => "$dir/$file.xml", 'dian_message' => mb_substr($message, 0, 500)])->save();
        if ($r['valid'] && $doc->predecessor_id) {
            PayrollDocument::query()->whereKey($doc->predecessor_id)->update(['status' => $doc->note_type === 2 ? 'Eliminado' : 'Reemplazado']);
        }

        return ['valid' => $r['valid'], 'status' => $status, 'message' => $message];
    }

    /**
     * Guarda el ApplicationResponse que devuelve la DIAN y, si el documento fue validado, arma y firma el AttachedDocument.
     *
     * @return array{0:?string, 1:?string}
     */
    private function storeResponse(string $responseXml, string $dir, string $suffix, array $data, string $signedXml, string $key, bool $valid): array
    {
        $encoded = $this->text($responseXml, 'XmlBase64Bytes');
        $ar = $encoded ? base64_decode($encoded, true) : false;
        if (! $ar) {
            return [null, null];
        }
        Storage::disk('local')->put("$dir/ar$suffix.xml", $ar);
        if (! $valid) {
            return ["$dir/ar$suffix.xml", null];
        }
        $now = now()->setTimezone('America/Bogota');
        $attached = (new AttachedDocumentBuilder)->build($data, $signedXml, $ar, [
            'key' => $key, 'code' => $this->text($ar, 'ResponseCode') ?? '02',
            'date' => $this->text($ar, 'IssueDate') ?? $now->toDateString(), 'time' => $this->text($ar, 'IssueTime') ?? $now->format('H:i:s').'-05:00',
        ]);
        Storage::disk('local')->put("$dir/ad$suffix.xml", $this->sign($attached));

        return ["$dir/ar$suffix.xml", "$dir/ad$suffix.xml"];
    }

    /** ZIP para el adquiriente (AttachedDocument + PDF), como lo exige la DIAN en el correo de entrega. */
    public static function attachedZip(SalesDocument|PurchaseDocument $doc, string $pdf): ?array
    {
        if (blank($doc->attached_path) || ! Storage::disk('local')->exists($doc->attached_path)) {
            return null;
        }
        $base = pathinfo($doc->attached_path, PATHINFO_FILENAME);
        $tmp = tempnam(sys_get_temp_dir(), 'adz');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString("$base.xml", Storage::disk('local')->get($doc->attached_path));
        $zip->addFile($pdf, "$base.pdf");
        $zip->close();

        return ['path' => $tmp, 'name' => 'z'.substr($base, 2).'.zip'];
    }

    /**
     * Consulta en la DIAN la factura del proveedor por su CUFE (GetXmlByDocumentKey) y extrae los datos del registro de eventos.
     *
     * @return array{number:string, nit:string, dv:string, id_type:string, name:string, payment_means:string, date:?string, vat:bool}|null
     */
    public function supplierInvoice(string $cufe): ?array
    {
        $xml = $this->xmlByDocumentKey($cufe, 'radian');
        $xp = $xml ? $this->xpath($xml) : null;
        if (! $xp) {
            return null;
        }
        if ($xp->document->documentElement->localName === 'AttachedDocument') {
            $inner = trim((string) $xp->query("/*/*[local-name()='Attachment']//*[local-name()='Description']")->item(0)?->textContent);
            $xp = $inner !== '' ? $this->xpath($inner) : null;
            if (! $xp) {
                return null;
            }
        }
        $q = fn (string $path) => ($n = $xp->query($path)->item(0)) ? trim($n->textContent) : null;
        $party = "/*/*[local-name()='AccountingSupplierParty']/*[local-name()='Party']";
        $company = $xp->query("$party/*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID']")->item(0);

        return [
            'number' => (string) $q("/*/*[local-name()='ID']"),
            'nit' => (string) $company?->textContent,
            'dv' => (string) $company?->getAttribute('schemeID'),
            'id_type' => (string) ($company?->getAttribute('schemeName') ?: '31'),
            'name' => (string) $q("$party/*[local-name()='PartyTaxScheme']/*[local-name()='RegistrationName']"),
            'payment_means' => (string) ($q("/*/*[local-name()='PaymentMeans']/*[local-name()='ID']") ?? '1'),
            'date' => $q("/*/*[local-name()='IssueDate']"),
            'vat' => $q("$party/*[local-name()='PartyTaxScheme']/*[local-name()='TaxScheme']/*[local-name()='ID']") === '01',
        ];
    }

    /** Descarga y decodifica el XML UBL asociado a un CUFE/CUDE. */
    public function xmlByDocumentKey(string $key, string $scope = 'dian_inbox'): ?string
    {
        $r = $this->send('GetXmlByDocumentKey', ['trackId' => $key], [
            'scope' => $scope, 'document_kind' => 'FV', 'document_number' => mb_substr($key, 0, 20),
        ]);
        if ($r['http'] === 0 || $r['http'] >= 500) {
            throw new DianUnavailableException("El servicio de la DIAN no está disponible (HTTP {$r['http']}). Intenta de nuevo más tarde.");
        }
        $code = $this->text($r['xml'], 'Code');
        if ($scope === 'dian_inbox' && $code !== null && $code !== '100') {
            throw new RuntimeException('DIAN: '.($this->text($r['xml'], 'Message') ?: 'No fue posible obtener el XML.'));
        }
        $encoded = $this->text($r['xml'], 'XmlBytesBase64');
        if (! $encoded) {
            return null;
        }
        $xml = base64_decode($encoded, true);
        if ($xml === false) {
            if ($scope === 'radian') {
                return null;
            }
            throw new RuntimeException('La DIAN devolvió un XML en base64 inválido.');
        }

        return $xml;
    }

    /**
     * Firma y envía un evento RADIAN (SendEventUpdateStatus) sobre la factura de un proveedor.
     *
     * @return array{valid:bool, message:string, cude:string, number:string, path:string}
     */
    public function sendRadianEvent(\App\Models\RadianEvent $event, string $code, ?string $claim = null): array
    {
        if (! $this->settings->simulated && ($missing = $this->missing('fe'))) {
            throw new RuntimeException('Para registrar eventos en la DIAN completa en Configuración > Facturación electrónica: '.implode(', ', $missing).'.');
        }
        [$softwareId, $pin] = $this->settings->softwareFor('fe');
        $sent = \App\Models\RadianEvent::query()->get(['events'])->sum(fn ($e) => count($e->events ?? []));
        $number = 'EV'.($sent + 1);
        $now = now()->setTimezone('America/Bogota');
        $mapper = new DocumentMapper($this->settings, $this->company);
        $built = (new RadianEventBuilder)->build([
            'number' => $number, 'code' => $code, 'claim' => $claim,
            'issue_date' => $now->toDateString(), 'issue_time' => $now->format('H:i:s').'-05:00',
            'environment' => $this->settings->environment === 'produccion' ? '1' : '2',
            'software' => ['id' => (string) $softwareId, 'pin' => (string) $pin, 'provider_nit' => preg_replace('/\D/', '', (string) $this->company->nit), 'provider_dv' => $this->company->verificationDigit()],
            'sender' => $mapper->companyParty(),
            'receiver' => ['id_type' => '31', 'type' => preg_match('/^[89]/', (string) $event->supplier_nit) ? '1' : '2', 'id' => (string) $event->supplier_nit, 'dv' => (string) $event->supplier_dv, 'name' => (string) $event->supplier_name, 'tax_scheme' => ['01', 'IVA']],
            'invoice' => ['number' => (string) $event->document_number, 'cufe' => $event->cufe],
            'person' => in_array($code, ['030', '032'], true) ? $event->receiver : null,
        ]);
        $xml = $this->sign($built['xml'], 'third party');
        $file = UblBuilder::fileName('ar', $this->company->nit, $sent + 1);
        $dir = 'dian/radian/'.$this->company->id;
        Storage::disk('local')->put("$dir/$file.xml", $xml);
        $zip = $this->zip("$file.xml", $xml);
        $r = $this->send('SendEventUpdateStatus', [
            'contentFile' => base64_encode($zip), 'number' => $number, 'code' => $code, 'cufe' => $event->cufe,
            'payment_means' => $event->payment_means, 'previous' => collect($event->events ?? [])->where('valid', true)->pluck('code')->all(),
        ], ['scope' => 'radian', 'document_kind' => $code, 'document_number' => $number]);
        if ($r['http'] === 0 || $r['http'] >= 500) {
            throw new DianUnavailableException("El servicio de la DIAN no está disponible (HTTP {$r['http']}). Intenta de nuevo más tarde.");
        }
        $errors = array_values(array_filter(array_map(fn ($n) => trim($n->textContent), $this->nodes($r['xml'], 'string'))));
        $message = (string) ($errors[0] ?? $this->text($r['xml'], 'StatusMessage') ?? $this->text($r['xml'], 'StatusDescription') ?? 'Sin respuesta de la DIAN');

        return ['valid' => $r['valid'], 'message' => $message, 'cude' => $built['cude'], 'number' => $number, 'path' => "$dir/$file.xml"];
    }

    /** Envía el set de pruebas completo del ámbito y consulta el resultado de cada lote; nunca dos envíos a la vez por empresa. */
    public function runTestSet(string $scope): array
    {
        $lock = $this->testSetLock($scope);
        try {
            return $this->sendTestSet($scope);
        } finally {
            $lock->release();
        }
    }

    /** Igual que runTestSet, pero el envío corre después de responder al navegador; la pantalla sigue el avance con testSetProgress. */
    public function startTestSet(string $scope): void
    {
        $lock = $this->testSetLock($scope);
        $state = $this->settings->habilitationFor($scope);
        $this->saveTestSet($scope, ['status' => 'en_proceso', 'sent' => 0, 'accepted' => 0, 'rejected' => 0, 'approved_at' => null, 'errors' => [],
            'total' => array_sum(config("dian.test_sets.$scope")), 'last_seq' => $state['last_seq'] ?? null, 'updated_at' => now()->toIso8601String(),
            'events' => [['at' => now()->format('H:i:s'), 'ok' => null, 'text' => 'Preparando el set de pruebas…']]]);
        app()->terminating(function () use ($scope, $lock) {
            try {
                $this->sendTestSet($scope);
            } catch (\Throwable $e) {
                report($e);
                $state = $this->settings->fresh()->habilitationFor($scope);
                $state['events'][] = ['at' => now()->format('H:i:s'), 'ok' => false, 'text' => 'El envío se detuvo: '.mb_substr($e->getMessage(), 0, 300)];
                $this->saveTestSet($scope, array_merge($state, ['status' => 'rechazado', 'errors' => [...($state['errors'] ?? []), mb_substr($e->getMessage(), 0, 300)]]));
            } finally {
                $lock->release();
            }
        });
    }

    /** Estado del set; si figura en proceso pero nadie lo está enviando, lo marca como interrumpido. */
    public function testSetProgress(string $scope): array
    {
        $state = $this->settings->fresh()->habilitationFor($scope);
        if (($state['status'] ?? null) === 'en_proceso' && ($lock = $this->testSetLockFor($scope))->get()) {
            $lock->release();
            $state['status'] = 'rechazado';
            $state['errors'] = [...($state['errors'] ?? []), 'El envío se interrumpió antes de terminar. Vuelve a enviar el set de pruebas.'];
            $this->saveTestSet($scope, $state);
        }

        return $state;
    }

    private function testSetLockFor(string $scope): \Illuminate\Contracts\Cache\Lock
    {
        return \Illuminate\Support\Facades\Cache::lock('dian-testset-'.\Illuminate\Support\Facades\DB::connection()->getDatabaseName().'-'.$this->company->id.'-'.$scope, 1800);
    }

    private function testSetLock(string $scope): \Illuminate\Contracts\Cache\Lock
    {
        if ($missing = $this->missing($scope)) {
            throw new RuntimeException('Antes de enviar el set de pruebas completa: '.implode(', ', $missing).'.');
        }
        $lock = $this->testSetLockFor($scope);
        if (! $lock->get()) {
            throw new RuntimeException('El set de pruebas ya se está enviando. Espera a que termine antes de volver a enviarlo.');
        }

        return $lock;
    }

    private function saveTestSet(string $scope, array $state): void
    {
        $this->settings->refresh();
        $this->settings->update(['habilitation' => array_merge($this->settings->habilitation ?? DianSetting::emptyHabilitation(), [$scope => $state])]);
    }

    private function sendTestSet(string $scope): array
    {
        [, , $testSet] = $this->settings->softwareFor($scope);
        @set_time_limit(0);
        ignore_user_abort(true);
        $total = array_sum(config("dian.test_sets.$scope"));
        $state = ['status' => 'en_proceso', 'sent' => 0, 'accepted' => 0, 'rejected' => 0, 'approved_at' => null, 'simulated' => (bool) $this->settings->simulated, 'errors' => [], 'total' => $total, 'events' => []];
        $seq = max(self::TEST_RESOLUTION['from'], (int) ($this->settings->habilitationFor($scope)['last_seq'] ?? 0));
        $event = function (?bool $ok, string $text, bool $replaceLast = false) use (&$state, $scope) {
            $replaceLast && array_pop($state['events']);
            $state['events'] = array_slice([...$state['events'], ['at' => now()->format('H:i:s'), 'ok' => $ok, 'text' => $text]], -40);
            $state['updated_at'] = now()->toIso8601String();
            $this->saveTestSet($scope, $state);
        };
        $event(null, $this->settings->simulated ? 'Modo simulado: no se enviará nada a la DIAN.' : 'Firmando documentos con el certificado de la empresa…');
        $template = $this->settings->simulated ? null : $this->testTemplate($scope);
        $reference = null;

        foreach (config("dian.test_sets.$scope") as $kind => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $number = self::TEST_PREFIX[$scope].(++$seq);
                $state['last_seq'] = $seq;
                $event(null, "Enviando {$kind} {$number} (".($state['sent'] + 1)." de {$total}) y esperando la respuesta de la DIAN…");
                $params = ['fileName' => "z$number.zip", 'testSetId' => (string) $testSet, 'number' => $number, 'kind' => $kind];
                if ($template && $scope === 'nomina') {
                    [$xml, $key] = $this->testPayroll($template, $kind, $seq, $reference);
                    $reference ??= ['number' => self::TEST_PREFIX[$scope].$seq, 'cune' => $key, 'date' => now()->toDateString()];
                    $file = UblBuilder::fileName($kind === 'NE' ? 'nie' : 'nai', $this->company->nit, $seq);
                    $params['fileName'] = 'z'.substr($file, 3).'.zip';
                    $params['contentFile'] = base64_encode($this->zip("$file.xml", $xml));
                    $params['cufe'] = $key;
                } elseif ($template) {
                    [$xml, $key] = $this->testDocument($template, $kind, $scope, $seq, $reference);
                    if (in_array($kind, ['FV', 'DS'], true)) {
                        $reference ??= ['number' => self::TEST_PREFIX[$scope].$seq, 'key' => $key, 'date' => now()->toDateString()];
                    }
                    $file = UblBuilder::fileName(self::FILE_PREFIX[$kind] ?? 'fv', $this->company->nit, $seq);
                    $params['fileName'] = 'z'.substr($file, strlen(self::FILE_PREFIX[$kind] ?? 'fv')).'.zip';
                    $params['contentFile'] = base64_encode($this->zip("$file.xml", $xml));
                    $params['cufe'] = $key;
                } else {
                    $params['cufe'] = hash('sha384', $number.$this->company->nit.now()->toIso8601String());
                }
                $log = ['scope' => $scope, 'document_kind' => $kind, 'document_number' => $number];
                $zip = $this->send('SendTestSetAsync', $params, $log);
                $trackId = $this->text($zip['xml'], 'ZipKey');
                $state['sent']++;
                if (! $trackId) {
                    $state['rejected']++;
                    $message = $this->text($zip['xml'], 'ProcessedMessage') ?? $this->nodes($zip['xml'], 'string')[0]->textContent ?? 'la DIAN no devolvió número de seguimiento';
                    count($state['errors']) < 5 && $state['errors'][] = "$number: ".mb_substr((string) $message, 0, 300);
                    $event(false, "{$kind} {$number}: no se pudo enviar (".mb_substr((string) $message, 0, 200).').', true);

                    continue;
                }
                $status = $this->statusZip($trackId, $params, $log);
                if (str_contains($status['xml'], 'se encuentra Rechazado')) {
                    $state['rejected']++;
                    $state['set_rejected'] = true;
                    $state['errors'][] = "{$number}: El set de pruebas quedó en estado Rechazado en la DIAN. Reinícielo en el portal de habilitación (o registre un nuevo TestSetId) antes de volver a enviarlo.";
                    $event(false, "{$kind} {$number}: la DIAN informa que el set de pruebas está Rechazado; se detiene el envío.", true);
                    break 2;
                }
                if (str_contains($status['xml'], 'se encuentra Aceptado')) {
                    $state['accepted'] = $total;
                    $state['rejected'] = 0;
                    $state['errors'] = [];
                    $state['set_accepted'] = true;
                    $event(true, "{$kind} {$number}: la DIAN informa que el set de pruebas ya está Aceptado.", true);
                    break 2;
                }
                if ($status['valid']) {
                    $state['accepted']++;
                    $event(true, "{$kind} {$number}: aceptado por la DIAN.", true);
                } else {
                    $state['rejected']++;
                    $message = mb_substr((string) ($this->nodes($status['xml'], 'string')[0]->textContent ?? $this->text($status['xml'], 'StatusDescription') ?? 'Rechazado'), 0, 300);
                    if (count($state['errors']) < 5) {
                        $state['errors'][] = "$number: $message";
                    }
                    $event(false, "{$kind} {$number}: rechazado. {$message}", true);
                }
            }
        }

        $state['last_seq'] = $seq;
        if (! empty($state['set_accepted']) || ($state['rejected'] === 0 && $state['accepted'] > 0)) {
            $state['status'] = $state['simulated'] ? 'simulado' : 'aprobado';
            $state['approved_at'] = now()->toIso8601String();
            $event(true, $state['simulated'] ? 'Terminó la simulación.' : '¡Set de pruebas aprobado! Ya puedes activar el ambiente de producción.');
        } else {
            $state['status'] = 'rechazado';
            $event(false, "Terminó el envío: {$state['accepted']} aceptados y {$state['rejected']} rechazados. Revisa los errores y corrige antes de reenviar.");
        }

        return $state;
    }

    public function activateProduction(): void
    {
        if ($this->settings->simulated) {
            throw new RuntimeException('Desactiva el modo simulado antes de pasar a producción.');
        }
        if ($this->settings->habilitationFor('fe')['status'] !== 'aprobado') {
            throw new RuntimeException('Primero debes aprobar ante la DIAN (sin modo simulado) el set de pruebas de facturación electrónica.');
        }
        $this->settings->update(['environment' => 'produccion', 'production_since' => now()]);
    }

    /** Firma con el certificado cargado. En modo simulado sin certificado el XML queda sin firma. */
    public function sign(string $xml, string $role = 'supplier'): string
    {
        if (! $this->settings->hasCertificate()) {
            if ($this->settings->simulated) {
                return $xml;
            }
            throw new RuntimeException('No hay certificado digital cargado.');
        }

        return (new XadesSigner(app(CertificateService::class)->load($this->settings)))->sign($xml, $role);
    }

    /** Documento base para el set de pruebas: el ultimo documento real con items del ambito. */
    private function testTemplate(string $scope): array
    {
        if ($scope === 'nomina') {
            $doc = PayrollDocument::query()->where('type', 'Soporte de pago')->whereHas('items')->latest('id')->first()
                ?? throw new RuntimeException('Genera al menos un documento de nómina electrónica (Nómina > Documentos electrónicos > Generar documentos) para el set de pruebas.');

            return app(\App\Services\Payroll\ElectronicPayroll::class)->map($doc, $this->settings);
        }
        $mapper = new DocumentMapper($this->settings, $this->company);
        if ($scope === 'ds') {
            $doc = PurchaseDocument::query()->where('type', 'DS')->whereHas('items')->latest('id')->first()
                ?? throw new RuntimeException('Registra al menos un documento soporte para generar el set de pruebas.');

            return $mapper->purchase($doc);
        }
        $doc = SalesDocument::query()->where('type', 'FV')->where('status', 'issued')->whereHas('items')->latest('id')->first()
            ?? throw new RuntimeException('Registra al menos una factura de venta para generar el set de pruebas.');

        return $mapper->sales($doc);
    }

    /** @return array{0:string, 1:string} XML firmado y clave del documento de prueba */
    private function testDocument(array $template, string $kind, string $scope, int $seq, ?array $reference): array
    {
        $d = $template;
        $d['kind'] = $kind;
        $d['number'] = self::TEST_PREFIX[$scope].$seq;
        $d['issue_date'] = now()->toDateString();
        $d['issue_time'] = now()->setTimezone('America/Bogota')->format('H:i:s').'-05:00';
        $d['due_date'] = now()->toDateString();
        $d['environment'] = '2';
        $d['technical_key'] = DocumentMapper::TEST_TECHNICAL_KEY;
        $d['resolution'] = self::TEST_RESOLUTION + ['prefix' => self::TEST_PREFIX[$scope]];
        if ($scope === 'fe') {
            $d['supplier']['registration_prefix'] = self::TEST_PREFIX[$scope];
        }
        $d['reference'] = in_array($kind, ['NC', 'ND', 'NA'], true) ? $reference : null;
        $built = (new UblBuilder)->build($d);

        return [$this->sign($built['xml']), $built['key']];
    }

    /** @return array{0:string, 1:string} XML firmado y CUNE de un documento del set de pruebas de nomina */
    private function testPayroll(array $template, string $kind, int $seq, ?array $reference): array
    {
        $d = $template;
        $d['prefix'] = self::TEST_PREFIX['nomina'];
        $d['consecutive'] = (string) $seq;
        $d['number'] = self::TEST_PREFIX['nomina'].$seq;
        $d['environment'] = '2';
        $d['issue_date'] = now()->toDateString();
        $d['issue_time'] = now()->setTimezone('America/Bogota')->format('H:i:s').'-05:00';
        $d['note_type'] = match ($kind) { 'NE' => null, 'NAE' => 2, default => 1 };
        $d['predecessor'] = $kind === 'NE' ? null : $reference;
        $built = (new NominaBuilder)->build($d);

        return [$this->sign($built['xml']), $built['cune']];
    }

    /** La DIAN procesa el set en segundo plano: consulta el ZIP hasta que deje de estar "en proceso" (codigo 98). */
    private function statusZip(string $trackId, array $params, array $log): array
    {
        for ($i = 0; $i < 10; $i++) {
            $status = $this->send('GetStatusZip', ['trackId' => $trackId] + $params, $log + ['track_id' => $trackId]);
            if ($status['valid'] || $this->settings->simulated || ! in_array($this->text($status['xml'], 'StatusCode'), ['0', '98', null], true)) {
                return $status;
            }
            sleep(3);
        }

        return $status;
    }

    private function zip(string $name, string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dian');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString($name, $contents);
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }

    /** @return array{http:int, xml:string, valid:bool} */
    private function send(string $operation, array $params, array $log, array $ranges = []): array
    {
        $transport = $this->transport($ranges);
        $response = $transport->call($operation, $params);
        $xml = $response->body;
        $valid = $this->text($xml, 'IsValid');
        $errors = $this->errors($xml);

        DianTransmission::create($log + [
            'user_id' => Auth::id(),
            'operation' => $operation,
            'environment' => $this->settings->environment,
            'simulated' => $this->settings->simulated,
            'cufe' => $this->text($xml, 'XmlDocumentKey'),
            'track_id' => $log['track_id'] ?? $this->text($xml, 'ZipKey'),
            'is_valid' => $valid === null ? ($errors === [] && $response->status === 200) : $valid === 'true',
            'status_code' => $this->text($xml, 'StatusCode') ?? $this->text($xml, 'OperationCode'),
            'status_message' => mb_substr((string) ($this->text($xml, 'StatusMessage') ?? $this->text($xml, 'OperationDescription') ?? ($errors[0] ?? 'Recibido')), 0, 500),
            'errors' => $errors ?: null,
            'response_body' => $xml,
            'duration_ms' => $response->durationMs,
        ]);

        return ['http' => $response->status, 'xml' => $xml, 'valid' => $valid === 'true'];
    }

    private function transport(array $ranges): DianTransport
    {
        if ($this->settings->simulated) {
            return new SimulatedDianTransport($ranges);
        }
        $endpoint = config('dian.endpoints.'.($this->settings->environment === 'produccion' ? 'produccion' : 'habilitacion'));

        return new SoapDianTransport($endpoint, app(CertificateService::class)->load($this->settings));
    }

    private function xpath(string $xml): ?DOMXPath
    {
        $doc = new DOMDocument;

        return @$doc->loadXML($xml) ? new DOMXPath($doc) : null;
    }

    private function text(string $xml, string $tag): ?string
    {
        $node = $this->xpath($xml)?->query("//*[local-name()='$tag']")->item(0);

        return $node && $node->textContent !== '' ? trim($node->textContent) : null;
    }

    /** @return list<string> */
    private function errors(string $xml): array
    {
        $nodes = [...$this->nodes($xml, 'ProcessedMessage'), ...($this->xpath($xml)?->query("//*[local-name()='ErrorMessage']/*") ?? [])];

        return array_values(array_unique(array_filter(array_map(fn ($n) => trim($n->textContent), $nodes))));
    }

    /** @return list<\DOMElement> */
    private function nodes(string $xml, string $tag): array
    {
        $list = $this->xpath($xml)?->query("//*[local-name()='$tag']");

        return $list ? iterator_to_array($list) : [];
    }
}
