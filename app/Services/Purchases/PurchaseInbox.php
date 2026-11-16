<?php

namespace App\Services\Purchases;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactPerson;
use App\Models\DocumentType;
use App\Models\DianInboxDocument;
use App\Models\DianInboxSync;
use App\Models\PurchaseDocument;
use App\Models\Tax;
use App\Services\Mail\CompanyMailer;
use App\Services\Mail\ImapMailbox;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Bandeja DIAN (Causacion automatica): recibe las facturas electronicas de los proveedores desde el buzon de recepcion
 * (IMAP) o desde un XML/ZIP cargado, y las deja listas para causarlas como factura de compra.
 */
class PurchaseInbox
{
    private const MAX_MESSAGES = 50;

    private const FIRST_SYNC_DAYS = 90;

    public function __construct(private readonly SupplierInvoiceParser $parser, private readonly InvoiceProductMatcher $matcher) {}

    public function sync(): DianInboxSync
    {
        $s = CompanyMailer::settings(CurrentCompany::id());
        if (blank($s['imap_host'] ?? null) || blank($s['imap_username'] ?? null) || blank($s['imap_password'] ?? null)) {
            return $this->log('Fallida', 0, 'Configura el buzón de recepción de facturas en Configuración > Desbloqueo de correos > Buzón de recepción de facturas de proveedores (IMAP).');
        }
        $box = new ImapMailbox($s['imap_host'], (int) ($s['imap_port'] ?? 993), (string) ($s['imap_encryption'] ?? 'ssl'));
        $received = 0;
        $notes = [];
        try {
            $box->connect($s['imap_username'], $s['imap_password']);
            $validity = $box->select(filled($s['imap_folder'] ?? null) ? $s['imap_folder'] : 'INBOX');
            $state = json_decode((string) DB::table('app_settings')->where('company_id', CurrentCompany::id())->where('key', 'imap_state')->value('value'), true) ?: [];
            $last = ($state['validity'] ?? null) === $validity ? (int) ($state['last_uid'] ?? 0) : 0;
            $criteria = $last ? 'UID '.($last + 1).':*' : 'SINCE '.now()->subDays(self::FIRST_SYNC_DAYS)->format('d-M-Y');
            if (str_contains(strtolower($s['imap_host']), 'gmail')) {
                $criteria .= ' X-GM-RAW "has:attachment (filename:zip OR filename:xml)"';
            }
            $uids = array_values(array_filter($box->search($criteria), fn ($uid) => $uid > $last));
            sort($uids);
            foreach (array_slice($uids, 0, self::MAX_MESSAGES) as $uid) {
                $raw = $box->fetch($uid);
                foreach (ImapMailbox::attachments($raw) as $file) {
                    $xmls = $file['name'] && str_ends_with(strtolower($file['name']), '.zip') ? $this->parser->xmlsFromZip($file['content']) : [$file['content']];
                    foreach ($xmls as $xml) {
                        try {
                            $received += $this->ingest($xml, 'correo', ImapMailbox::sender($raw)) ? 1 : 0;
                        } catch (RuntimeException $e) {
                            $notes[] = $file['name'].': '.$e->getMessage();
                        }
                    }
                }
                $last = $uid;
            }
            DB::table('app_settings')->updateOrInsert(['company_id' => CurrentCompany::id(), 'key' => 'imap_state'],
                ['value' => json_encode(['validity' => $validity, 'last_uid' => $last]), 'updated_at' => now()]);
        } catch (Throwable $e) {
            $box->logout();

            return $this->log('Fallida', $received, mb_substr($e->getMessage(), 0, 900));
        }
        $box->logout();

        return $this->log('Exitosa', $received, $notes ? mb_substr(implode(' | ', array_unique($notes)), 0, 900) : null);
    }

