<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalStage;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use Throwable;

final class FiscalXmlMetadata
{
    /**
     * Tamanho do prefixo que o `Id` de um evento carrega antes da chave: `ID` +
     * `tpEvento` (6 dígitos), com ou sem o CNPJ do emitente (14 dígitos) no
     * meio. Nenhuma das duas layouts é assumida — as duas são conferidas
     * contra o `nSeqEvento` do próprio documento.
     */
    private const ID_PREFIX_LENGTHS = [6, 20];

    /**
     * Largura máxima do campo `nSeqEvento` dentro do `Id`, pela tipagem da NT.
     * É o que separa as duas layouts: na de 52 dígitos a sequência ocupa 1 ou
     * 2 posições, e na de 66 o prefixo sem o CNPJ deixaria uma sobra de 16.
     */
    private const ID_SEQUENCE_MAX_LENGTH = 10;

    /**
     * As raízes de documento que a distribuição entrega, e o **caminho da chave
     * de acesso do próprio documento** em cada uma.
     *
     * A raiz é conferida, e não o `schema` que o serviço declarou no `docZip`:
     * esse atributo é texto do fisco que não fez validação de nada, e aceitar
     * um `schema` como classificação trocaria "o serviço disse que é isso" por
     * "isto é o que o XML é". Os dois podem divergir — o `leiauteDistCTe`
     * publicado mostra entradas com `procComp` e sem ele na mesma lista — e só
     * a raiz descreve a forma do payload.
     *
     * Nenhum caminho aqui atravessa `infDoc` ou `infDocAnt`, que é onde vivem as
     * chaves das NF-e transportadas e dos CT-e anteriores. Isso não é
     * coincidência de ordenação de nós: um documento de transporte tem a chave
     * alheia antes da própria em ordem de percurso, e uma extração que
     * procurasse "a primeira `chave` que aparecer" pegaria a referência de outro
     * documento — que é a identidade de um documento que não é este.
     *
     * Os nomes de raiz são os dos XSDs publicados do pacote `PRCTE`, mais o
     * `resCTe`, que é o nome de resumo da distribuição nacional de CT-e. O
     * `GTVeProc` é o elemento raiz de `procGTVe_v4.00.xsd` — nome de arquivo e
     * nome de raiz não são o mesmo nome, e aqui importa o segundo.
     *
     * @var array<string, list<string>>
     */
    private const CHAVE_PROPRIA = [
        // NF-e e NFC-e: mesma raiz, chaves de modelo `55` e `65`.
        'resNFe' => ['chNFe'],
        'procNFe' => ['infNFe/chNFe', 'protNFe/infProt/chNFe'],
        'procEventoNFe' => ['infEvento/chNFe', 'protNFe/infProt/chNFe'],
        // CT-e: três famílias processadas, resumo e evento. `cteSimpProc` é
        // modelo `57` como o CT-e regular, e é por isso que o modelo não pode
        // sair do nome da raiz.
        'resCTe' => ['chCTe'],
        'cteProc' => ['infCte/chCTe', 'protCTe/infProt/chCTe'],
        'cteSimpProc' => ['infCte/chCTe', 'protCTe/infProt/chCTe'],
        'cteOSProc' => ['infCte/chCTe', 'protCTe/infProt/chCTe'],
        'GTVeProc' => ['infCte/chCTe', 'protCTe/infProt/chCTe'],
        'procEventoCTe' => ['infEvento/chCTe'],
    ];

    /**
     * Onde a chave de um **documento transportado** aparece no CT-e, e por que
     * ela é lida mesmo sem ser usada como identidade.
     *
     * `infDoc/infNFe/chave` e `infDocAnt/infNFeTranspParcial/chNFe` são as duas
     * posições do schema publicado (`cteTiposBasico_v4.00.xsd`), e o nome do
     * elemento difere entre elas — o que é uma razão a mais para a detecção de
     * mascaramento não depender de um único caminho.
     *
     * Ler essas chaves é o que permite dizer que o documento é **mascarado**:
     * quem consulta por `autXML` recebe as referências de NF-e transportada
     * substituídas por uma forma que não é chave de acesso nenhuma, e a
     * deduplicação por `chave_acesso` colidiria se elas fossem indexadas.
     */
    private const CHAVES_TRANSPORTADAS = [
        'infDoc/infNFe/chave',
        'infDocAnt/infNFeTranspParcial/chNFe',
        'infDocAnt/infNFeTranspParcial/chave',
    ];

