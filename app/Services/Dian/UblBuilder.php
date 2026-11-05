<?php

namespace App\Services\Dian;

/**
 * XML UBL 2.1 segun el Anexo tecnico de factura electronica DIAN (v1.9) y documento soporte (v1.1).
 * Recibe el documento normalizado por DocumentMapper y devuelve el XML sin firmar, la clave (CUFE/CUDE/CUDS) y el QR.
 */
class UblBuilder
{
    private const NS = 'xmlns="urn:oasis:names:specification:ubl:schema:xsd:%s-2" '
        .'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" '
        .'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" '
        .'xmlns:ds="http://www.w3.org/2000/09/xmldsig#" '
        .'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" '
        .'xmlns:sts="dian:gov:co:facturaelectronica:Structures-2-1" '
        .'xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" '
        .'xmlns:xades141="http://uri.etsi.org/01903/v1.4.1#" '
        .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';

    private const DIAN = 'schemeAgencyID="195" schemeAgencyName="CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)"';

    private const PROFILES = [
        'FV' => ['Invoice', 'DIAN 2.1: Factura Electrónica de Venta', '10', '01'],
        'NC' => ['CreditNote', 'DIAN 2.1: Nota Crédito de Factura Electrónica de Venta', '20', '91'],
        'ND' => ['DebitNote', 'DIAN 2.1: Nota Débito de Factura Electrónica de Venta', '30', null],
        'DS' => ['Invoice', 'DIAN 2.1: documento soporte en adquisiciones efectuadas a no obligados a facturar.', '10', '05'],
        'NA' => ['CreditNote', 'DIAN 2.1: Nota de ajuste al documento soporte en adquisiciones efectuadas a sujetos no obligados a expedir factura o documento equivalente.', '10', '95'],
    ];

    public const TAX_CODES = ['IVA' => ['01', 'IVA'], 'Impoconsumo' => ['04', 'INC'], 'ICA' => ['03', 'ICA']];

    public const WITHHOLDING_CODES = ['ReteIVA' => ['05', 'ReteIVA'], 'Retefuente' => ['06', 'ReteRenta'], 'ReteICA' => ['07', 'ReteICA']];

