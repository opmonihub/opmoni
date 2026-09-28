<?php

namespace Tests\Unit;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use App\Services\Fiscal\Support\DigValComparison;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use RuntimeException;
use Tests\TestCase;

class FiscalXmlMetadataTest extends TestCase
{
    public function test_accepts_a_valid_access_key(): void
    {
        // Chave com o DV 0 que o módulo 11 exige: soma 980, resto 1.
        $this->assertTrue(FiscalXmlMetadata::isValidChave('35220499999999999999550010020000001240556600'));
    }

    public function test_rejects_wrong_check_digit(): void
    {
        $this->assertFalse(FiscalXmlMetadata::isValidChave('35220499999999999999550010020000001240556603'));
    }

    public function test_rejects_wrong_length_and_non_digits(): void
    {
        $this->assertFalse(FiscalXmlMetadata::isValidChave('123'));
        $this->assertFalse(FiscalXmlMetadata::isValidChave(str_repeat('A', 44)));
    }

    public function test_extracts_summary_metadata(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalModel::Nfe, $result->model);
        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('710.00', $result->valorTotal);
        $this->assertNotNull($result->emissaoAt);
        $this->assertSame('', $result->eventId);
    }

    public function test_extracts_authorized_document_metadata(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('11222333000181', $result->destinatarioCnpj);
    }

    public function test_extracts_the_digest_from_the_summary(): void
    {
        // No resumo o `digVal` está no topo: é o resumo do XML que o ambiente
        // nacional calculou quando catalogou a nota.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame('i2rqNaD6rqmCfhXHyTBf4xe1ImQ=', $result->digVal);
    }

    public function test_extracts_the_digest_from_the_authorized_document(): void
    {
        // No documento completo o `digVal` vem de `protNFe/infProt` — o protocolo
        // de autorização — e é o mesmo valor que o resumo traz. A igualdade dos
        // dois é a verificação de integridade do módulo, sem uma linha de
        // cripto.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame('i2rqNaD6rqmCfhXHyTBf4xe1ImQ=', $result->digVal);
    }

    public function test_the_two_stages_of_one_document_carry_the_same_digest(): void
    {
        // Par sintético, e o módulo avisa disso: os fixtures são
        // estruturalmente realistas, não capturas do ambiente nacional. Um par
        // `resNFe` + `procNFe` de verdade, e um par deliberadamente divergente,
        // têm de vir do serviço antes de produção.
        $resumo = (new FiscalXmlMetadata)->extract(
            file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml')),
            FiscalModel::Nfe,
        );

        $documento = (new FiscalXmlMetadata)->extract(
            file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml')),
            FiscalModel::Nfe,
        );

        $this->assertNotNull($resumo->digVal);
        $this->assertSame($resumo->digVal, $documento->digVal);
        $this->assertTrue(DigValComparison::compare($resumo->digVal, $documento->digVal));
    }

    public function test_the_summary_and_the_authorized_document_are_different_stages(): void
    {
        // As duas etapas de um mesmo documento, e a razão de `stage` existir: com
        // `event_id` vazio nas duas, a chave composta guardava o resumo e o
        // documento completo na mesma linha, e o segundo apagava o XML, a posição
        // e o digest do primeiro. A spec exige duas linhas.
        $resumo = (new FiscalXmlMetadata)->extract(file_get_contents(base_path('tests/Fixtures/fiscal/resNFe.xml')), FiscalModel::Nfe);
        $documento = (new FiscalXmlMetadata)->extract(file_get_contents(base_path('tests/Fixtures/fiscal/procNFe.xml')), FiscalModel::Nfe);

        $this->assertSame(FiscalStage::Summary, $resumo->stage);
        $this->assertSame(FiscalStage::Document, $documento->stage);

        // Continua sendo o mesmo documento: mesmo tipo, mesma chave, e
        // `event_id` vazio nas duas — a diferença é a etapa, não a identidade.
        $this->assertSame(FiscalKind::Document, $resumo->kind);
        $this->assertSame(FiscalKind::Document, $documento->kind);
        $this->assertSame($resumo->chave, $documento->chave);
        $this->assertSame('', $resumo->eventId);
        $this->assertSame('', $documento->eventId);
    }

    public function test_an_event_is_its_own_stage(): void
    {
        $xml = $this->evento('ID1101113522049999999999999955001002000000124055660001', '1');

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalStage::Event, $result->stage);
        $this->assertSame(FiscalKind::Event, $result->kind);
        $this->assertSame('110111-1', $result->eventId);
    }

    public function test_an_nfce_summary_is_also_a_summary(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe_nfce.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfce);

        // A etapa vem do formato do XML — o resumo não traz o protocolo de
        // autorização — e não do modelo: uma NFC-e chega pela distribuição do
        // mesmo jeito que uma NF-e.
        $this->assertSame(FiscalStage::Summary, $result->stage);
    }

    public function test_a_document_without_a_digest_extracts_none(): void
    {
        // Evento não tem `digVal` em lugar nenhum, e a coluna nullable espera
        // nulo — não string vazia, que o writer leria como digest.
        $xml = $this->evento('ID1101113522049999999999999955001002000000124055660001', '1');

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertNull($result->digVal);
    }

    public function test_follows_the_model_path_instead_of_any_matching_element(): void
    {
        // No CT-e o destinatário é `<toma>` e o valor é `vTPrest`: um CNPJ
        // qualquer, ou um `vNF` que não existe, não podem satisfazer a
        // extração.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/cteProc.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Cte);

        $this->assertSame(FiscalModel::Cte, $result->model);
        $this->assertSame('35220799999999999999570010000001231123456786', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('11222333000181', $result->destinatarioCnpj);
        $this->assertSame('1500.00', $result->valorTotal);
    }

    public function test_extracts_event_identity(): void
    {
        $xml = <<<'XML'
        <procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe">
          <evento versao="1.00">
            <infEvento Id="ID1101113522049999999999999955001002000000124055660001">
              <tpEvento>110111</tpEvento>
              <nSeqEvento>1</nSeqEvento>
              <dhEvento>2022-04-04T11:54:49-03:00</dhEvento>
            </infEvento>
          </evento>
        </procEventoNFe>
        XML;

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        $this->assertSame(FiscalKind::Event, $result->kind);
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('110111-1', $result->eventId);
        $this->assertNotNull($result->eventoOcorridoEmAt);
    }

    public function test_anchors_the_key_on_the_sequence_and_not_on_a_neighbouring_window(): void
    {
        // Cada `Id` foi construído para que a janela de 44 dígitos um posição à
        // direita da verdadeira também feche o DV e leia um modelo válido — a
        // série começa em 55, e a última posição da janela vizinha é o
        // primeiro dígito da sequência. Uma varredura "tente as janelas até uma
        // fechar" devolve a vizinha, e o documento entra no banco com uma
        // identidade que não existe. As três primeiras asserções descrevem a
        // armadilha e falham se alguém mudar a construção; a última é o
        // contrato.
        $casos = [
            'ID com CNPJ e sequência de um algarismo' => [
                'id' => 'ID11011199999999999999352204999999999999995555100000004211234567882',
                'nSeqEvento' => '2',
                'chave' => '35220499999999999999555510000000421123456788',
                'prefixo' => '11011199999999999999',
            ],
            'ID sem CNPJ e sequência de um algarismo' => [
                'id' => 'ID110111352204999999999999995555100000004211234567882',
                'nSeqEvento' => '2',
                'chave' => '35220499999999999999555510000000421123456788',
                'prefixo' => '110111',
            ],
            'ID com CNPJ e sequência de dois algarismos' => [
                'id' => 'ID110111999999999999993522049999999999999955558000000042112345678210',
                'nSeqEvento' => '10',
                'chave' => '35220499999999999999555580000000421123456782',
                'prefixo' => '11011199999999999999',
            ],
        ];

        $extractor = new FiscalXmlMetadata;

        foreach ($casos as $rotulo => $caso) {
            $janela = substr($caso['id'], 2 + strlen($caso['prefixo']) + 1, 44);

            $this->assertTrue(FiscalXmlMetadata::isValidChave($caso['chave']), "chave real de {$rotulo}");
            $this->assertNotSame($caso['chave'], $janela);
            $this->assertTrue(FiscalXmlMetadata::isValidChave($janela), "janela vizinha de {$rotulo} também fecha");
            $this->assertSame('55', substr($janela, 20, 2), "janela vizinha de {$rotulo} também tem modelo legível");

            $result = $extractor->extract($this->evento($caso['id'], $caso['nSeqEvento']), FiscalModel::Nfe);

            $this->assertSame($caso['chave'], $result->chave, "chave extraída de {$rotulo}");
        }
    }

    public function test_extracts_the_key_from_generated_event_ids_in_both_published_layouts(): void
    {
        // A classe do defeito é rara — a janela vizinha só passa no DV em
        // ~1,3% das vezes — então a amostra é grande e o sweep é fixo: a
        // mesma sequência de chaves roda em toda execução.
        $extractor = new FiscalXmlMetadata;
        $divergencias = [];
        $ufs = [11, 12, 13, 14, 15, 16, 17, 21, 22, 23, 24, 25, 26, 27, 28, 29, 31, 32, 33, 35, 41, 42, 43, 50, 51, 52, 53];

        mt_srand(20260927);

        foreach ([true, false] as $comCnpj) {
            for ($n = 0; $n < 2000; $n++) {
                $chave = $this->chaveDeTeste(
                    uf: (string) $ufs[mt_rand(0, count($ufs) - 1)],
                    anoMes: sprintf('%02d%02d', mt_rand(0, 4), 1 + mt_rand(0, 11)),
                    cnpj: (string) mt_rand(1, 99999999999999),
                    mod: '55',
                    serie: (string) mt_rand(0, 999),
                    nNf: (string) mt_rand(1, 999999999),
                    cNf: (string) mt_rand(1, 99999999),
                );
                $nSeq = (string) (1 + mt_rand(0, 99));
                $cnpj = substr($chave, 6, 14);
                $id = 'ID110111'.($comCnpj ? $cnpj : '').$chave.$nSeq;

                try {
                    $result = $extractor->extract($this->evento($id, $nSeq), FiscalModel::Nfe);
                } catch (RuntimeException $e) {
                    // Recusar também é identidade errada: o evento existia e
                    // tinha chave boa.
                    $divergencias[] = sprintf('%s: recusado (%s)', substr($id, 0, 32), $e->getMessage());

                    continue;
                }

                if ($result->chave !== $chave) {
                    $divergencias[] = sprintf('%s: queria %s, veio %s', substr($id, 0, 32), $chave, $result->chave);
                }
            }
        }

        $this->assertSame([], $divergencias, sprintf('%d chaves de evento com a identidade errada', count($divergencias)));
    }

    public function test_rejects_a_document_whose_access_key_does_not_close(): void
    {
        // O DV é o alarme de corrupção de identidade: um `chNFe` com o dígito
        // trocado é um documento que não pode ser gravado sob nenhuma chave.
        $xml = <<<'XML'
        <resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <chNFe>35220499999999999999550010020000001240556603</chNFe>
          <CNPJ>99999999999999</CNPJ>
          <dhEmi>2022-04-04T11:54:49-03:00</dhEmi>
          <vNF>710.00</vNF>
        </resNFe>
        XML;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dígito verificador inválido/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);
    }

    public function test_rejects_a_document_whose_model_is_not_the_expected_one(): void
    {
        // Um `cteProc` entregue ao conector da NF-e é um documento real com
        // etiqueta errada: a chave é de outro documento, então a unicidade de
        // `(client_id, chave_acesso, event_id)` não a impediria de entrar.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/cteProc.xml'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CT-e.*NF-e/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);
    }

    public function test_rejects_a_key_whose_model_is_outside_the_catalogue(): void
    {
        $xml = <<<'XML'
        <resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <chNFe>35260911222333000181610010000077881556677880</chNFe>
          <CNPJ>11222333000181</CNPJ>
          <dhEmi>2026-09-27T10:00:00-03:00</dhEmi>
        </resNFe>
        XML;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/fora do catálogo \(61\)/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);
    }

    public function test_extracts_an_nfe_whose_root_is_outside_the_root_catalogue(): void
    {
        // A regressão do caminho que está em produção. O catálogo de raízes foi
        // escrito contra o pacote do CT-e e este checkout não tem nenhum schema
        // que enumere as raízes que a distribuição de NF-e pode entregar, então
        // a raiz desconhecida **não recusa** aqui: o NF-e volta a se comportar
        // como antes, extraindo a primeira `chNFe` que encontrar.
        //
        // A recusa aqui não seria um bug de parse. `DfeEntryCollector` adota a
        // posição só quando não houve falha, e a mesma entrada voltaria na
        // consulta seguinte, e na seguinte: uma raiz de NF-e fora do catálogo
        // levaria cada cliente que a tivesse em faixa para uma posição que nunca
        // mais avança, sem nenhum erro que dissesse isso.
        $xml = <<<'XML'
        <entregaDeDocumento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
          <chNFe>35220499999999999999550010020000001240556600</chNFe>
          <CNPJ>99999999999999</CNPJ>
          <dhEmi>2022-04-04T11:54:49-03:00</dhEmi>
          <vNF>710.00</vNF>
        </entregaDeDocumento>
        XML;

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);

        // O nome da raiz segue no `schema`, que é o que a coluna guarda, e a
        // etapa sai da ausência de protocolo — o mesmo de sempre.
        $this->assertSame('35220499999999999999550010020000001240556600', $result->chave);
        $this->assertSame('99999999999999', $result->emitenteCnpj);
        $this->assertSame('710.00', $result->valorTotal);
        $this->assertSame('entregaDeDocumento', $result->schema);
        $this->assertSame(FiscalStage::Summary, $result->stage);
    }

    public function test_the_model_guard_still_refuses_a_cte_on_a_root_the_catalogue_knows(): void
    {
        // O outro lado da mesma distinção: no NF-e a raiz desconhecida não recusa,
        // mas a guarda de modelo continua valendo e é independente da raiz. O
        // `cteProc` é recusado pelo modelo — não pela raiz, que aqui não tem
        // nada a dizer.
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/cteProc.xml'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CT-e.*NF-e/');

        (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfe);
    }

    public function test_extracts_metadata_from_an_nfce(): void
    {
        $xml = file_get_contents(base_path('tests/Fixtures/fiscal/resNFe_nfce.xml'));

        $result = (new FiscalXmlMetadata)->extract($xml, FiscalModel::Nfce);

        $this->assertSame(FiscalModel::Nfce, $result->model);
        $this->assertSame(FiscalKind::Document, $result->kind);
        $this->assertSame('35260911222333000181650010000077881556677885', $result->chave);
        $this->assertSame('11222333000181', $result->emitenteCnpj);
        $this->assertSame('95.40', $result->valorTotal);
        $this->assertNotNull($result->emissaoAt);
        $this->assertSame('', $result->eventId);
    }

    private function evento(string $id, string $nSeqEvento): string
    {
        return '<procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<evento versao="1.00"><infEvento Id="'.$id.'">'
            .'<tpEvento>110111</tpEvento>'
            .'<nSeqEvento>'.$nSeqEvento.'</nSeqEvento>'
            .'<dhEvento>2026-09-27T10:00:00-03:00</dhEvento>'
            .'</infEvento></evento></procEventoNFe>';
    }

    /**
     * Monta uma chave de 44 dígitos com DV válido, espelhando o módulo 11 da
     * NT por conta própria: um gerador que chamasse o método que está sob
     * teste só provaria que ele é consistente com ele mesmo.
     */
    private function chaveDeTeste(string $uf, string $anoMes, string $cnpj, string $mod, string $serie, string $nNf, string $cNf): string
    {
        $base = $uf.$anoMes.str_pad($cnpj, 14, '0', STR_PAD_LEFT)
            .$mod.str_pad($serie, 3, '0', STR_PAD_LEFT)
            .str_pad($nNf, 9, '0', STR_PAD_LEFT).'1'.str_pad($cNf, 8, '0', STR_PAD_LEFT);

        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;

        for ($i = 42, $w = 0; $i >= 0; $i--, $w++) {
            $sum += ((int) $base[$i]) * $weights[$w % 8];
        }

        $resto = $sum % 11;

        return $base.($resto < 2 ? '0' : 11 - $resto);
    }
}