    /**
     * As raízes que a distribuição entrega e que **não** são um documento a
     * indexar. A entrada é pulada, e a posição avança.
     *
     * `procInutCTe` é a inutilização e `procCancCTe` o cancelamento antigo —
     * os dois são entradas reais da distribuição de CT-e (`procInutCTe` é a raiz
     * de `procInutCTe_v4.00.xsd`; os dois aparecem como tipo de entrada no
     * `leiauteLoteRFBCTe_v1.00.xsd`). Nenhum dos dois tem chave de acesso de
     * documento, e nenhum é algo que este módulo gravaria.
     *
     * Estão aqui, e não como recusa, por causa de `DfeEntryCollector`:
     * `mayAdoptPosition` é falso quando há recusa, então recusar aqui travaria a
     * posição do cliente no primeiro `inut` do lote, para sempre. Uma entrada
     * que não é documento não é um buraco — não havia documento nela.
     *
     * A lista é do CT-e e só do CT-e: o catálogo do NF-e vive fora deste
     * checkout e esta tarefa não tem como fechar uma lista de raízes de NF-e
     * sem inventar, e uma lista inventada seria pior do que a ausência dela —
     * ver `CHAVE_NA_RAIZ_DESCONHECIDA`.
     *
     * @var list<string>
     */
    private const NAO_DOCUMENTO = [
        'procInutCTe',
        'procCancCTe',
    ];

    /**
     * O comportamento de antes do catálogo de raízes, preservado de propósito no
     * caminho de NF-e: a primeira `chNFe` ou `chCTe` que aparecer em qualquer
     * lugar do documento.
     *
     * O conector de NF-e está em produção desde o plano anterior, e o catálogo
     * desta tarefa foi escrito contra o pacote `PRCTE` do SVRS — que é o do
     * CT-e. **Este checkout não tem nenhum schema que enumere as raízes que a
     * distribuição de NF-e pode entregar**: o único XSD de NF-e versionado aqui
     * (`tiposDistDFe_v1.01.xsd`) não declara elemento raiz nenhum. Então não há
     * como afirmar que o catálogo de raízes cobre o NF-e, e uma raiz de NF-e que
     * ele não cobrisse viraria uma recusa permanente numa posição de produção.
     *
     * No caminho de CT-e — que não está em produção e em que recusar o que não se
     * consegue classificar é a escolha honesta — a raiz desconhecida é recusa.
     *
     * Este é o lado permissivo do catálogo, e ele é exclusivo do NF-e: no CT-e a
     * chave seria lida de um documento cuja forma ninguém classificou, e é
     * exatamente ali que uma chave de transporte poderia ser lida como identidade.
     */
    private const CHAVE_NA_RAIZ_DESCONHECIDA = ['chNFe', 'chCTe'];