    /** @return array{xml:string, key:string, qr:string, root:string} */
    public function build(array $d): array
    {
        [$root, $profile, $customization, $typeCode] = self::PROFILES[$d['kind']];
        if (! empty($d['customization'])) {
            $customization = $d['customization'];
        }
        $key = $this->documentKey($d);
        $qr = $this->qr($d, $key);
        $isDs = in_array($d['kind'], ['DS', 'NA'], true);
        $lineTag = ['Invoice' => 'InvoiceLine', 'CreditNote' => 'CreditNoteLine', 'DebitNote' => 'DebitNoteLine'][$root];
        $qtyTag = ['Invoice' => 'InvoicedQuantity', 'CreditNote' => 'CreditedQuantity', 'DebitNote' => 'DebitedQuantity'][$root];

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>';
        $x .= '<'.$root.' '.sprintf(self::NS, $root).'>';
        $x .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent><sts:DianExtensions>';
        if ($d['kind'] === 'FV' || $d['kind'] === 'DS') {
            $r = $d['resolution'];
            $x .= '<sts:InvoiceControl>'.$this->el('sts:InvoiceAuthorization', $r['number'])
                .'<sts:AuthorizationPeriod>'.$this->el('cbc:StartDate', $r['valid_from']).$this->el('cbc:EndDate', $r['valid_to']).'</sts:AuthorizationPeriod>'
                .'<sts:AuthorizedInvoices>'.($r['prefix'] !== '' ? $this->el('sts:Prefix', $r['prefix']) : '').$this->el('sts:From', $r['from']).$this->el('sts:To', $r['to']).'</sts:AuthorizedInvoices>'
                .'</sts:InvoiceControl>';
        }
        $x .= '<sts:InvoiceSource><cbc:IdentificationCode listAgencyID="6" listAgencyName="United Nations Economic Commission for Europe" listSchemeURI="urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1">CO</cbc:IdentificationCode></sts:InvoiceSource>';
        $x .= '<sts:SoftwareProvider><sts:ProviderID '.self::DIAN.' schemeID="'.$d['software']['provider_dv'].'" schemeName="31">'.$d['software']['provider_nit'].'</sts:ProviderID>'
            .'<sts:SoftwareID '.self::DIAN.'>'.$this->e($d['software']['id']).'</sts:SoftwareID></sts:SoftwareProvider>';
        $x .= '<sts:SoftwareSecurityCode '.self::DIAN.'>'.hash('sha384', $d['software']['id'].$d['software']['pin'].$d['number']).'</sts:SoftwareSecurityCode>';
        $x .= '<sts:AuthorizationProvider><sts:AuthorizationProviderID '.self::DIAN.' schemeID="4" schemeName="31">800197268</sts:AuthorizationProviderID></sts:AuthorizationProvider>';
        $x .= $this->el('sts:QRCode', $qr);
        $x .= '</sts:DianExtensions></ext:ExtensionContent></ext:UBLExtension>';
        $x .= '<ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>';

        $x .= $this->el('cbc:UBLVersionID', 'UBL 2.1').$this->el('cbc:CustomizationID', $customization).$this->el('cbc:ProfileID', $profile);
        $x .= $this->el('cbc:ProfileExecutionID', $d['environment']).$this->el('cbc:ID', $d['number']);
        $scheme = match ($d['kind']) { 'FV' => 'CUFE-SHA384', 'DS', 'NA' => 'CUDS-SHA384', default => 'CUDE-SHA384' };
        $x .= '<cbc:UUID schemeID="'.$d['environment'].'" schemeName="'.$scheme.'">'.$key.'</cbc:UUID>';
        $x .= $this->el('cbc:IssueDate', $d['issue_date']).$this->el('cbc:IssueTime', $d['issue_time']);
        if ($root === 'Invoice' && ! empty($d['due_date'])) {
            $x .= $this->el('cbc:DueDate', $d['due_date']);
        }
        if ($typeCode && $root === 'Invoice') {
            $x .= $this->el('cbc:InvoiceTypeCode', $typeCode);
        } elseif ($typeCode) {
            $x .= $this->el('cbc:CreditNoteTypeCode', $typeCode);
        }
        if (filled($d['notes'] ?? null)) {
            $x .= $this->el('cbc:Note', $d['notes']);
        }
        $x .= $this->el('cbc:DocumentCurrencyCode', $d['currency']).$this->el('cbc:LineCountNumeric', count($d['lines']));

        if (! empty($d['reference'])) {
            $ref = $d['reference'];
            $x .= '<cac:DiscrepancyResponse>'.$this->el('cbc:ReferenceID', $ref['number']).$this->el('cbc:ResponseCode', $d['discrepancy']['code'])
                .$this->el('cbc:Description', $d['discrepancy']['description']).'</cac:DiscrepancyResponse>';
            $x .= '<cac:BillingReference><cac:InvoiceDocumentReference>'.$this->el('cbc:ID', $ref['number'])
                .'<cbc:UUID schemeName="'.($isDs ? 'CUDS-SHA384' : 'CUFE-SHA384').'">'.$this->e($ref['key']).'</cbc:UUID>'
                .$this->el('cbc:IssueDate', $ref['date']).'</cac:InvoiceDocumentReference></cac:BillingReference>';
        }

        $x .= '<cac:AccountingSupplierParty>'.$this->party($d['supplier'], $isDs, true).'</cac:AccountingSupplierParty>';
        $x .= '<cac:AccountingCustomerParty>'.$this->party($d['customer'], false, ! $isDs).'</cac:AccountingCustomerParty>';
        $p = $d['payment'];
        $x .= '<cac:PaymentMeans>'.$this->el('cbc:ID', $p['id']).$this->el('cbc:PaymentMeansCode', $p['code'])
            .((($p['id'] === '2' || ! empty($p['show_due'])) && ! empty($p['due_date'])) ? $this->el('cbc:PaymentDueDate', $p['due_date']) : '').'</cac:PaymentMeans>';
        if (($d['currency'] ?? 'COP') !== 'COP' && (float) ($d['exchange_rate'] ?? 0) > 0) {
            $x .= '<cac:PaymentExchangeRate>'.$this->el('cbc:SourceCurrencyCode', $d['currency'])
                .$this->el('cbc:SourceCurrencyBaseRate', '1.00').$this->el('cbc:TargetCurrencyCode', 'COP')
                .$this->el('cbc:TargetCurrencyBaseRate', '1.00').$this->el('cbc:CalculationRate', $this->num((float) $d['exchange_rate'], 4))
                .$this->el('cbc:Date', $d['issue_date']).'</cac:PaymentExchangeRate>';
        }

        $x .= $this->taxTotals($d['taxes'], 'TaxTotal', $d['currency']);
        $x .= $this->taxTotals($d['withholdings'], 'WithholdingTaxTotal', $d['currency']);

        $t = $d['totals'];
        $totalTag = $root === 'DebitNote' ? 'RequestedMonetaryTotal' : 'LegalMonetaryTotal';
        $x .= '<cac:'.$totalTag.'>'.$this->amount('cbc:LineExtensionAmount', $t['line_extension'], $d['currency'])
            .$this->amount('cbc:TaxExclusiveAmount', $t['tax_exclusive'], $d['currency'])
            .$this->amount('cbc:TaxInclusiveAmount', $t['tax_inclusive'], $d['currency'])
            .$this->amount('cbc:AllowanceTotalAmount', 0, $d['currency'])
            .$this->amount('cbc:ChargeTotalAmount', 0, $d['currency'])
            .$this->amount('cbc:PayableAmount', $t['payable'], $d['currency']).'</cac:'.$totalTag.'>';

        foreach ($d['lines'] as $i => $l) {
            $x .= '<cac:'.$lineTag.'>'.'<cbc:ID'.(isset($l['scheme_id']) ? ' schemeID="'.$l['scheme_id'].'"' : '').'>'.($i + 1).'</cbc:ID>'
                .'<cbc:'.$qtyTag.' unitCode="'.($l['unit'] ?? '94').'">'.$this->num($l['quantity'], 6).'</cbc:'.$qtyTag.'>'
                .$this->amount('cbc:LineExtensionAmount', $l['base'], $d['currency']);
            if ($isDs && $d['kind'] === 'DS') {
                $x .= '<cac:InvoicePeriod>'.$this->el('cbc:StartDate', $d['issue_date']).$this->el('cbc:DescriptionCode', '1').$this->el('cbc:Description', 'Por operación').'</cac:InvoicePeriod>';
            }
            if ($l['discount'] > 0) {
                $x .= '<cac:AllowanceCharge>'.$this->el('cbc:ID', 1).$this->el('cbc:ChargeIndicator', 'false')
                    .$this->el('cbc:AllowanceChargeReason', 'Descuento').$this->el('cbc:MultiplierFactorNumeric', $this->num($l['discount'] / max($l['base'] + $l['discount'], 0.01) * 100))
                    .$this->amount('cbc:Amount', $l['discount'], $d['currency']).$this->amount('cbc:BaseAmount', $l['base'] + $l['discount'], $d['currency']).'</cac:AllowanceCharge>';
            }
            $x .= $this->taxTotals($l['taxes'], 'TaxTotal', $d['currency']);
            $x .= '<cac:Item>'.$this->el('cbc:Description', $l['description'])
                .(! empty($l['provider']) ? $this->providerParty($l['provider']) : '')
                .'<cac:StandardItemIdentification><cbc:ID schemeID="999" schemeName="Estándar de adopción del contribuyente">'.$this->e($l['code']).'</cbc:ID></cac:StandardItemIdentification></cac:Item>';
            $x .= '<cac:Price>'.$this->amount('cbc:PriceAmount', $l['price'], $d['currency']).'<cbc:BaseQuantity unitCode="'.($l['unit'] ?? '94').'">'.$this->num($l['quantity'], 6).'</cbc:BaseQuantity></cac:Price>';
            $x .= '</cac:'.$lineTag.'>';
        }
        $x .= '</'.$root.'>';

        return ['xml' => $x, 'key' => $key, 'qr' => $qr, 'root' => $root];
    }

