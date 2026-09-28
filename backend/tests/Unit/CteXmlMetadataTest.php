<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use App\Services\Fiscal\Support\DfeEntry;
use App\Services\Fiscal\Support\DfeEntryCollector;
use App\Services\Fiscal\Support\DfeResponse;
use App\Services\Fiscal\Support\DocZipDecoder;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Services\Fiscal\Support\NotIndexableDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * As famílias de documento que a distribuição de CT-e entrega, e o documento
 * que chega com as chaves de transporte zeradas.
 *
 * Todos os fixtures deste arquivo são **sintéticos** e nenhum deles é uma
 * captura do Ambiente Nacional. O cabeçalho de cada um diz de onde veio a
 * forma e o que não foi conferido; este comentário não repete isso.
 */
class CteXmlMetadataTest extends TestCase
{
    /**
     * As cinco famílias do plano, mais a do GTV-e.
     *
     * A chave é a do **próprio** documento, com DV válido, e é a que a coluna
     * `chave_acesso` vai receber. Os nomes de raiz são os publicados:
     * `cteSimpProc` e `cteOSProc` e `procEventoCTe` e `GTVeProc` estão nos
     * XSD do pacote `PRCTE` do SVRS, e `resCTe` é o nome de resumo da
     * distribuição nacional de CT-e.
     *
     * @return array<string, array{string, FiscalModel, FiscalKind, FiscalStage, string}>
     */
    public static function familias(): array
    {
        return [
            'resCTe' => ['cte-resumo.xml', FiscalModel::Cte, FiscalKind::Document, FiscalStage::Summary, '35220999999999999999570000000011011000000006'],
            'cteProc' => ['cteProc.xml', FiscalModel::Cte, FiscalKind::Document, FiscalStage::Document, '35220799999999999999570010000001231123456786'],
            'cteSimpProc' => ['cte-simp.xml', FiscalModel::Cte, FiscalKind::Document, FiscalStage::Document, '35220999999999999999570000000011031000000000'],
            'cteOSProc' => ['cte-os.xml', FiscalModel::CteOs, FiscalKind::Document, FiscalStage::Document, '35220999999999999999670000000011021000000006'],
            'procEventoCTe' => ['cte-evento.xml', FiscalModel::Cte, FiscalKind::Event, FiscalStage::Event, '35220999999999999999570000000011041000000008'],
            'GTVeProc' => ['cte-gtve.xml', FiscalModel::Gtve, FiscalKind::Document, FiscalStage::Document, '35220999999999999999640000000011051000000007'],
        ];
    }

    #[DataProvider('familias')]
    public function test_reconhece_cada_familia_de_distribuicao_de_cte(
        string $fixture,
        FiscalModel $model,
        FiscalKind $kind,
        FiscalStage $stage,
        string $chave,
    ): void {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/'.$fixture));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);

        // O modelo vem da chave de acesso, e a chave é a do próprio documento —
        // é isso que distingue o CT-e OS e o GTV-e do CT-e regular, que os três
        // chegam pelo mesmo conector.
        $this->assertSame($model, $result->model);
        $this->assertSame($chave, $result->chave);
        $this->assertTrue(FiscalXmlMetadata::isValidChave($result->chave));
        $this->assertSame($kind, $result->kind);
        $this->assertSame($stage, $result->stage);
        $this->assertFalse($result->mascarado);
    }

    public function test_a_etapa_do_resumo_vem_da_ausencia_do_protocolo_e_nao_do_nome_da_raiz(): void
    {
        // `resCTe` e `cteProc` são as duas pontas da mesma cadeia: mesmo tipo,
        // mesmo `event_id` vazio, e é a presença do protocolo de autorização que
        // as separa. Um `resCTe` que trouxesse `protCTe` seria documento
        // completo, e um `cteProc` sem ele seria resumo.
        $result = (new FiscalXmlMetadata)->extract(
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-resumo.xml')),
            FiscalModel::Cte,
        );

        $this->assertSame(FiscalStage::Summary, $result->stage);
        $this->assertSame('ZmFrZSBkaWdlc3Qgc2ludGV0aWNvIGRvIHJlc3Vtbw==', $result->digVal);
    }

