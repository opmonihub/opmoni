<?php

namespace Tests\Feature;

use App\Erp\Fiscal\ErpFiscalSaleSourcePrerequisites;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpSaleFiscalPrerequisiteRulesTest extends TestCase
{
    private function facts(string $model): array
    {
        $case = $model === '55' ? 'NF-e 55 posted sale' : 'NFC-e 65 posted sale';
        $source = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case][2];

        return array_intersect_key($source, array_flip(['issuer', 'recipient', 'items', 'operation_nature'])) + [
            'tax_context' => ['origin_uf' => 'SP', 'destination_uf' => 'SP', 'model' => $model,
                'purpose' => 'normal', 'final_consumer' => true, 'presence' => '1', 'movement' => 'out'],
        ];
    }

    public static function models(): iterable
    {
        yield 'NF-e' => ['55'];
        yield 'NFC-e' => ['65'];
    }

    #[DataProvider('models')]
    public function test_sale_source_stage_validates_represented_facts_without_fabricating_issuance_identity(string $model): void
    {
        $facts = $this->facts($model);
        unset($facts['recipient']['state_registration_indicator']);
        foreach (['identity', 'nfce_qr', 'payment', 'freight', 'totals'] as $issuanceField) {
            $this->assertArrayNotHasKey($issuanceField, $facts);
        }

        $this->assertSame([], app(ErpFiscalSaleSourcePrerequisites::class)->errors($model, $facts));
        $this->assertArrayNotHasKey('state_registration_indicator', $facts['recipient']);
    }

    #[DataProvider('models')]
    public function test_both_existing_strict_models_require_actual_recipient_identity_and_address(string $model): void
    {
        $facts = $this->facts($model);
        unset($facts['recipient']['cnpj'], $facts['recipient']['address']);
        $errors = app(ErpFiscalSaleSourcePrerequisites::class)->errors($model, $facts);

        $this->assertArrayHasKey('recipient.cnpj', $errors);
        $this->assertArrayHasKey('recipient.address', $errors);
        $this->assertArrayNotHasKey('cnpj', $facts['recipient']);
        $this->assertArrayNotHasKey('address', $facts['recipient']);
    }

    public function test_missing_frozen_item_classifications_and_issuer_municipality_report_real_source_paths(): void
    {
        $facts = $this->facts('55');
        unset($facts['items'][0]['ncm'], $facts['items'][0]['tax']['origin'], $facts['issuer']['address']['city_code']);
        $errors = app(ErpFiscalSaleSourcePrerequisites::class)->errors('55', $facts);

        foreach (['items.0.ncm', 'items.0.tax.origin', 'issuer.address.city_code'] as $path) {
            $this->assertArrayHasKey($path, $errors);
        }
    }

    public function test_supported_tax_groups_remain_the_current_strict_builder_groups(): void
    {
        $facts = $this->facts('55');
        $facts['items'][0]['tax']['pis_cst'] = '01';
        $errors = app(ErpFiscalSaleSourcePrerequisites::class)->errors('55', $facts);

        $this->assertArrayHasKey('items.tax.unsupported_group', $errors);
        $this->assertSame('01', $facts['items'][0]['tax']['pis_cst']);
    }

    #[DataProvider('models')]
    public function test_unrepresented_recipient_registration_indicator_remains_mandatory_at_issuance(string $model): void
    {
        $case = $model === '55' ? 'NF-e 55 posted sale' : 'NFC-e 65 posted sale';
        [$builder, , $source] = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case];
        unset($source['recipient']['state_registration_indicator']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recipient.state_registration_indicator');

        app($builder)->validateSource($source);
    }

    #[DataProvider('models')]
    public function test_issuance_keeps_full_identity_validation_after_reusing_sale_source_prerequisites(string $model): void
    {
        $case = $model === '55' ? 'NF-e 55 posted sale' : 'NFC-e 65 posted sale';
        [$builder, , $source] = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case];
        unset($source['identity']['access_key']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('identity.access_key');

        app($builder)->validateSource($source);
    }

    #[DataProvider('models')]
    public function test_full_sale_builder_keeps_original_missing_invalid_and_unsupported_error_paths_in_order(string $model): void
    {
        $case = $model === '55' ? 'NF-e 55 posted sale' : 'NFC-e 65 posted sale';
        [$builder, , $source] = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case];
        $source['issuer']['legal_name'] = 'A';
        $source['issuer']['state_registration'] = 'invalid';
        $source['issuer']['tax_regime'] = '3';
        unset($source['recipient']['cnpj'], $source['recipient']['address'], $source['items'][0]['ncm']);
        $source['items'][0]['tax']['origin'] = '9';
        $source['items'][0]['tax']['pis_cst'] = '01';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dados fiscais obrigatórios ausentes ou inválidos: issuer.legal_name, issuer.state_registration, issuer.tax_regime, recipient.cnpj, recipient.address, items.0.tax.origin, items.0.typed_fields, items.0.ncm, items.tax.unsupported_group.');
        app($builder)->validateSource($source);
    }

    #[DataProvider('models')]
    public function test_full_sale_builder_keeps_structural_and_invalid_scalar_errors_distinct_from_missing_fields(string $model): void
    {
        $case = $model === '55' ? 'NF-e 55 posted sale' : 'NFC-e 65 posted sale';
        [$builder, , $source] = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case];
        foreach (['group', 'scalar'] as $invalid) {
            $changed = $source;
            if ($invalid === 'group') {
                $changed['recipient'] = 'invalid';
                $expected = 'Grupo fiscal obrigatório inválido: recipient.';
            } else {
                $changed['items'][0]['tax']['origin'] = ['invalid'];
                $expected = 'Campo fiscal escalar inválido: origin.';
            }
            try {
                app($builder)->validateSource($changed);
                $this->fail('The existing full builder must reject malformed source.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame($expected, $error->getMessage());
            }
        }
    }

    public static function transportModels(): iterable
    {
        yield 'CT-e' => ['CT-e 57 typed transport service'];
        yield 'MDF-e' => ['MDF-e 58 typed manifest'];
    }

    #[DataProvider('transportModels')]
    public function test_full_transport_builders_keep_original_shared_issuer_validation(string $case): void
    {
        [$builder, , $source] = ErpFiscalModelBuilderSchemaTest::builderProvider()[$case];
        app($builder)->validateSource($source);
        $source['issuer']['legal_name'] = 'A';
        $source['issuer']['state_registration'] = 'invalid';
        $source['issuer']['tax_regime'] = '3';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dados fiscais obrigatórios ausentes ou inválidos: issuer.legal_name, issuer.state_registration, issuer.tax_regime.');
        app($builder)->validateSource($source);
    }
}
