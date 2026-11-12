<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\Contact;
use App\Models\DianSetting;
use App\Models\PurchaseDocument;
use App\Services\Dian\DocumentMapper;
use App\Services\Dian\UblBuilder;
use App\Support\ColombianNit;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DianDocumentMapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
        });
        Schema::create('purchase_document_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_document_id');
            $table->unsignedInteger('position');
        });
        Schema::create('purchase_document_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_document_item_id');
        });
        Schema::create('purchase_document_retentions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_document_id');
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('unit_code')->nullable();
        });
        Schema::create('municipalities', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('department_code')->nullable();
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('name');
        });
    }

    public function test_support_document_xml_uses_nit_and_calculated_dv_for_natural_person_acquirer(): void
    {
        $data = $this->mapper()->purchase($this->purchaseDocument('DS'));
        $expectedDv = ColombianNit::verificationDigit($data['customer']['id']);

        $this->assertSame('2', $data['customer']['type']);
        $this->assertSame('31', $data['customer']['id_type']);
        $this->assertSame($expectedDv, $data['customer']['dv']);

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML((new UblBuilder)->build($data)['xml']));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $companyId = '//cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID';
        $this->assertSame('31', $xpath->evaluate("string($companyId/@schemeName)"));
        $this->assertSame($expectedDv, $xpath->evaluate("string($companyId/@schemeID)"));
    }

    public function test_adjustment_to_support_document_uses_nit_but_other_purchase_types_keep_their_id_type(): void
    {
        $adjustment = $this->mapper()->purchase($this->purchaseDocument('NA'));
        $purchase = $this->mapper()->purchase($this->purchaseDocument('FC'));

        $this->assertSame('31', $adjustment['customer']['id_type']);
        $this->assertSame(ColombianNit::verificationDigit($adjustment['customer']['id']), $adjustment['customer']['dv']);
        $this->assertSame('13', $purchase['customer']['id_type']);
    }

    private function mapper(): DocumentMapper
    {
        return new DocumentMapper(
            new DianSetting(['support_software_id' => 'software', 'support_software_pin' => 'pin']),
            new Company([
                'identification_type' => '13',
                'person_type' => 'natural',
                'nit' => '900123456',
                'business_name' => 'Empresa persona natural',
            ]),
        );
    }

    private function purchaseDocument(string $type): PurchaseDocument
    {
        $document = new PurchaseDocument(['type' => $type, 'date' => '2026-10-20', 'balance' => 0]);
        $document->setRelation('contact', new Contact([
            'identification_type' => '13',
            'identification' => '12345678',
            'person_type' => 'natural',
            'name' => 'Proveedor persona natural',
        ]));

        return $document;
    }
}