    public function test_a_chave_propria_nao_e_a_chave_da_nfe_transportada(): void
    {
        // O `cte-os.xml` traz uma NF-e transportada com chave legível e DV
        // válido. Ela é a primeira `chNFe` do documento em ordem de percurso, e
        // uma extração que a pegasse gravaria o CT-e OS sob a identidade de uma
        // nota que é de outro CNPJ — e a unicidade de
        // `(client_id, chave_acesso, event_id)` não a impediria, porque a chave
        // alheia entraria sem conflito.
        $result = (new FiscalXmlMetadata)->extract(
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-os.xml')),
            FiscalModel::Cte,
        );

        $this->assertSame('35220999999999999999670000000011021000000006', $result->chave);
        $this->assertNotSame('35220999999999999999550010000011081000000006', $result->chave);
    }

    public function test_marca_como_mascarado_o_documento_cujas_chaves_de_transporte_chegam_zeradas(): void
    {
        // Mesmo `cteOSProc` do `cte-os.xml`, com a chave da NF-e transportada
        // trocada pela forma de 44 dígitos iguais que o fisco usa para dizer
        // "esta referência não é sua" (a mesma forma aparece na NT de CT-e
        // 2025.001, na rejeição 933, com `chCTe` no lugar da chave de
        // transporte). A forma vem do próprio plano, que a descreve assim; a
        // regra aceita 44 dígitos iguais, e não um dígito só, porque é essa a
        // forma que o fisco usa.
        $result = (new FiscalXmlMetadata)->extract(
            $this->cteOsComTransporteZerado(),
            FiscalModel::Cte,
        );

        $this->assertTrue($result->mascarado);

        // Mascarado não muda a identidade: a chave continua sendo a do próprio
        // CT-e OS, e não uma chave de transporte — nem a zerada, nem nenhuma.
        $this->assertSame('35220999999999999999670000000011021000000006', $result->chave);
        $this->assertTrue(FiscalXmlMetadata::isValidChave($result->chave));
    }

    public function test_nao_marca_como_mascarado_o_documento_cujas_chaves_de_transporte_estao_legiveis(): void
    {
        // O contrário do caso acima, e ele precisa existir: marcar todo CT-e que
        // transporta NF-e transformaria a coluna em ruído e apagaria o achado
        // real.
        $result = (new FiscalXmlMetadata)->extract(
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-os.xml')),
            FiscalModel::Cte,
        );

        $this->assertFalse($result->mascarado);
    }

    public function test_um_documento_sem_nenhuma_chave_de_transporte_nao_e_mascarado(): void
    {
        $result = (new FiscalXmlMetadata)->extract(
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-gtve.xml')),
            FiscalModel::Cte,
        );

        $this->assertFalse($result->mascarado);
    }

    public function test_recusa_um_cte_entregue_ao_conector_de_nfe(): void
    {
        // A guarda do outro lado: aceitar a família de CT-e no conector de CT-e
        // não pode virar aceitar CT-e no conector de NF-e. A chave carrega o
        // modelo, e o modelo não é o do serviço que perguntou.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/cte-os.xml'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CT-e OS.*NF-e/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);
    }

    public function test_recusa_uma_nfe_entregue_ao_conector_de_cte(): void
    {
        // E o simétrico: o conector de CT-e não é o caminho da NF-e.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/NF-e.*CT-e/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);
    }

    public function test_recusa_uma_chave_de_cte_com_digito_verificador_invalido(): void
    {
        // O DV é conferido em todas as famílias, inclusive nas de CT-e: a chave
        // do `resCTe` abaixo é a do próprio documento com o último dígito
        // trocado, e o que fecha o módulo 11 é o DV.
        $xml = str_replace(
            '35220999999999999999570000000011011000000006',
            '35220999999999999999570000000011011000000007',
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-resumo.xml')),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dígito verificador inválido/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);
    }

    public function test_a_recusa_por_dv_nao_repete_a_chave_no_motivo_que_o_lote_reporta(): void
    {
        // A recusa que chega ao relatório é a `FailedEntry::reason`, e ela é uma
        // frase fixa que nomeia a etapa. A exceção interna carrega a chave — ela
        // existe para o diagnóstico, e é capturada e descartada no coletor — mas
        // o que é gravado, logado e mostrado ao operador não pode repetir uma
        // chave de acesso que não fecha o DV, porque essa chave não é a
        // identidade de documento nenhum e pareceria uma.
        $payload = base64_encode(gzcompress(str_replace(
            '35220999999999999999570000000011011000000006',
            '35220999999999999999570000000011011000000007',
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-resumo.xml')),
        )));

        $resultado = (new DfeEntryCollector(new DocZipDecoder, new FiscalXmlMetadata))->collect(
            new DfeResponse(
                cStat: '138',
                xMotivo: 'Documento localizado',
                ultNsu: 1,
                maxNsu: 1,
                entries: [new DfeEntry(nsu: 1, schema: 'resCTe_v1.00.xsd', payload: $payload)],
            ),
            FiscalModel::Cte,
        );

        $this->assertSame([], $resultado->documents);
        $this->assertCount(1, $resultado->failures);
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $resultado->failures[0]->reason);
        $this->assertStringNotContainsString('3522099999999999999957', $resultado->failures[0]->reason);
        $this->assertFalse($resultado->mayAdoptPosition);
    }

