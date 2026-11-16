<?php

namespace App\Services\Purchases;

use App\Models\Contact;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/** Lee la factura electronica UBL 2.1 de un proveedor (XML suelto, AttachedDocument o ZIP con ambos). */
class SupplierInvoiceParser
{
    private const NS = [
        'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    ];

    /** @return list<string> contenido de cada XML del ZIP */
    public function xmlsFromZip(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($tmp, $binary);
        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException('No fue posible abrir el ZIP');
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.xml')) {
                $out[] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($tmp);

        return $out;
    }

    public function parse(string $content): array
    {
        $doc = $this->load($content);
        if ($doc->getName() === 'AttachedDocument') {
            $inner = (string) ($this->x($doc, '//cac:Attachment/cac:ExternalReference/cbc:Description')[0] ?? '');
            if ($inner === '') {
                throw new RuntimeException('El AttachedDocument no trae la factura embebida');
            }
            $doc = $this->load($inner);
        }
        if (! in_array($doc->getName(), ['Invoice', 'CreditNote', 'DebitNote'], true)) {
            throw new RuntimeException('El XML no es una factura electrónica UBL 2.1');
        }

        $v = fn (string $path) => trim((string) ($this->x($doc, $path)[0] ?? ''));
        $sp = '/*/cac:AccountingSupplierParty/cac:Party';
        $company = $this->x($doc, "$sp/cac:PartyTaxScheme/cbc:CompanyID")[0] ?? null;
        $nit = trim((string) $company);
        $lineTag = ['Invoice' => 'InvoiceLine', 'CreditNote' => 'CreditNoteLine', 'DebitNote' => 'DebitNoteLine'][$doc->getName()];
        $qtyTag = ['Invoice' => 'InvoicedQuantity', 'CreditNote' => 'CreditedQuantity', 'DebitNote' => 'DebitedQuantity'][$doc->getName()];
        $typeCode = match ($doc->getName()) {
            'Invoice' => $v('/*/cbc:InvoiceTypeCode'),
            'CreditNote' => $v('/*/cbc:CreditNoteTypeCode'),
            'DebitNote' => $v('/*/cbc:DebitNoteTypeCode'),
        };
        $documentClass = self::documentClass($doc->getName(), $typeCode);

        $lines = [];
        foreach ($this->x($doc, "/*/cac:$lineTag") as $line) {
            $line->registerXPathNamespace('cbc', self::NS['cbc']);
            $line->registerXPathNamespace('cac', self::NS['cac']);
            $lv = fn (string $p) => trim((string) ($line->xpath($p)[0] ?? ''));
            $lines[] = [
                'code' => $lv('cac:Item/cac:StandardItemIdentification/cbc:ID') ?: $lv('cac:Item/cac:SellersItemIdentification/cbc:ID'),
                'description' => $lv('cac:Item/cbc:Description'),
                'quantity' => (float) $lv("cbc:$qtyTag"),
                'price' => (float) $lv('cac:Price/cbc:PriceAmount'),
                'subtotal' => (float) $lv('cbc:LineExtensionAmount'),
                'tax_percent' => (float) $lv('cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent'),
                'tax' => (float) $lv('cac:TaxTotal/cbc:TaxAmount'),
            ];
        }

        $contact = $nit !== '' ? Contact::query()->where('identification', $nit)->first(['id', 'name', 'identification']) : null;

        return [
            'kind' => [
                'FC' => 'Factura electrónica', 'DS' => 'Documento soporte', 'NC' => 'Nota crédito',
                'NA' => 'Nota ajuste documento soporte', 'ND' => 'Nota débito',
            ][$documentClass] ?? ['Invoice' => 'Factura electrónica', 'CreditNote' => 'Nota crédito', 'DebitNote' => 'Nota débito'][$doc->getName()],
            'document_class' => $documentClass,
            'type_code' => $typeCode,
            'related_cufe' => $v('/*/cac:BillingReference/*/cbc:UUID'),
            'number' => $v('/*/cbc:ID'),
            'cufe' => $v('/*/cbc:UUID'),
            'date' => $v('/*/cbc:IssueDate'),
            'due_date' => $v('/*/cbc:DueDate') ?: $v('/*/cac:PaymentMeans/cbc:PaymentDueDate'),
            'payment_means' => $v('/*/cac:PaymentMeans/cbc:ID') ?: '1',
            'currency' => $v('/*/cbc:DocumentCurrencyCode') ?: 'COP',
            'supplier' => [
                'nit' => $nit,
                'dv' => $company ? (string) ($company['schemeID'] ?? '') : '',
                'id_type' => $company ? (string) ($company['schemeName'] ?? '31') : '31',
                'person_type' => $v("$sp/../cbc:AdditionalAccountID") === '2' ? 'natural' : 'juridica',
                'name' => $v("$sp/cac:PartyTaxScheme/cbc:RegistrationName") ?: $v("$sp/cac:PartyName/cbc:Name"),
                'email' => $v("$sp/cac:Contact/cbc:ElectronicMail"),
                'phone' => $v("$sp/cac:Contact/cbc:Telephone"),
                'address' => $v("$sp/cac:PhysicalLocation/cac:Address/cac:AddressLine/cbc:Line"),
                'municipality_code' => $v("$sp/cac:PhysicalLocation/cac:Address/cbc:ID"),
                'vat' => $v("$sp/cac:PartyTaxScheme/cac:TaxScheme/cbc:ID") === '01',
                'contact_id' => $contact?->id,
                'exists' => (bool) $contact,
            ],
            'customer' => [
                'nit' => $v('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
                'name' => $v('/*/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:RegistrationName'),
            ],
            'lines' => $lines,
            'totals' => [
                'subtotal' => (float) $v('/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount'),
                'tax' => (float) $v('/*/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount') - (float) $v('/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
                'discount' => (float) $v('/*/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount'),
                'total' => (float) $v('/*/cac:LegalMonetaryTotal/cbc:PayableAmount'),
            ],
        ];
    }

    public static function documentClass(string $root, string $typeCode): ?string
    {
        return match ([$root, $typeCode]) {
            ['Invoice', '01'] => 'FC',
            ['Invoice', '05'] => 'DS',
            ['CreditNote', '91'] => 'NC',
            ['CreditNote', '95'] => 'NA',
            ['DebitNote', '92'] => 'ND',
            default => null,
        };
    }

    private function load(string $content): SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string(trim($content), SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new RuntimeException('El archivo no es un XML válido');
        }
        foreach (self::NS as $p => $ns) {
            $doc->registerXPathNamespace($p, $ns);
        }

        return $doc;
    }

    private function x(SimpleXMLElement $doc, string $path): array
    {
        return $doc->xpath($path) ?: [];
    }
}