    /** Registra una factura recibida; devuelve null si ya estaba o si es un ApplicationResponse u otro XML. */
    public function ingest(string $xml, string $source, ?string $from = null): ?DianInboxDocument
    {
        $root = @simplexml_load_string(trim($xml), 'SimpleXMLElement', LIBXML_NONET);
        if ($root === false || ! in_array($root->getName(), ['AttachedDocument', 'Invoice', 'CreditNote', 'DebitNote'], true)) {
            return null;
        }
        $data = $this->parser->parse($xml);
        if (! in_array($data['document_class'], ['FC', 'DS', 'NC', 'ND', 'NA'], true)) {
            return null;
        }
        if (blank($data['cufe'])) {
            throw new RuntimeException('La factura no tiene CUFE');
        }
        $companyNit = preg_replace('/\D/', '', (string) Company::query()->find(CurrentCompany::id())?->nit);
        if ($companyNit === '') {
            throw new RuntimeException('Configura el NIT de la empresa antes de importar documentos DIAN.');
        }
        if (preg_replace('/\D/', '', $data['customer']['nit']) !== $companyNit) {
            return null;
        }
        if (blank($data['supplier']['nit'])) {
            throw new RuntimeException("El documento {$data['number']} no incluye el NIT del proveedor.");
        }
        if (in_array($data['document_class'], ['NC', 'ND', 'NA'], true) && blank($data['related_cufe'])) {
            throw new RuntimeException("La nota {$data['number']} no incluye el CUFE/CUDE del documento que modifica.");
        }
        if ($existing = DianInboxDocument::query()->where('cufe', $data['cufe'])->first()) {
            $legacyNote = in_array($data['document_class'], ['NC', 'ND', 'NA'], true) && blank($existing->related_cufe);
            if ($existing->document_class !== $data['document_class'] || blank($existing->related_cufe) && filled($data['related_cufe'])) {
                $existing->update([
                    'kind' => $data['kind'], 'document_class' => $data['document_class'],
                    'related_cufe' => $data['related_cufe'] ?: null,
                    ...($legacyNote ? ['status' => 'Pendiente', 'purchase_document_id' => null] : []),
                ]);
            }

            return $source === 'archivo' ? $existing : null;
        }
        $path = 'dian/recibidos/'.CurrentCompany::id().'/'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);

        $purchase = in_array($data['document_class'], ['FC', 'DS'], true)
            ? $this->existingPurchase((string) $data['supplier']['nit'], (string) $data['number'])
            : null;