    /** CUFE (FV), CUDE (NC/ND) y CUDS (DS/NA) segun el anexo tecnico. */
    public function documentKey(array $d): string
    {
        $t = $d['totals'];
        $tax = fn (string $code) => $this->num(collect($d['taxes'])->where('code', $code)->sum('amount'));
        if (in_array($d['kind'], ['DS', 'NA'], true)) {
            return hash('sha384', $d['number'].$d['issue_date'].$d['issue_time'].$this->num($t['line_extension']).'01'.$tax('01')
                .$this->num($t['payable']).$d['supplier']['id'].$d['customer']['id'].$d['software']['pin'].$d['environment']);
        }
        $secret = $d['kind'] === 'FV' ? $d['technical_key'] : $d['software']['pin'];

        return hash('sha384', $d['number'].$d['issue_date'].$d['issue_time'].$this->num($t['line_extension'])
            .'01'.$tax('01').'04'.$tax('04').'03'.$tax('03').$this->num($t['payable'])
            .$d['supplier']['id'].$d['customer']['id'].$secret.$d['environment']);
    }

    public function qr(array $d, string $key): string
    {
        $t = $d['totals'];
        $host = $d['environment'] === '1' ? 'https://catalogo-vpfe.dian.gov.co' : 'https://catalogo-vpfe-hab.dian.gov.co';
        $iva = collect($d['taxes'])->where('code', '01')->sum('amount');
        $other = collect($d['taxes'])->where('code', '!=', '01')->sum('amount');

        return implode("\n", [
            'NumFac='.$d['number'], 'FecFac='.$d['issue_date'], 'HorFac='.$d['issue_time'], 'NitFac='.$d['supplier']['id'],
            'DocAdq='.$d['customer']['id'], 'ValFac='.$this->num($t['line_extension']), 'ValIva='.$this->num($iva),
            'ValOtroIm='.$this->num($other), 'ValTolFac='.$this->num($t['payable']), 'CUFE='.$key,
            'QRCode='.$host.'/document/searchqr?documentkey='.$key,
        ]);
    }