    public function test_recusa_uma_raiz_xml_fora_do_catalogo(): void
    {
        // Raiz que não é de nenhuma família conhecida: o documento não é
        // classificável, e classificá-lo por resemblance seria inventar
        // identidade. O nome da raiz abaixo é `loteDeDocumentos` de propósito —
        // ele não contém nenhuma palavra do motivo, para que a asserção seja
        // sobre a frase e não sobre o eco do nome.
        $xml = '<loteDeDocumentos xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<chCTe>35220999999999999999570000000011011000000006</chCTe>'
            .'</loteDeDocumentos>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Raiz de documento fora do catálogo/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);
    }

    public function test_uma_raiz_que_e_apenas_xml_legivel_nao_e_um_documento(): void
    {
        // O caso que a raiz do catálogo fecha: um XML bem formado, com uma chave
        // de acesso que fecha o DV e com o modelo certo, só não é um documento
        // fiscal. Sem a guarda de raiz, ele entraria no banco com a identidade
        // de um documento que não existe.
        $xml = '<loteDeDocumentos xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<chCTe>35220999999999999999570000000011011000000006</chCTe>'
            .'</loteDeDocumentos>';

        $this->expectException(RuntimeException::class);

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);
    }

    public function test_uma_raiz_desconhecida_vira_entrada_falha_e_nao_um_documento(): void
    {
        // O mesmo caso visto de onde a decisão é tomada: a entrada não vira
        // documento, o lote continua, e `mayAdoptPosition` falso impede que o
        // cursor passe por cima de uma posição que ninguém leu.
        $payload = base64_encode(gzcompress(
            '<loteDeDocumentos xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<chCTe>35220999999999999999570000000011011000000006</chCTe>'
            .'</loteDeDocumentos>',
        ));

        $resultado = (new DfeEntryCollector(new DocZipDecoder, new FiscalXmlMetadata))->collect(
            new DfeResponse(
                cStat: '138',
                xMotivo: 'Documento localizado',
                ultNsu: 200,
                maxNsu: 200,
                entries: [
                    new DfeEntry(nsu: 199, schema: 'desconhecido_v1.00.xsd', payload: $payload),
                    new DfeEntry(
                        nsu: 200,
                        schema: 'cteProc_v4.00.xsd',
                        payload: base64_encode(gzcompress(file_get_contents(base_path('tests/Fixtures/fiscal/cte-gtve.xml')))),
                    ),
                ],
            ),
            FiscalModel::Cte,
        );

        $this->assertCount(1, $resultado->documents, 'a entrada legível do mesmo lote continua');
        $this->assertSame(FiscalModel::Gtve, $resultado->documents[0]->model);
        $this->assertCount(1, $resultado->failures);
        $this->assertSame(199, $resultado->failures[0]->nsu);
        $this->assertSame('desconhecido_v1.00.xsd', $resultado->failures[0]->schema);

        // A razão é uma frase fixa: ela nomeia a etapa, e não repete o payload.
        $this->assertSame('FiscalXmlMetadata rejeitou o documento decodificado.', $resultado->failures[0]->reason);
        $this->assertFalse($resultado->mayAdoptPosition);
    }

    public function test_uma_raiz_conhecida_que_nao_e_documento_deixa_a_posicao_avancar(): void
    {
        // Pular e recusar são opostos, e a diferença é a posição. Uma inutilização
        // chega no meio do lote e o serviço a entrega sempre: recusada, ela faria
        // `mayAdoptPosition` falso para sempre, a consulta seguinte pediria o
        // mesmo intervalo e receberia a mesma inutilização — a posição daquele
        // cliente nunca mais andaria, e nada disso apareceria como erro.
        $payload = base64_encode(gzcompress(
            '<procInutCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<infInut><cUF>35</cUF><ano>22</ano><CNPJ>99999999999999</CNPJ>'
            .'<serie>000</serie><mod>57</mod><nCTIni>1</nCTIni><nCTFin>9</nCTFin></infInut>'
            .'</procInutCTe>',
        ));

        $resultado = (new DfeEntryCollector(new DocZipDecoder, new FiscalXmlMetadata))->collect(
            new DfeResponse(
                cStat: '138',
                xMotivo: 'Documento localizado',
                ultNsu: 200,
                maxNsu: 200,
                entries: [
                    new DfeEntry(nsu: 199, schema: 'procInutCTe_v4.00.xsd', payload: $payload),
                    new DfeEntry(
                        nsu: 200,
                        schema: 'cteProc_v4.00.xsd',
                        payload: base64_encode(gzcompress(file_get_contents(base_path('tests/Fixtures/fiscal/cte-gtve.xml')))),
                    ),
                ],
            ),
            FiscalModel::Cte,
        );

        // Nenhuma linha para a inutilização, nenhuma falha por ela, e a posição
        // adota: o buraco que impede a posição de andar é o buraco, e a
        // inutilização não é um.
        $this->assertSame([], $resultado->failures);
        $this->assertCount(1, $resultado->documents);
        $this->assertSame(200, $resultado->documents[0]->nsu);
        $this->assertSame(FiscalModel::Gtve, $resultado->documents[0]->model);
        $this->assertTrue($resultado->mayAdoptPosition);
    }

    public function test_uma_raiz_conhecida_que_nao_e_documento_e_recusada_como_pular_e_nao_como_falha(): void
    {
        // A exceção de "não é indexável" é capturada **antes** da `RuntimeException`
        // genérica. Se fosse capturada depois, viraria `FailedEntry` e a
        // posição travaria — que é o defeito que o tipo separado existe para
        // impedir, e que este teste pins.
        $this->expectException(NotIndexableDocument::class);

        (new FiscalXmlMetadata)->extract(
            '<procCancCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<infCanc><chCTe>35220999999999999999570000000011011000000006</chCTe></infCanc>'
            .'</procCancCTe>',
            FiscalModel::Cte,
        );
    }

    public function test_uma_raiz_desconhecida_nao_e_documento_e_continua_sendo_recusa_no_cte(): void
    {
        // O outro lado da regra, e ele vale: no conector que não está em produção,
        // uma raiz que o módulo não reconhece é recusa, porque recusar o que não
        // se consegue classificar é a escolha honesta e não tranca ninguém. O
        // que muda de um lado para o outro da linha é o conector, não a raiz.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Raiz de documento fora do catálogo/');

        (new FiscalXmlMetadata)->extract(
            '<loteDeDocumentos xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<chCTe>35220999999999999999570000000011011000000006</chCTe>'
            .'</loteDeDocumentos>',
            FiscalModel::Cte,
        );
    }

    public function test_o_mascarado_viaja_do_parser_para_o_documento_puxado(): void
    {
        // Parser e contrato, que é tudo que este arquivo é dono: o documento
        // puxado chega ao writer sabendo que o fisco mascarou as chaves
        // transportadas. O salto até a coluna gravada é do writer e está em
        // `FiscalDocumentWriterTest::test_stores_the_masked_flag_the_parser_observed`
        // — o caminho inteiro, do `docZip` à linha persistida.
        $resultado = (new DfeEntryCollector(new DocZipDecoder, new FiscalXmlMetadata))->collect(
            new DfeResponse(
                cStat: '138',
                xMotivo: 'Documento localizado',
                ultNsu: 1,
                maxNsu: 1,
                entries: [new DfeEntry(
                    nsu: 1,
                    schema: 'procCTeOS_v4.00.xsd',
                    payload: base64_encode(gzcompress($this->cteOsComTransporteZerado())),
                )],
            ),
            FiscalModel::Cte,
        );

        $this->assertCount(1, $resultado->documents);
        $this->assertTrue($resultado->documents[0]->mascarado);
        $this->assertSame('35220999999999999999670000000011021000000006', $resultado->documents[0]->chave);
    }

    /**
     * O `cte-os.xml` com a chave da NF-e transportada trocada pela forma de 44
     * dígitos iguais. Montado aqui — e não em arquivo — porque é o `cte-os.xml`
     * com um valor trocado, e um segundo arquivo que só difere em 44 dígitos
     * seria uma fonte de verdade a mais para divergir.
     */
    private function cteOsComTransporteZerado(): string
    {
        return str_replace(
            '35220999999999999999550010000011081000000006',
            str_repeat('9', 44),
            file_get_contents(base_path('tests/Fixtures/fiscal/cte-os.xml')),
        );
    }
}