        return DianInboxDocument::create([
            'cufe' => $data['cufe'], 'kind' => $data['kind'], 'document_class' => $data['document_class'], 'related_cufe' => $data['related_cufe'] ?: null,
            'number' => $data['number'], 'issue_date' => $data['date'] ?: null,
            'supplier_nit' => $data['supplier']['nit'], 'supplier_name' => $data['supplier']['name'], 'total' => $data['totals']['total'],
            'status' => $purchase ? 'Causada' : 'Pendiente', 'purchase_document_id' => $purchase, 'xml_path' => $path, 'source' => $source, 'received_from' => $from,
        ]);
    }

    /** Factura de compra ya registrada (p. ej. importada de Siigo) con el mismo proveedor y numero. */
    public function existingPurchase(string $supplierNit, string $number): ?int
    {
        $contacts = Contact::query()->where('identification', preg_replace('/\D/', '', $supplierNit))->pluck('id');
        if ($contacts->isEmpty() || $number === '') {
            return null;
        }
        preg_match('/^(.*?)(\d+)$/', $number, $m);

        return PurchaseDocument::query()->whereIn('contact_id', $contacts)->whereIn('type', ['FC', 'DS'])
            ->where('status', '!=', 'annulled')
            ->where(fn ($q) => $q->whereRaw("CONCAT(COALESCE(supplier_prefix, ''), supplier_number) = ?", [$number])
                ->when($m, fn ($w) => $w->orWhere(fn ($x) => $x->where('supplier_number', ltrim($m[2], '0') ?: '0')->whereIn('supplier_prefix', [$m[1], ''])->orWhereNull('supplier_prefix')->where('supplier_number', $m[2]))))
            ->value('id');
    }

    public function parse(DianInboxDocument $doc): array
    {
        return $this->parser->parse((string) Storage::disk('local')->get($doc->xml_path));
    }

    /** Datos iniciales del formulario de factura de compra; crea el proveedor si aun no existe. */
    public function initial(DianInboxDocument $doc): array
    {
        $data = $this->parse($doc);
        $s = $data['supplier'];
        $contact = Contact::query()->where('identification', $s['nit'])->first();
        if (! $contact) {
            $contact = Contact::create([
                'is_supplier' => true, 'person_type' => $s['person_type'], 'identification_type' => $s['id_type'] ?: '31',
                'identification' => $s['nit'], 'dv' => $s['dv'] !== '' ? substr($s['dv'], 0, 1) : null,
                'first_name' => $s['person_type'] === 'natural' ? $s['name'] : null,
                'business_name' => $s['person_type'] === 'juridica' ? $s['name'] : null,
                'email' => $s['email'] ?: null, 'phone' => $s['phone'] ?: null, 'address' => $s['address'] ?: null,
                'municipality_code' => strlen($s['municipality_code']) === 5 ? $s['municipality_code'] : null,
                'department_code' => strlen($s['municipality_code']) === 5 ? substr($s['municipality_code'], 0, 2) : null,
                'vat_responsible' => $s['vat'],
            ]);
        } elseif (! $contact->is_supplier) {
            $contact->update(['is_supplier' => true]);
        }

        $vat = Tax::query()->where('active', true)->where('type', 'IVA')->get(['id', 'percentage']);
        $matches = $this->matcher->suggestions($doc, $data);
        $items = collect($data['lines'])->map(function ($l, $i) use ($vat, $matches) {
            [$price, $discount] = $this->linePrice($l);

            return [
                'type' => 'Producto', 'item_id' => $matches[$i]['product_id'] ?? null, 'label' => $matches[$i]['label'] ?? '',
                'description' => $l['description'], 'quantity' => $l['quantity'], 'price' => $price, 'discount' => $discount,
                'tax_id' => $l['tax_percent'] > 0 ? $vat->first(fn ($t) => abs((float) $t->percentage - $l['tax_percent']) < 0.01)?->id : null,
                'withholding_id' => null,
            ];
        })->all();
        preg_match('/^([A-Za-z]*)(.*)$/', $data['number'], $m);
        $isNote = in_array($doc->document_class, ['NC', 'ND', 'NA'], true);
        $credit = $isNote || $data['payment_means'] === '2';
        $relatedPurchase = null;
        if ($isNote) {
            if (! $this->canCause($doc)) {
                throw new RuntimeException('Primero debes causar la factura o documento soporte que modifica esta nota.');
            }
            $relatedInbox = DianInboxDocument::query()->where('cufe', $doc->related_cufe)->firstOrFail();
            $relatedPurchase = PurchaseDocument::query()->findOrFail($relatedInbox->purchase_document_id);
        }
        $purchaseType = $doc->document_class;

        return [
            'inbox_id' => $doc->id, 'date' => $data['date'] ?: now()->toDateString(),
            'document_type' => $purchaseType === 'NC' ? 'NC-C1' : $this->documentType($contact->id, $purchaseType),
            'contact_id' => $contact->id, 'contact_label' => "{$contact->identification} - {$contact->name}",
            'related_id' => $relatedPurchase?->id,
            'related_label' => $relatedPurchase ? "{$relatedPurchase->name} - {$contact->name}" : null,
            'person_id' => ContactPerson::query()->where('contact_id', $contact->id)->orderBy('id')->value('id'),
            'supplier_prefix' => $m[1] ?? '', 'supplier_number' => $m[2] ?? $data['number'],
            'items' => $items, 'credit' => $credit, 'due_date' => $credit ? ($data['due_date'] ?: $data['date']) : null, 'payments' => [],
            'observations' => "{$data['kind']} {$data['number']} - CUFE/CUDE {$data['cufe']}",
        ];
    }

    /** Tipo de factura de compra (Compra / Gasto) usado la ultima vez con el proveedor; si es nuevo, el primero. */
    private function documentType(int $contactId, string $kind): ?int
    {
        $active = DocumentType::query()->where('type', $kind)->where('active', true)->orderBy('code')->pluck('id');
        $last = PurchaseDocument::query()->where('contact_id', $contactId)->where('type', $kind)->whereIn('document_type_id', $active)
            ->latest('id')->value('document_type_id');

        return $last ?? $active->first();
    }

    public function canCause(DianInboxDocument $doc): bool
    {
        if ($doc->status !== 'Pendiente') {
            return false;
        }
        if (in_array($doc->document_class, ['FC', 'DS'], true)) {
            return true;
        }
        if (! in_array($doc->document_class, ['NC', 'ND', 'NA'], true) || blank($doc->related_cufe)) {
            return false;
        }
        $base = DianInboxDocument::query()->where('cufe', $doc->related_cufe)->first();
        if (! $base || $base->status !== 'Causada' || ! in_array($base->document_class, ['FC', 'DS'], true)) {
            return false;
        }

        return PurchaseDocument::query()->whereKey($base->purchase_document_id)
            ->whereIn('type', ['FC', 'DS'])->where('status', '!=', 'annulled')->exists();
    }

    public static function formPath(DianInboxDocument $doc): ?string
    {
        return match ($doc->document_class) {
            'FC' => '/compras/facturas/crear',
            'DS' => '/compras/documentos-soporte/crear',
            'NC' => '/compras/notas-credito/crear',
            'ND' => '/compras/notas-debito/crear',
            'NA' => '/compras/notas-ajuste/crear',
            default => null,
        };
    }

    /** Valor unitario y descuento (en valor) tal como vienen en la factura, para que el subtotal cuadre con el XML. */
    private function linePrice(array $l): array
    {
        $qty = (float) $l['quantity'];
        if ($qty <= 0) {
            return [$l['price'], 0];
        }
        $gross = round($qty * $l['price'], 2);
        if ($l['price'] <= 0 || $l['subtotal'] > $gross + 0.01) {
            return [round($l['subtotal'] / $qty, 6), 0];
        }

        return [$l['price'], $gross - $l['subtotal'] > 0.01 ? round($gross - $l['subtotal'], 2) : 0];
    }

    private function log(string $status, int $received, ?string $observations): DianInboxSync
    {
        return DianInboxSync::create(['user_id' => Auth::id(), 'status' => $status, 'received' => $received, 'observations' => $observations]);
    }
}