    /** Nombre de archivo DIAN: prefijo + NIT(10) + PPP(000 software propio) + AA + consecutivo (8 hex). */
    public static function fileName(string $prefix, string $nit, int $sequence, ?int $year = null): string
    {
        return $prefix.str_pad(preg_replace('/\D/', '', $nit), 10, '0', STR_PAD_LEFT).'000'.substr((string) ($year ?? now()->year), -2)
            .str_pad(strtolower(dechex($sequence % 0xFFFFFFFF)), 8, '0', STR_PAD_LEFT);
    }

    /** Mandante dentro del ítem: NIT con DV, otro documento sin schemeID. */
    private function providerParty(array $p): string
    {
        $attrs = ' '.self::DIAN.' schemeName="'.$this->e($p['id_type']).'"';
        if (($p['id_type'] ?? '') === '31' && filled($p['dv'] ?? null)) {
            $attrs .= ' schemeID="'.$this->e((string) $p['dv']).'"';
        }
        $id = '<cbc:ID'.$attrs.'>'.$this->e($p['id']).'</cbc:ID>';

        return '<cac:InformationContentProviderParty><cac:PowerOfAttorney><cac:AgentParty><cac:PartyIdentification>'.$id
            .'</cac:PartyIdentification></cac:AgentParty></cac:PowerOfAttorney></cac:InformationContentProviderParty>';
    }

