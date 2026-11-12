<?php

namespace Tests\Unit;

use App\Services\Dian\AttachedDocumentBuilder;
use App\Services\Dian\DocumentMapper;
use App\Services\Dian\NominaBuilder;
use App\Services\Dian\RadianEventBuilder;
use App\Services\Dian\SimulatedDianTransport;
use App\Services\Dian\SoapDianTransport;
use App\Services\Dian\UblBuilder;
use App\Services\Dian\XadesSigner;
use App\Services\Purchases\SupplierInvoiceParser;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

/** XML DIAN sin base de datos: CUFE/CUDE, estructura UBL, firma XAdES, sobre SOAP, AttachedDocument y transporte simulado. */
class DianXmlTest extends TestCase
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    public function test_cufe_concatenates_fields_in_annex_order(): void
    {
        $d = $this->invoice();
        $expected = hash('sha384', 'SETP990000001'.'2026-10-14'.'10:53:10-05:00'.'200000.00'.'01'.'38000.00'.'04'.'0.00'.'03'.'0.00'.'238000.00'
            .'900123456'.'800199436'.'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c'.'2');

        $this->assertSame($expected, (new UblBuilder)->documentKey($d));
    }

    public function test_cude_uses_software_pin_instead_of_technical_key(): void
    {
        $d = ['kind' => 'NC'] + $this->invoice();
        $expected = hash('sha384', 'SETP990000001'.'2026-10-14'.'10:53:10-05:00'.'200000.00'.'01'.'38000.00'.'04'.'0.00'.'03'.'0.00'.'238000.00'
            .'900123456'.'800199436'.'12345'.'2');

        $this->assertSame($expected, (new UblBuilder)->documentKey($d));
    }

    public function test_supplier_document_types_are_classified_for_purchase_inbox(): void
    {
        $this->assertSame('FC', SupplierInvoiceParser::documentClass('Invoice', '01'));
        $this->assertSame('DS', SupplierInvoiceParser::documentClass('Invoice', '05'));
        $this->assertSame('NC', SupplierInvoiceParser::documentClass('CreditNote', '91'));
        $this->assertSame('NA', SupplierInvoiceParser::documentClass('CreditNote', '95'));
        $this->assertSame('ND', SupplierInvoiceParser::documentClass('DebitNote', '92'));
        $this->assertNull(SupplierInvoiceParser::documentClass('Invoice', '02'));
    }

    public function test_invoice_ubl_has_dian_structure(): void
    {
        $built = (new UblBuilder)->build($this->invoice());
        $xp = $this->xpath($built['xml']);

        $this->assertSame('Invoice', $built['root']);
        $this->assertSame('01', $xp->evaluate('string(//cbc:InvoiceTypeCode)'));
        $this->assertSame('10', $xp->evaluate('string(//cbc:CustomizationID)'));
        $this->assertSame($built['key'], $xp->evaluate('string(//cbc:UUID)'));
        $this->assertSame('KGM', $xp->evaluate('string(//cac:InvoiceLine/cbc:InvoicedQuantity/@unitCode)'));
        $this->assertSame('06', $xp->evaluate('string(//cac:WithholdingTaxTotal/cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:ID)'));
        $this->assertSame('238000.00', $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        $this->assertStringContainsString('CUFE='.$built['key'], $built['qr']);
    }

    public function test_debit_note_uses_requested_monetary_total_and_reference(): void
    {
        $d = ['kind' => 'ND', 'discrepancy' => ['code' => '3', 'description' => 'Cambio del valor'], 'reference' => ['number' => 'SETP990000001', 'key' => str_repeat('a', 96), 'date' => '2026-10-14']] + $this->invoice();
        $xp = $this->xpath((new UblBuilder)->build($d)['xml']);

        $this->assertSame(1.0, $xp->evaluate('count(//cac:RequestedMonetaryTotal)'));
        $this->assertSame('3', $xp->evaluate('string(//cac:DiscrepancyResponse/cbc:ResponseCode)'));
        $this->assertSame(str_repeat('a', 96), $xp->evaluate('string(//cac:BillingReference//cbc:UUID)'));
        $this->assertSame(1.0, $xp->evaluate('count(//cac:DebitNoteLine/cbc:DebitedQuantity)'));
    }

    public function test_xades_signature_verifies_with_certificate(): void
    {
        $bundle = $this->certificate();
        $signed = (new XadesSigner($bundle))->sign((new UblBuilder)->build($this->invoice())['xml']);
        $this->assertSignatureValid($signed, $bundle);
    }

    public function test_attached_document_embeds_invoice_and_application_response_and_is_signed(): void
    {
        $bundle = $this->certificate();
        $d = $this->invoice();
        $built = (new UblBuilder)->build($d);
        $invoice = (new XadesSigner($bundle))->sign($built['xml']);
        $ar = '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"><x>]]></x></ApplicationResponse>';
        $ad = (new AttachedDocumentBuilder)->build($d, $invoice, $ar, ['key' => $built['key'], 'code' => '02', 'date' => '2026-10-14', 'time' => '10:55:00-05:00']);
        $signed = (new XadesSigner($bundle))->sign($ad);

        $xp = $this->xpath($signed);
        $this->assertSame('AttachedDocument', $xp->document->documentElement->localName);
        $this->assertSame('Contenedor de Factura Electrónica', $xp->evaluate('string(/*/cbc:DocumentType)'));
        $inner = new DOMDocument;
        $this->assertTrue($inner->loadXML($xp->evaluate('string(/*/cac:Attachment//cbc:Description)')));
        $this->assertSame('Invoice', $inner->documentElement->localName);
        $this->assertStringContainsString(']]>', $xp->evaluate('string(//cac:ParentDocumentLineReference//cbc:Description)'));
        $this->assertSame('02', $xp->evaluate('string(//cbc:ValidationResultCode)'));
        $this->assertSignatureValid($signed, $bundle);
    }

    public function test_soap_envelope_is_signed_with_ws_security(): void
    {
        $bundle = $this->certificate();
        $xml = (new SoapDianTransport('https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc', $bundle))
            ->envelope('SendBillSync', ['fileName' => 'z1.zip', 'contentFile' => 'UEsDBA==']);
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS);
        $xp->registerNamespace('wsa', 'http://www.w3.org/2005/08/addressing');

        $this->assertSame('z1.zip', $xp->evaluate("string(//*[local-name()='fileName'])"));
        $to = $xp->query('//wsa:To')->item(0);
        $this->assertSame(base64_encode(hash('sha256', $to->C14N(true, false, null, ['soap', 'wcf']), true)), $xp->evaluate('string(//ds:DigestValue)'));
        $ok = openssl_verify($xp->query('//ds:SignedInfo')->item(0)->C14N(true, false, null, ['wsa', 'soap', 'wcf']),
            base64_decode($xp->evaluate('string(//ds:SignatureValue)')), openssl_pkey_get_public($bundle['cert']), OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok);
    }

    public function test_simulated_transport_returns_application_response(): void
    {
        $r = (new SimulatedDianTransport)->call('SendBillSync', ['number' => 'SETP990000001', 'kind' => 'FV', 'cufe' => 'abc']);
        $xp = new DOMXPath($this->load($r->body));

        $this->assertSame('true', $xp->evaluate("string(//*[local-name()='IsValid'])"));
        $ar = base64_decode($xp->evaluate("string(//*[local-name()='XmlBase64Bytes'])"));
        $this->assertStringContainsString('<cbc:ResponseCode>02</cbc:ResponseCode>', $ar);
        $this->assertStringContainsString('abc', $ar);
    }

    public function test_file_name_follows_dian_convention(): void
    {
        $this->assertSame('fv09001234560002600000001', UblBuilder::fileName('fv', '900123456', 1, 2026));
        $this->assertSame('ad0900123456000260000000a', 'ad'.substr(UblBuilder::fileName('fv', '900123456', 10, 2026), 2));
    }

    public function test_payroll_cune_and_structure(): void
    {
        $d = $this->payroll();
        $built = (new NominaBuilder)->build($d);
        $expected = hash('sha384', 'DNE248'.'2026-10-14'.'10:00:00-05:00'.'2000000.00'.'160000.00'.'1840000.00'.'900123456'.'12241689'.'102'.'12345'.'2');
        $this->assertSame($expected, $built['cune']);

        $xp = new DOMXPath($this->load($built['xml']));
        $xp->registerNamespace('n', 'dian:gov:co:facturaelectronica:NominaIndividual');
        $this->assertSame('NominaIndividual', $built['root']);
        $this->assertSame('30', $xp->evaluate('string(//n:Devengados/n:Basico/@DiasTrabajados)'));
        $this->assertSame('4.00', $xp->evaluate('string(//n:Deducciones/n:Salud/@Porcentaje)'));
        $this->assertSame('25.00', $xp->evaluate('string(//n:HEDs/n:HED/@Porcentaje)'));
        $this->assertSame('1840000.00', $xp->evaluate('string(//n:ComprobanteTotal)'));
        $this->assertSame(hash('sha384', 'soft-n'.'12345'.'DNE248'), $xp->evaluate('string(//n:ProveedorXML/@SoftwareSC)'));
        $order = array_map(fn ($n) => $n->localName, iterator_to_array($xp->query('/n:NominaIndividual/*')));
        $this->assertSame(['UBLExtensions', 'Novedad', 'Periodo', 'NumeroSecuenciaXML', 'LugarGeneracionXML', 'ProveedorXML', 'CodigoQR', 'InformacionGeneral',
            'Empleador', 'Trabajador', 'Pago', 'FechasPagos', 'Devengados', 'Deducciones', 'DevengadosTotal', 'DeduccionesTotal', 'ComprobanteTotal'], $order);
        $this->assertSignatureValid((new XadesSigner($this->certificate()))->sign($built['xml']), $this->lastBundle);
    }

    public function test_payroll_adjustment_notes(): void
    {
        $d = ['note_type' => 1, 'predecessor' => ['number' => 'DNE248', 'cune' => str_repeat('b', 96), 'date' => '2026-10-14'], 'number' => 'DNE249', 'consecutive' => '249'] + $this->payroll();
        $xml = (new NominaBuilder)->build($d)['xml'];
        $this->assertStringContainsString('<TipoNota>1</TipoNota><Reemplazar><ReemplazandoPredecesor NumeroPred="DNE248"', $xml);
        $this->assertStringContainsString('TipoXML="103"', $xml);

        $del = (new NominaBuilder)->build(['note_type' => 2] + $d);
        $this->assertStringContainsString('<Eliminar><EliminandoPredecesor', $del['xml']);
        $this->assertStringNotContainsString('<Devengados>', $del['xml']);
        $this->assertSame(hash('sha384', 'DNE249'.'2026-10-14'.'10:00:00-05:00'.'0.00'.'0.00'.'0.00'.'900123456'.'0'.'103'.'12345'.'2'), $del['cune']);
    }

    public function test_radian_event_is_signed_application_response(): void
    {
        $d = [
            'number' => 'EV1', 'code' => '031', 'claim' => '02', 'issue_date' => '2026-10-14', 'issue_time' => '10:00:00-05:00', 'environment' => '2',
            'software' => ['id' => 'soft', 'pin' => '12345', 'provider_nit' => '900123456', 'provider_dv' => '7'],
            'sender' => ['id_type' => '31', 'id' => '900123456', 'dv' => '7', 'name' => 'Comprador SAS', 'tax_scheme' => ['01', 'IVA']],
            'receiver' => ['id_type' => '31', 'id' => '800146425', 'dv' => '1', 'name' => 'Proveedor SAS', 'tax_scheme' => ['01', 'IVA']],
            'invoice' => ['number' => 'FE20520478', 'cufe' => str_repeat('a', 96)], 'person' => null,
        ];
        $built = (new RadianEventBuilder)->build($d);
        $this->assertSame(hash('sha384', 'EV12026-10-1410:00:00-05:00900123456800146425031FE20520478'.'01'.'12345'), $built['cude']);
        $xp = $this->xpath($built['xml']);
        $this->assertSame('031', $xp->evaluate("string(//*[local-name()='ResponseCode'])"));
        $this->assertSame('02', $xp->evaluate("string(//*[local-name()='ResponseCode']/@listID)"));
        $this->assertSame(str_repeat('a', 96), $xp->evaluate("string(//*[local-name()='DocumentReference']/*[local-name()='UUID'])"));
        $bundle = $this->certificate();
        $this->assertSignatureValid((new XadesSigner($bundle))->sign($built['xml'], 'third party'), $bundle);
    }

    private function payroll(): array
    {
        return [
            'note_type' => null, 'prefix' => 'DNE', 'consecutive' => '248', 'number' => 'DNE248', 'issue_date' => '2026-10-14', 'issue_time' => '10:00:00-05:00',
            'environment' => '2', 'notes' => null, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'period_code' => '5', 'worked_time' => '400',
            'software' => ['id' => 'soft-n', 'pin' => '12345', 'provider_nit' => '900123456', 'provider_dv' => '7'],
            'employer' => ['name' => 'EMPRESA SAS', 'nit' => '900123456', 'dv' => '7', 'department_code' => '41', 'municipality_code' => '41551', 'address' => 'Calle 1'],
            'worker' => ['type' => '01', 'subtype' => '00', 'document_type' => '13', 'document' => '12241689', 'first_name' => 'John', 'other_names' => 'Jairo',
                'last_name' => 'Bermeo', 'second_last_name' => 'Muñoz', 'department_code' => '41', 'municipality_code' => '41551', 'address' => 'Finca',
                'integral' => false, 'contract_type' => '2', 'salary' => 1750905, 'code' => '12241689', 'start_date' => '2025-08-01', 'end_date' => null],
            'payment' => ['method' => '10', 'bank' => null, 'account_type' => null, 'account' => null],
            'payment_dates' => ['2026-09-30'],
            'earnings' => ['sueldo' => ['quantity' => 30, 'value' => 1750905, 'rate' => 0, 'label' => 'Sueldo'], 'hed' => ['quantity' => 2, 'value' => 249095, 'rate' => 0, 'label' => 'HED']],
            'deductions' => ['salud' => ['quantity' => 0, 'value' => 80000, 'rate' => 0.04, 'label' => 'Salud'], 'pension' => ['quantity' => 0, 'value' => 80000, 'rate' => 0.04, 'label' => 'Pensión']],
            'totals' => ['earnings' => 2000000, 'deductions' => 160000], 'predecessor' => null,
        ];
    }

    private function assertSignatureValid(string $xml, array $bundle): void
    {
        $doc = $this->load($xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS);
        $signedInfo = $xp->query('//ds:SignedInfo')->item(0);
        $this->assertNotNull($signedInfo, 'Falta la firma');
        $ok = openssl_verify($signedInfo->C14N(false, false), base64_decode($xp->evaluate('string(//ds:SignatureValue)')),
            openssl_pkey_get_public($bundle['cert']), OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok, 'La firma no corresponde al certificado');

        $keyInfo = $xp->query('//ds:KeyInfo')->item(0);
        $refKey = $xp->query("//ds:Reference[@URI='#".$keyInfo->getAttribute('Id')."']/ds:DigestValue")->item(0);
        $this->assertSame(base64_encode(hash('sha256', $keyInfo->C14N(false, false), true)), $refKey->textContent);
    }

    private array $lastBundle = [];

    /** @return array{cert:string, pkey:string} */
    private function certificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'CO', 'commonName' => 'PRUEBA', 'serialNumber' => '900123456'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], 1234);
        openssl_x509_export($cert, $pem);
        openssl_pkey_export($key, $pkey);

        return $this->lastBundle = ['cert' => $pem, 'pkey' => $pkey];
    }

    private function load(string $xml): DOMDocument
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml);

        return $doc;
    }

    private function xpath(string $xml): DOMXPath
    {
        $xp = new DOMXPath($this->load($xml));
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        return $xp;
    }

    public function test_mandate_invoice_uses_profile_11_roles_and_zz(): void
    {
        $d = $this->invoice();
        $d['customization'] = '11';
        $d['payment'] = ['id' => '1', 'code' => '1', 'due_date' => '2026-10-14', 'show_due' => true];
        $d['lines'] = [
            ['code' => 'INT', 'description' => 'Internet', 'quantity' => 1, 'price' => 100000, 'discount' => 0, 'base' => 100000, 'unit' => '94',
                'scheme_id' => '1', 'provider' => ['id_type' => '31', 'id' => '900111222', 'dv' => '1'],
                'taxes' => [['code' => '01', 'name' => 'IVA', 'percent' => 19, 'base' => 100000, 'amount' => 19000]]],
            ['code' => 'MAN', 'description' => 'Mantenimiento', 'quantity' => 1, 'price' => 20000, 'discount' => 0, 'base' => 20000, 'unit' => '94',
                'scheme_id' => '0', 'taxes' => [['code' => '01', 'name' => 'IVA', 'percent' => 19, 'base' => 20000, 'amount' => 3800]]],
            ['code' => 'AJU', 'description' => 'Ajuste', 'quantity' => 1, 'price' => -50, 'discount' => 0, 'base' => -50, 'unit' => '94',
                'scheme_id' => '0', 'taxes' => [['code' => 'ZZ', 'name' => 'No aplica', 'percent' => 0, 'base' => -50, 'amount' => 0]]],
        ];
        $d['taxes'] = [['code' => '01', 'name' => 'IVA', 'percent' => 19, 'base' => 120000, 'amount' => 22800]];
        $d['totals'] = ['line_extension' => 119950, 'tax_exclusive' => 120000, 'tax_inclusive' => 142750, 'payable' => 142750];

        $xml = (new UblBuilder)->build($d)['xml'];

        $this->assertStringContainsString('<cbc:CustomizationID>11</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('<cbc:ID schemeID="1">1</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:ID schemeID="0">2</cbc:ID>', $xml);
        $this->assertStringContainsString('<cac:InformationContentProviderParty>', $xml);
        $this->assertSame(1, substr_count($xml, '<cac:InformationContentProviderParty>'));
        $this->assertStringContainsString('schemeName="31"', $xml);
        $this->assertStringContainsString('>900111222</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:ID>ZZ</cbc:ID>', $xml);
        $this->assertStringContainsString('<cbc:PaymentMeansCode>1</cbc:PaymentMeansCode>', $xml);
        $this->assertStringContainsString('<cbc:PaymentDueDate>2026-10-14</cbc:PaymentDueDate>', $xml);
        $this->assertStringContainsString('<cbc:LineExtensionAmount currencyID="COP">119950.00</cbc:LineExtensionAmount>', $xml);
        $this->assertStringContainsString('<cbc:TaxExclusiveAmount currencyID="COP">120000.00</cbc:TaxExclusiveAmount>', $xml);
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="COP">142750.00</cbc:PayableAmount>', $xml);
        $this->assertStringNotContainsString('<cbc:CustomizationID>10</cbc:CustomizationID>', $xml);
    }

    public function test_credit_note_stays_on_profile_20_with_external_invoice(): void
    {
        $d = ['kind' => 'NC'] + $this->invoice();
        $d['reference'] = ['number' => 'FE123', 'key' => 'abc123', 'date' => '2026-10-01'];
        $d['lines'][0]['scheme_id'] = '1';
        $d['lines'][0]['provider'] = ['id_type' => '31', 'id' => '900111222', 'dv' => '1'];

        $xml = (new UblBuilder)->build($d)['xml'];

        $this->assertStringContainsString('<cbc:CustomizationID>20</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('<cbc:ID>FE123</cbc:ID>', $xml);
        $this->assertStringContainsString('>abc123</cbc:UUID>', $xml);
    }

    public function test_non_resident_is_id_type_or_country(): void
    {
        $this->assertTrue(DocumentMapper::nonResident(['id_type' => '50', 'country_code' => 'CO']));
        $this->assertTrue(DocumentMapper::nonResident(['id_type' => '31', 'country_code' => 'DO']));
        $this->assertFalse(DocumentMapper::nonResident(['id_type' => '31', 'country_code' => 'CO']));
        $this->assertFalse(DocumentMapper::nonResident(['id_type' => '13', 'country_code' => 'CO']));
    }

    public function test_support_document_for_non_resident_uses_customization_11_and_exchange_rate(): void
    {
        $d = $this->invoice();
        $d['kind'] = 'DS';
        $d['customization'] = '11';
        $d['currency'] = 'USD';
        $d['exchange_rate'] = 4325.67;
        $d['supplier'] = [
            'type' => '2', 'id_type' => '50', 'id' => '22300801382', 'dv' => '', 'name' => 'JEFFERSON RAMIREZ',
            'address' => 'Republica dominicana', 'municipality_code' => null, 'city' => 'Santo Domingo',
            'department' => '', 'department_code' => '', 'postal' => null, 'country_code' => 'DO', 'country_name' => 'República Dominicana',
            'email' => 'a@b.co', 'phone' => '3000000000', 'tax_level' => 'R-99-PN', 'tax_scheme' => ['ZZ', 'No aplica'], 'registration_prefix' => null,
        ];

        $xml = (new UblBuilder)->build($d)['xml'];

        $this->assertStringContainsString('<cbc:CustomizationID>11</cbc:CustomizationID>', $xml);
        $this->assertStringContainsString('schemeName="50"', $xml);
        $this->assertStringContainsString('<cbc:IdentificationCode>DO</cbc:IdentificationCode>', $xml);
        $this->assertStringContainsString('República Dominicana', $xml);
        $this->assertStringContainsString('<cbc:CityName>Santo Domingo</cbc:CityName>', $xml);
        $this->assertStringContainsString('<cbc:DocumentCurrencyCode>USD</cbc:DocumentCurrencyCode>', $xml);
        $this->assertStringContainsString('<cbc:SourceCurrencyCode>USD</cbc:SourceCurrencyCode>', $xml);
        $this->assertStringContainsString('<cbc:TargetCurrencyCode>COP</cbc:TargetCurrencyCode>', $xml);
        $this->assertStringContainsString('<cbc:CalculationRate>4325.6700</cbc:CalculationRate>', $xml);
        $this->assertStringNotContainsString('<cbc:CustomizationID>10</cbc:CustomizationID>', $xml);
        $this->assertStringNotContainsString('>41551</cbc:ID>', $xml);
        $this->assertStringNotContainsString('<cbc:PostalZone>000000</cbc:PostalZone>', $xml);
    }

    private function invoice(): array
    {
        $party = fn (string $id, string $name) => [
            'type' => '1', 'id_type' => '31', 'id' => $id, 'dv' => '7', 'name' => $name, 'address' => 'Calle 1 # 2-3', 'municipality_code' => '11001',
            'city' => 'Bogotá, D.C.', 'department' => 'Bogotá', 'department_code' => '11', 'postal' => '110111', 'email' => 'a@b.co', 'phone' => '3000000000',
            'tax_level' => 'O-13', 'tax_scheme' => ['01', 'IVA'], 'registration_prefix' => null,
        ];

        return [
            'kind' => 'FV', 'number' => 'SETP990000001', 'issue_date' => '2026-10-14', 'issue_time' => '10:53:10-05:00', 'due_date' => '2026-11-13',
            'environment' => '2', 'currency' => 'COP', 'notes' => null, 'technical_key' => 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
            'resolution' => ['number' => '18760000001', 'valid_from' => '2019-01-19', 'valid_to' => '2030-01-19', 'prefix' => 'SETP', 'from' => 990000000, 'to' => 995000000],
            'software' => ['id' => 'soft-1', 'pin' => '12345', 'provider_nit' => '900123456', 'provider_dv' => '7'],
            'supplier' => $party('900123456', 'EMPRESA DE PRUEBA SAS'), 'customer' => $party('800199436', 'CLIENTE SAS'),
            'payment' => ['id' => '2', 'code' => 'ZZZ', 'due_date' => '2026-11-13'],
            'lines' => [['code' => 'P1', 'description' => 'Concentrado', 'quantity' => 2, 'price' => 100000, 'discount' => 0, 'base' => 200000, 'unit' => 'KGM',
                'taxes' => [['code' => '01', 'name' => 'IVA', 'percent' => 19, 'base' => 200000, 'amount' => 38000]]]],
            'taxes' => [['code' => '01', 'name' => 'IVA', 'percent' => 19, 'base' => 200000, 'amount' => 38000]],
            'withholdings' => [['code' => '06', 'name' => 'ReteRenta', 'percent' => 2.5, 'base' => 200000, 'amount' => 5000]],
            'totals' => ['line_extension' => 200000, 'tax_exclusive' => 200000, 'tax_inclusive' => 238000, 'payable' => 238000],
            'reference' => null, 'discrepancy' => ['code' => '1', 'description' => 'Devolución'],
        ];
    }
}