    public function extract(string $xml, FiscalModel $model): FiscalXmlMetadataResult
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('O documento capturado não é um XML legível.');
        }

        $xpath = new DOMXPath($dom);

        // O nome do elemento raiz, que é o `schema` que a coluna vai guardar e
        // também o que classifica a forma do payload.
        $schema = $this->schemaOf($dom);

        // Raiz conhecida e não indexável: a entrada é pulada e a posição avança.
        // Ver `NotIndexableDocument` para por que isto não pode ser uma recusa.
        if (in_array($schema, self::NAO_DOCUMENTO, true)) {
            throw new NotIndexableDocument($schema);
        }

        $paths = $this->pathsOf($schema, $model);

        $tpEvento = $this->firstText($xpath, ['tpEvento']);
        $nSeqEvento = $this->firstText($xpath, ['nSeqEvento']);

        $chave = $this->firstText($xpath, $paths)
            ?? $this->chaveFromId($xpath, $nSeqEvento)
            ?? throw new RuntimeException('O documento capturado não expõe chave de acesso.');

        if (! self::isValidChave($chave)) {
            throw new RuntimeException("Chave de acesso com dígito verificador inválido: {$chave}.");
        }

        // O modelo que volta é o da chave do próprio documento, e não o que o
        // conector pediu: o conector de CT-e entrega três modelos no mesmo lote
        // — CT-e regular e simplificado (`57`), CT-e OS (`67`) e GTV-e (`64`) —
        // e o que os distingue é a chave, não a pergunta.
        $model = $this->guardModel($chave, $model);

        $isEvent = $tpEvento !== null;

        return new FiscalXmlMetadataResult(
            chave: $chave,
            model: $model,
            kind: $isEvent ? FiscalKind::Event : FiscalKind::Document,
            stage: $this->stageOf($xpath, $isEvent),
            eventId: $isEvent ? $tpEvento.'-'.($nSeqEvento ?? '1') : '',
            schema: $schema,
            emitenteCnpj: $this->firstText($xpath, ['emit/CNPJ', 'prest/CNPJ', 'CNPJ']),
            destinatarioCnpj: $this->firstText($xpath, ['dest/CNPJ', 'toma/CNPJ', 'destinatario/CNPJ']),
            valorTotal: $this->firstText($xpath, ['vNF', 'vTPrest', 'vLiq']),
            // O mesmo digest nas duas etapas da distribuição, em lugares
            // diferentes: o resumo o traz no topo, o documento autorizado no
            // protocolo. O caminho específico vem primeiro porque é o protocolo
            // que o ambiente nacional escreveu, e o topo é o que sobra para o
            // resumo. Evento não tem digest, e aí a coluna fica nula.
            digVal: $this->firstText($xpath, ['protNFe/infProt/digVal', 'protCTe/infProt/digVal', 'digVal']),
            emissaoAt: $this->toDate($this->firstText($xpath, ['dhEmi', 'dhRecbto'])),
            eventoOcorridoEmAt: $this->toDate($this->firstText($xpath, ['dhEvento'])),
            mascarado: $this->isMascarado($xpath),
            // O número e a série do `ide`: o resumo e o evento não têm `ide`,
            // e aí a coluna fica nula — nunca um número inventado.
            numero: $this->firstText($xpath, ['ide/nNF', 'ide/nCT']),
            serie: $this->firstText($xpath, ['ide/serie']),
        );
    }

    /**
     * O conector em que uma raiz fora do catálogo é **recusa**, e não apenas uma
     * classificação que não encontrou o caminho da chave.
     *
     * É o conector de CT-e porque recusar é a escolha honesta onde ainda não há
     * tráfego: o catálogo foi escrito contra o pacote publicado do CT-e, e uma
     * recusa é a afirmação de que existe uma raiz que este código não conhece —
     * o que é verdade. No NF-e, que está em produção, a mesma recusa viraria uma
     * trava permanente para quem a encontrasse, e o checkout não tem schema que
     * a sustente. Ver `pathsOf()` e `CHAVE_NA_RAIZ_DESCONHECIDA`.
     *
     * ⚠️ A justificativa que esta escolha carregava — "recusar aqui é inofensivo
     * porque ainda não há posição de cliente em jogo" — **não** é mais o que
     * segura a escolha, e quem lê este bloco para decidir se o catálogo deve
     * crescer precisa saber disso. O que é verdade hoje é mais estreito: o CT-e
     * não está em produção, e é por isso que um cliente real ainda não tem
     * posição em jogo. No instante em que o canário é autorizado — que é o
     * primeiro passo do gate de liberação — a frase deixa de valer, e o binding é
     * do gate, não de um comentário.
     *
     * O que a recusa compra, e o que ela custa, estão no caminho do
     * esgotamento da lacuna: recusar mantém a evidência (a linha em
     * `fiscal_gaps`, o `attempts`, o `last_error` que vira `gap_abandoned`) e
     * custa três ciclos de consulta; ao fim deles a posição anda por cima da
     * posição recusada, como a rejeição faria. Ou seja: recusar deixa **mais**
     * para trás, e pular e avançar deixaria nada. Nenhum dos dois desfechos
     * recupera o documento, e a diferença entre eles é a única coisa que este
     * catálogo tem a oferecer hoje.
     */
    private const RAIZ_E_RECUSA = FiscalModel::Cte;

    /**
     * Onde a chave do próprio documento está, dada a raiz e o conector que
     * perguntou.
     *
     * A raiz do catálogo resolve o caso comum. A raiz de fora do catálogo tem dois
     * desfechos, e a diferença entre eles é o que separa uma classificação de uma
     * trava:
     *
     * - **No CT-e, que não está em produção**, a raiz desconhecida é recusa. O
     *   catálogo foi escrito contra o pacote publicado do CT-e, recusar o que não
     *   se consegue classificar é a escolha honesta, e a recusa mantém a
     *   evidência da posição enquanto custa três ciclos de consulta — ao fim dos
     *   quais a posição anda do mesmo jeito que andaria com a rejeição. O que a
     *   recusa compra e o que ela custa estão em `RAIZ_E_RECUSA`, inclusive o
     *   fato de que a justificativa "ninguém tem posição em jogo" vale só
     *   enquanto o canário não for autorizado.
     * - **No NF-e, que está em produção**, a raiz desconhecida não recusa: o
     *   comportamento de antes do catálogo é preservado. Este checkout não tem
     *   schema que enumere as raízes que a distribuição de NF-e entrega, então uma
     *   recusa aqui seria uma afirmação que nada sustenta — e, por
     *   `mayAdoptPosition`, uma afirmação que tranca a posição de um cliente que
     *   hoje avança. Ver `CHAVE_NA_RAIZ_DESCONHECIDA`.
     *
     * A guarda de modelo continua valendo nos dois caminhos e independentemente da
     * raiz: uma `chCTe` entregue ao conector de NF-e é recusada pelo modelo, não
     * pela raiz.
     *
     * @return list<string>
     */
    private function pathsOf(string $root, FiscalModel $expected): array
    {
        $paths = self::CHAVE_PROPRIA[$root] ?? null;

        if ($paths !== null) {
            return $paths;
        }

        if (in_array($expected, self::RAIZ_E_RECUSA->family(), true)) {
            throw new RuntimeException("Raiz de documento fora do catálogo: {$root}.");
        }

        return self::CHAVE_NA_RAIZ_DESCONHECIDA;
    }

    /**
     * O documento chegou com as chaves dos documentos que ele transporta
     * substituídas por uma forma que não é chave de acesso de ninguém.
     *
     * A forma é 44 dígitos iguais: é assim que o fisco diz "esta referência não
     * é sua" para quem consulta por `autXML`, e é a mesma que a rejeição 933 da
     * NT de CT-e 2025.001 mostra para uma `chCTe` indisponível. O `design.md`
     * chama de "zeradas" e o fixture do plano escreve noves; as duas formas
     * caem na mesma regra, e a regra é o preenchimento repetido — que nenhuma
     * chave real tem, porque nenhum dos 44 dígitos de uma chave real é livre.
     *
     * A regra é o preenchimento repetido e não "a chave não fecha o DV", e a
     * escolha está pinsada em `CteXmlMetadataTest`: as duas regras divergem nos
     * dois sentidos e a suíte pega as duas. Uma referência transportada com o DV
     * trocado e 44 dígitos não repetidos **não** é mascaramento — é um defeito
     * no documento, e o painel precisa poder dizer as duas coisas. E 44 zeros,
     * a forma que a palavra do `design.md` aponta, **é** mascaramento mesmo
     * fechando o DV, porque é a única das dez repetições que fecha. Trocar esta
     * regra pela do DV deixa os testes verdes só se os dois casos sumirem junto.
     *
     * E nenhuma das duas coisas muda a identidade: `chave` é a do próprio
     * documento, lida antes daqui e por outro caminho.
     */
    private function isMascarado(DOMXPath $xpath): bool
    {
        foreach (self::CHAVES_TRANSPORTADAS as $path) {
            $value = $this->firstText($xpath, [$path]);

            if ($value !== null && self::isRepeatedDigits($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 44 dígitos iguais — a largura de uma chave de acesso e a forma de
     * preenchimento do fisco. A largura entra na regra porque é ela que separa
     * isto de qualquer trecho curto de dígitos iguais, que o documento tem em
     * série, número e código aleatório, e que não é preenchimento do fisco.
     */
    private static function isRepeatedDigits(string $value): bool
    {
        return preg_match('/^(\d)\1{43}$/', $value) === 1;
    }

    /**
     * A etapa sai do formato do XML, e não do modelo nem da posição: o resumo é
     * a entrega que traz os campos do documento sem o XML dele, e o que
     * distingue uma do outro é o protocolo de autorização. Um `resNFe` é resumo
     * porque não tem `protNFe`, um `procNFe` é documento completo porque tem.
     *
     * A ordem importa: um evento não tem protocolo, e classificá-lo pelo
     * `tpEvento` primeiro é o que impede que ele caia na etapa de documento.
     */
    private function stageOf(DOMXPath $xpath, bool $isEvent): FiscalStage
    {
        if ($isEvent) {
            return FiscalStage::Event;
        }

        $isAuthorised = XmlQuery::first($xpath, 'protNFe/infProt') !== null
            || XmlQuery::first($xpath, 'protCTe/infProt') !== null;

        return $isAuthorised ? FiscalStage::Document : FiscalStage::Summary;
    }

    /**
     * A chave carrega o modelo do documento nas posições 21-22, então ele sai
     * dali e não de quem chamou — e é o que volta para o chamador. Um `resCTe`
     * entregue ao conector da NF-e é um documento real e uma etiqueta errada: a
     * unicidade de `(client_id, chave_acesso, event_id)` não o protegeria, porque
     * a chave é de outro documento e entraria sem conflito. Recusar aqui é o que
     * impede que ele seja gravado; a decisão de pular o documento em vez de
     * falhar o lote é do conector, e este erro é nomeado para que ele possa
     * classificá-lo.
     *
     * O que se aceita é a **família** do modelo pedido, e não o modelo: o
     * conector de CT-e entrega CT-e regular, CT-e OS, CT-e simplificado e
     * GTV-e no mesmo lote, e recusar três deles porque o chamador disse `Cte`
     * seria recusar a família inteira por causa de um membro. O que continua
     * recusado é o que está fora dela — e o `default => null` do catálogo
     * acima é o que recusa o que ninguém nomeou, sem que o `null` vire um
     * modelo de reserva em qualquer ponto deste arquivo.
     *
     * @return FiscalModel o modelo que a chave do próprio documento carrega
     */
    private function guardModel(string $chave, FiscalModel $expected): FiscalModel
    {
        $code = substr($chave, 20, 2);
        $found = FiscalModel::fromDocumentModel($code);

        if ($found === null) {
            throw new RuntimeException("Chave de acesso com modelo fora do catálogo ({$code}): {$chave}.");
        }

        if (! in_array($found, $expected->family(), true)) {
            throw new RuntimeException("Chave de acesso de {$found->label()} onde se esperava {$expected->label()}: {$chave}.");
        }

        return $found;
    }

    /**
     * Dígito verificador módulo 11 sobre os 43 primeiros dígitos, pesos
     * cíclicos de 2 a 9 da direita para a esquerda.
     */
    public static function isValidChave(string $chave): bool
    {
        if (preg_match('/^\d{44}$/', $chave) !== 1) {
            return false;
        }

        $weights = [2, 3, 4, 5, 6, 7, 8, 9];
        $sum = 0;

        for ($i = 42, $w = 0; $i >= 0; $i--, $w++) {
            $sum += ((int) $chave[$i]) * $weights[$w % 8];
        }

        $mod = $sum % 11;
        $expected = $mod < 2 ? 0 : 11 - $mod;

        return $expected === (int) $chave[43];
    }

    private function schemaOf(DOMDocument $dom): string
    {
        $root = $dom->documentElement;

        return $root === null ? '' : $root->nodeName;
    }

    /**
     * @param  list<string>  $paths
     */
    private function firstText(DOMXPath $xpath, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = XmlQuery::first($xpath, $path);

            if ($node !== null && trim($node->textContent) !== '') {
                return trim($node->textContent);
            }
        }

        return null;
    }

    /**
     * A chave não vem em elemento nenhum do evento: ela é a parte do `Id` que
     * não é `ID`, `tpEvento` nem `nSeqEvento`. O `nSeqEvento` do próprio
     * documento é a âncora — a chave são os 44 dígitos imediatamente antes
     * dele — e é ela que decide entre as duas layouts publicadas do `Id` (com
     * e sem o CNPJ do emitente entre o `tpEvento` e a chave), porque a sobra
     * de uma não pode parecer a sequência da outra.
     *
     * Nenhuma das duas é assumida, e nenhuma janela é testada "até uma
     * fechar": a chave é a identidade do documento, e uma janela vizinha que
     * passa no DV (~1,3% das vezes, com o modelo ainda legível) faria o
     * documento ser gravado sob uma identidade que não existe, sem erro em
     * lugar nenhum. Layout desconhecido recusa, com o mesmo erro de chave
     * ausente.
     */
    private function chaveFromId(DOMXPath $xpath, ?string $nSeqEvento): ?string
    {
        $node = XmlQuery::firstBy($xpath, '//*[@Id]');

        if ($node === null || preg_match('/\d+/', $node->getAttribute('Id'), $matches) !== 1) {
            return null;
        }

        $digits = $matches[0];

        // O `Id` de um documento é a chave pura; o de um evento nunca é.
        if (strlen($digits) === 44) {
            return $digits;
        }

        if ($nSeqEvento === null) {
            return null;
        }

        foreach (self::ID_PREFIX_LENGTHS as $prefix) {
            $candidate = substr($digits, $prefix, 44);
            $sequence = substr($digits, $prefix + 44);

            if (strlen($candidate) !== 44 || $sequence === '' || strlen($sequence) > self::ID_SEQUENCE_MAX_LENGTH) {
                continue;
            }

            // A sequência no `Id` é o valor do XML, com ou sem zero à
            // esquerda — `01` para o `nSeqEvento` 1 que o brief traz.
            if (ltrim($sequence, '0') !== ltrim($nSeqEvento, '0')) {
                continue;
            }

            if (FiscalModel::fromDocumentModel(substr($candidate, 20, 2)) !== null && self::isValidChave($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function toDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