    private function party(array $p, bool $isDsSupplier, bool $withLocation): string
    {
        $id = '<cbc:CompanyID '.self::DIAN.($p['id_type'] === '31' ? ' schemeID="'.$p['dv'].'"' : '').' schemeName="'.$p['id_type'].'">'.$this->e($p['id']).'</cbc:CompanyID>';
        $address = $this->address($p);
        $x = $this->el('cbc:AdditionalAccountID', $p['type']).'<cac:Party>';
        if ($p['id_type'] !== '31' || $isDsSupplier || (string) $p['type'] === '2') {
            $x .= '<cac:PartyIdentification><cbc:ID schemeName="'.$p['id_type'].'"'.($p['id_type'] === '31' ? ' schemeID="'.$p['dv'].'"' : '').'>'.$this->e($p['id']).'</cbc:ID></cac:PartyIdentification>';
        }
        $x .= '<cac:PartyName>'.$this->el('cbc:Name', $p['name']).'</cac:PartyName>';
        if ($withLocation) {
            $x .= '<cac:PhysicalLocation>'.str_replace('RegistrationAddress', 'Address', $address).'</cac:PhysicalLocation>';
        }
        $x .= '<cac:PartyTaxScheme>'.$this->el('cbc:RegistrationName', $p['name']).$id
            .'<cbc:TaxLevelCode listName="48">'.$this->e($p['tax_level']).'</cbc:TaxLevelCode>'.$address
            .'<cac:TaxScheme>'.$this->el('cbc:ID', $p['tax_scheme'][0]).$this->el('cbc:Name', $p['tax_scheme'][1]).'</cac:TaxScheme></cac:PartyTaxScheme>';
        $x .= '<cac:PartyLegalEntity>'.$this->el('cbc:RegistrationName', $p['name']).$id
            .(! empty($p['registration_prefix']) ? '<cac:CorporateRegistrationScheme>'.$this->el('cbc:ID', $p['registration_prefix']).'</cac:CorporateRegistrationScheme>' : '')
            .'</cac:PartyLegalEntity>';
        if (filled($p['email'] ?? null) || filled($p['phone'] ?? null)) {
            $x .= '<cac:Contact>'.(filled($p['phone'] ?? null) ? $this->el('cbc:Telephone', $p['phone']) : '').(filled($p['email'] ?? null) ? $this->el('cbc:ElectronicMail', $p['email']) : '').'</cac:Contact>';
        }

        return $x.'</cac:Party>';
    }

    private function address(array $p): string
    {
        $country = strtoupper((string) ($p['country_code'] ?? 'CO')) ?: 'CO';
        $name = (string) ($p['country_name'] ?? ($country === 'CO' ? 'Colombia' : $country));
        $foreign = $country !== 'CO';
        $x = '<cac:RegistrationAddress>';
        if (! $foreign && filled($p['municipality_code'] ?? null)) {
            $x .= $this->el('cbc:ID', $p['municipality_code']);
        }
        $x .= $this->el('cbc:CityName', $p['city'] ?? '');
        if (! $foreign && filled($p['postal'] ?? null)) {
            $x .= $this->el('cbc:PostalZone', $p['postal']);
        }
        if (! $foreign) {
            $x .= $this->el('cbc:CountrySubentity', $p['department'] ?? '').$this->el('cbc:CountrySubentityCode', $p['department_code'] ?? '');
        }
        $x .= '<cac:AddressLine>'.$this->el('cbc:Line', $p['address']).'</cac:AddressLine>';
        $x .= '<cac:Country>'.$this->el('cbc:IdentificationCode', $country).'<cbc:Name languageID="es">'.$this->e($name).'</cbc:Name></cac:Country></cac:RegistrationAddress>';

        return $x;
    }

    /** @param list<array{code:string, name:string, percent:float, base:float, amount:float}> $taxes */
    private function taxTotals(array $taxes, string $tag, string $currency): string
    {
        $x = '';
        foreach (collect($taxes)->groupBy('code') as $code => $rows) {
            $x .= '<cac:'.$tag.'>'.$this->amount('cbc:TaxAmount', $rows->sum('amount'), $currency);
            foreach ($rows as $r) {
                $x .= '<cac:TaxSubtotal>'.$this->amount('cbc:TaxableAmount', $r['base'], $currency).$this->amount('cbc:TaxAmount', $r['amount'], $currency)
                    .'<cac:TaxCategory>'.$this->el('cbc:Percent', $this->num($r['percent']))
                    .'<cac:TaxScheme>'.$this->el('cbc:ID', $code).$this->el('cbc:Name', $r['name']).'</cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>';
            }
            $x .= '</cac:'.$tag.'>';
        }

        return $x;
    }

    private function amount(string $tag, float $value, string $currency): string
    {
        return '<'.$tag.' currencyID="'.$currency.'">'.$this->num($value).'</'.$tag.'>';
    }

    private function el(string $tag, mixed $value): string
    {
        return '<'.$tag.'>'.$this->e((string) $value).'</'.$tag.'>';
    }

    private function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function num(float $v, int $decimals = 2): string
    {
        return number_format(round($v, $decimals), $decimals, '.', '');
    }
}
