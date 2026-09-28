<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproConnection;
use App\Support\SerproSigner;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;

/**
 * O termo de autorização do escritório, montado e assinado, e nada mais.
 *
 * **O que esta classe decide e o que ela não decide.** Ela monta o documento
 * no formato do modelo de referência que o próprio provedor publica, normaliza o
 * que é invisível e entrega os bytes à rotina de assinatura isolada. Ela não
 * decide se o formato é aceito: essa decisão é do gate de emissão, e o gate só
 * abre depois de um teste de contrato real contra o provedor, que **não existe
 * ainda**. A documentação do provedor está viva — lida em 2026-09-28, ela
 * responde `200` e publica a tabela de layout, inclusive `finalidade` sem o
 * espaço e a canonicalização inclusiva —, mas ela **não publica XSD** e não
 * diz se o validador do provedor aceita o nosso documento. Um termo emitido por
 * esta classe é um documento bem formado e criptograficamente válido, e **não**
 * é um termo de que se possa afirmar aceitação.
 *
 * **Quem é quem no documento, e de onde vem essa leitura.** O modelo dá ao
 * elemento `destinatario` o papel `contratante` e ao `assinadoPor` o papel
 * `autor pedido de dados`, e o texto do próprio termo diz que o destinatário é
 * a empresa CONTRATANTE a quem o signatário autoriza a executar as requisições.
 * Quatro coisas do repositório fecham a conta: o `CONTEXT.md` define o
 * `contratante` como a plataforma, "nunca a Account que opera o sistema"; o
 * envelope de toda requisição leva `contratante` = `contratante_numero` da
 * credencial de plataforma; o exemplo do provedor publica
 * `destinatario papel="contractante"` com a razão social da CONTRATANTE; e quem
 * assina é o e-CNPJ do escritório, de modo que o escritório é o `assinadoPor` por
 * definição — colocar a plataforma ali seria dizer num documento jurídico que
 * outro sujeito assinou. Logo: `destinatario` é a plataforma, `assinadoPor` é o
 * escritório. **O plano de 02 diz em um trecho "com o escritório como
 * destinatário", e é esse trecho que diverge**: ele briga com o papel
 * `contratante` que o próprio modelo dá ao elemento `destinatario` e com o
 * exemplo que o provedor publica, e é a leitura do modelo que o código segue.
 *
 * **O par que identifica o contratante sai de uma linha só, e é por isso que
 * ele não é conferido.** O número vem de `contratante_numero` e o nome é
 * cortado de `certificate_subject` — a mesma linha, a que o gate leu —, e o
 * método não recebe o número por parâmetro, de modo que nenhum chamador
 * consegue produzir um termo que nomeia a plataforma com o número de outra
 * empresa. A versão anterior recebia o número e relia a credencial para o nome,
 * cruzando as duas metades em tempo de execução: a conferência era a defesa de
 * uma interface que já permitia o defeito, e a interface agora não o permite.
 *
 * **Os bytes são o documento.** A renovação reenvia exatamente os mesmos bytes e
 * o provedor responde `304` com o token no `ETag` sem re-assinar nada. Um termo
 * que mudasse de byte entre a persistência e o reenvio seria outro documento, e
 * a renovação seria uma emissão disfarçada. Por isso a normalização acontece
 * **antes** da assinatura, nenhuma normalização acontece depois, e nada
 * reserializa o resultado: a string devolvida aqui é a string que a rotina
 * devolveu, sem passar por `loadXML`/`saveXML` outra vez. A data entra como
 * `America/Sao_Paulo` e as duas chamadas de Carbon do mesmo instante dão as
 * mesmas datas, de modo que a montagem é reprodutível byte a byte.
 *
 * **As quatro constantes de formato são declarações, e o gate as lê.** O período
 * de vigência não aparece no documento — o modelo escreve só a data computada, e
 * a data é valor por termo —, a normalização é transformação do documento, não
 * marca no template, e o fuso também não aparece: o que ele muda é o dia da
 * `dataAssinatura`, que é valor por termo. Um digest do template sozinho ficaria
 * igual depois de `30` virar `60`, depois de a normalização ser removida e
 * depois de o fuso mudar, e a prova de contrato gravada continuaria autorizando
 * um documento que ninguém testou. Por isso `formatDigest()` hasheia o template
 * canonicalizado concatenado com as quatro constantes, com prefixo de
 * comprimento, em ordem fixa.
 *
 * **O que esse digest não cobre, e é preciso saber onde termina.** Ele cobre o
 * template do termo e as quatro constantes. Ele **não** cobre o envelope da
 * assinatura: o provedor valida o documento assinado, então mudar a lista de
 * transforms, a URI da `Reference`, o algoritmo de assinatura ou a própria
 * `SerproSigner` muda os bytes que o provedor vê e deixa `formatDigest()`
 * intocado — e o gate reabriria sem ninguém decidir isso. O envelope é coberto
 * pelos testes de proveniência da `SerproSigner`, que afirma a estrutura e
 * confere a assinatura contra a chave pública do certificado: é uma medida, e
 * um teste de contrato é uma aceitação. Confundir as duas coisas é como um
 * leitor acaba crendo que o gate cobre o documento inteiro quando ele cobre a
 * parte que carrega o dado do escritório.
 *
 * **Sigilo.** O documento assinado, os bytes do certificado e a senha não são
 * registrados em log nenhum, não são impressos e não tocam o disco; o que sai
 * daqui é a string do termo e nada mais.
 */
final class SerproTermSigner
{
    /**
     * O período de vigência, em dias.
     *
     * É o `+30 days` do modelo de referência, e é o **único** cálculo de
     * período que existe em qualquer material do provedor. O que se preserva é o
     * **período**, não a chamada `date()` do modelo — que não roda, porque
     * passa string onde vai timestamp e um terceiro argumento a uma função de
     * dois. Aqui é aritmética de Carbon.
     *
     * **O valor é do modelo de referência, e a documentação do provedor não o
     * confirma — ela o contradiz sem substituí-lo.** Lida em 2026-09-28, a
     * página de padrões técnicos declara apenas que `vigencia` é "a data de
     * validade deste termo de autorização, no formato AAAAMMDD": um formato, e
     * nenhum número de dias. Os dois exemplos que ela publica duram muito mais
     * que trinta — o de layout vai de `20220614` a `20221231`, **200 dias**, e o
     * do serviço de envio de `20220808` a `20221231`, **145 dias** — e ambos
     * terminam no mesmo dia.
     *
     * **A inferência de que isso prova é mais fraca do que parece, e fica
     * escrita aqui para ninguém a usar como se fosse mais forte.** "Os dois
     * exemplos terminam no mesmo dia, logo o período não é uma constante de N
     * dias" descarta **uma** hipótese — a constante — e não descarta uma regra
     * **calculada**. O candidato está à vista: **31 de dezembro é o término
     * natural de um documento fiscal brasileiro**, e os dois exemplos são de
     * 2022. "Válido até o fim do exercício" explica dois inícios diferentes com
     * um fim só, tão bem quanto uma constante explicaria, e seria uma convenção
     * documentada e não coincidência. O que a inferência sustenta, e é o
     * bastante, é mais fraco que ela: **nem 145 nem 200 são inferíveis**, porque
     * sob qualquer regra compatível com as amostras os dois números são artefato
     * da regra e não a regra.
     *
     * E o argumento "30 é o único cálculo de período em qualquer material do
     * provedor" é verdadeiro e **argumenta a favor do modelo de referência** —
     * o mesmo artefato de terceiro cujas outras duas esquisitices foram
     * mantidas porque era a única autoridade, e essa autoridade já foi lida e
     * não confirma 30. O achado de que os dois exemplos duram muito mais que
     * trinta é um argumento **contra** 30, e precisa ser pesado contra o
     * argumento a favor em vez de escondido ao lado dele.
     *
     * **O argumento que sobrevive ao contato com o provedor real é a
     * assimetria do risco, e é este: 30 deixa mais espaço para estar errado.**
     * Se a regra do provedor for mais longa — fim de exercício, digamos —, um
     * termo de trinta dias é mais curto que o máximo e provavelmente é
     * aceito, porque um documento que vence satisfaz um prazo maior. Se a
     * regra for **mais curta** que trinta, o termo é recusado, e essa falha é
     * **tarde e recuperável**: a renovação diária continua rodando, a recusa
     * fica gravada como `recusado` e a ação que a linha registra é re-assinar.
     * O inverso não vale — um termo longo demais falha de imediato contra o
     * gateway real, e a recusa é o único estado que este produto não desfaz sem
     * o escritório entregar o e-CNPJ de novo. Com o gate fechado e o item
     * 4.6a por pagar, **30 é o valor cujo erro custa menos.**
     *
     * **O contra-argumento fica declarado, e é ele que o contrato tem de
     * fechar.** Trinta dias é prazo curto para uma autorização feita para durar
     * um exercício, e a documentação do provedor — a única coisa que um leitor
     * não técnico leria — aponta para o fim do ano. Se o teste de contrato
     * reprovar 30, o valor muda, e a consequência mecânica é uma constante:
     * `formatDigest()` reabre o gate por si, que é o comportamento correto.
     * **O que não se pode é trocar 30 por 145 ou por 200** — seriam duas
     * amostras contraditórias promovidas a regra, contra um schema que não
     * está publicado. Por isso **4.6a tem de ser pago antes de qualquer prova
     * ser gravada.**
     */
    public const PERIODO_VIGENCIA_DAYS = 30;

    /**
     * O algoritmo de canonicalização do formato: a inclusiva da REC 2001, que é
     * a que a `Reference` do termo assinado declara.
     *
     * O modelo **calcula** o digest com a forma exclusiva e **declara** a
     * inclusiva. A rotina de assinatura mantém a exclusiva, por decisão
     * registrada em `design.md` (D2), e a divergência entre as duas não aparece
     * neste documento porque o termo não declara namespace algum — que é o que
     * o teste de divergência de canonicalização prova, e o que a raiz sem
     * namespace é que garante.
     *
     * **O URI está repetido de propósito.** A `SerproSigner` tem o mesmo literal
     * em `CANONICALIZATION`, e as duas cópias não devem virar uma: o teste que
     * amarra o algoritmo **declarado no documento assinado** a esta constante é
     * o que pega uma troca feita de um lado só. Uma constante compartilhada
     * mudaria os dois lados junto e o teste ficaria cego — que é a forma mais
     * comum de um teste que passa sem provar nada.
     */
    public const ALGORITMO_CANONICALIZACAO = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    /**
     * A regra de normalização do Unicode invisível, escrita como o texto que a
     * operação executa.
     *
     * A regra é a implementação: os caracteres que saem do termo são lidos
     * **desta constante**, e não de uma lista ao lado dela. Uma lista paralela
     * poderia divergir da constante sem que nada percebesse, e o gate passaria a
     * autorizar um documento cuja normalização é outra.
     */
    public const REGRA_NORMALIZACAO = 'remove:U+200B,U+200C,U+200D,U+FEFF';

    /**
     * O que substitui todo valor por escritório e por termo no template.
     *
     * ASCII puro, e por uma razão que é fácil de inverter: um template com
     * caractere invisível mudaria de byte sob a normalização, e o digest
     * ficaria sensível a ela pelo motivo errado — pareceria que o template
     * cobre a regra quando quem a cobre é a constante.
     */
    public const PLACEHOLDER = '********';

    /** O prefixo da regra de normalização, que o código corta para ler os pontos. */
    private const PREFIXO_REGRA = 'remove:';

    /**
     * O fuso em que as duas datas do termo são escritas.
     *
     * Entra no digest pelo mesmo motivo das outras três: não aparece em lugar
     * nenhum do template, e o que ele muda é a `dataAssinatura` — que é valor
     * por termo, com placeholder. Na fronteira da meia-noite de São Paulo o dia
     * da assinatura muda, o documento muda, e o digest do formato não diria
     * nada disso. Uma prova de contrato já gravada continuaria autorizando o
     * termo novo, que é exatamente a falha que o gate existe para impedir.
     */
    public const FUSO = 'America/Sao_Paulo';

    /** O identificador do sistema, literal do modelo. */
    private const SISTEMA = 'API Integra Contador';

    /** O tipo das duas partes do termo: as duas são empresas, e as duas com CNPJ. */
    private const TIPO = 'PJ';

    /** O papel do `destinatario`, literal do modelo. */
    private const PAPEL_CONTRATANTE = 'contratante';

    /** O papel do `assinadoPor`, literal do modelo. */
    private const PAPEL_AUTOR = 'autor pedido de dados';

    /** O texto do termo, verbatim do modelo de referência. */
    private const TEXTO_TERMO = 'Autorizo a empresa CONTRATANTE, identificada neste termo de autorização como DESTINATÁRIO, a executar as requisições dos serviços web disponibilizados pela API INTEGRA CONTADOR, onde terei o papel de AUTOR PEDIDO DE DADOS no corpo da mensagem enviada na requisição do serviço web. Esse termo de autorização está assinado digitalmente com o certificado digital do PROCURADOR ou OUTORGADO DO CONTRIBUINTE responsável, identificado como AUTOR DO PEDIDO DE DADOS.';

    /** O aviso legal, verbatim do modelo de referência. */
    private const TEXTO_AVISO_LEGAL = 'O acesso a estas informações foi autorizado pelo próprio PROCURADOR ou OUTORGADO DO CONTRIBUINTE, responsável pela informação, via assinatura digital. É dever do destinatário da autorização e consumidor deste acesso observar a adoção de base legal para o tratamento dos dados recebidos conforme artigos 7º ou 11º da LGPD (Lei n.º 13.709, de 14 de agosto de 2018), aos direitos do titular dos dados (art. 9º, 17 e 18, da LGPD) e aos princípios que norteiam todos os tratamentos de dados no Brasil (art. 6º, da LGPD).';

    /** A finalidade, verbatim do modelo de referência. */
    private const TEXTO_FINALIDADE = 'A finalidade única e exclusiva desse TERMO DE AUTORIZAÇÃO, é garantir que o CONTRATANTE apresente a API INTEGRA CONTADOR esse consentimento do PROCURADOR ou OUTORGADO DO CONTRIBUINTE assinado digitalmente, para que possa realizar as requisições dos serviços web da API INTEGRA CONTADOR em nome do AUTOR PEDIDO DE DADOS (PROCURADOR ou OUTORGADO DO CONTRIBUINTE).';

    public function __construct(private SerproSigner $signer) {}

    /**
     * O termo do escritório assinado pelo e-CNPJ da conta.
     *
     * **A credencial de plataforma chega por parâmetro, e é a linha que o gate
     * liberou.** Ela é quem diz o que o contratante é: o número vem de
     * `contratante_numero` e o nome é cortado de `certificate_subject`, e as
     * duas metades saem **da mesma linha**. A versão anterior recebia o número
     * por parâmetro e relia a credencial para achar o nome — duas leituras, e
     * duas chances de ver empresas diferentes, que é o desfecho que a
     * docblock do `SerproTermManager` diz que a emissão tem de impedir: um
     * `destinatario` que nenhuma prova autorizou.
     *
     * Por isso não há conferência de número aqui, e a ausência é
     * estrutural: o número do contratante não é um parâmetro que este método
     * aceite, de modo que nenhum chamador consegue passar o de outra empresa.
     * Onde antes havia uma conferência em tempo de execução, há uma interface
     * que não tem por onde errar.
     *
     * @param  SerproConnection  $connection  a credencial de plataforma, já lida
     *                                        por quem chamou — `SerproTermManager`
     *                                        é o único chamador, e a lê **antes**
     *                                        do gate
     *
     * @throws SerproException quando a credencial não nomeia o contratante, ou
     *                         quando a assinatura falha. Nenhuma das mensagens
     *                         carrega o termo, o certificado ou a senha.
     */
    public function sign(Account $account, AccountCertificate $certificate, SerproConnection $connection): string
    {
        // O número e o nome saem da mesma linha, e essa é a metade da garantia:
        // o número é o que a coluna guarda, e o nome é o que o certificado da
        // mesma linha carrega.
        $documento = self::document(array_merge([
            'contratante_numero' => (string) $connection->contratante_numero,
            'contratante_nome' => $this->nomeDoContratante($connection),
            'autor_numero' => $certificate->document,
            'autor_nome' => $this->nomeDoEscritorio($account),
        ], $this->datas()));

        /*
         * **A normalização é antes da assinatura, e é aqui que ela é única.**
         *
         * Um U+200B colado no nome sobrevive a qualquer comparação de string e
         * ainda assim derruba a validação do nome no provedor. Removê-lo depois
         * de assinar não resolveria nada: o digest já estaria calculado sobre os
         * bytes errados, e qualquer normalização depois da assinatura produz um
         * documento cuja assinatura não confere. Por isso a string devolvida
         * daqui é a mesma que entra no cálculo do digest, e a rotina de
         * assinatura não normaliza nada.
         */
        $normalizado = self::normalize($documento);

        // O que volta é o que vai para o banco e o que vai para o provedor na
        // renovação, sem passar por `loadXML`/`saveXML` de novo.
        return $this->signer->sign(
            $normalizado,
            $certificate->certificateBytes(),
            $certificate->certificatePassword(),
        );
    }

    /**
     * O template do termo: o documento com todo valor por escritório e por termo
     * trocado pelo placeholder.
     *
     * É público porque duas coisas precisam dele além desta classe — o digest
     * do gate e o teste de proveniência da assinatura, que tem de exercitar o
     * documento real e não uma cópia dele.
     */
    public static function template(): string
    {
        return self::document([
            'contratante_numero' => self::PLACEHOLDER,
            'contratante_nome' => self::PLACEHOLDER,
            'autor_numero' => self::PLACEHOLDER,
            'autor_nome' => self::PLACEHOLDER,
            'assinatura' => self::PLACEHOLDER,
            'vigencia' => self::PLACEHOLDER,
        ]);
    }

    /**
     * A impressão digital do **formato** do termo, e a entrada do gate de
     * emissão: o SHA-256 do template canonicalizado concatenado com as quatro
     * constantes de formato — o comprimento do período de vigência, o algoritmo
     * de canonicalização, a regra de normalização e o fuso das datas — em ordem
     * fixa e com prefixo de comprimento, para que nenhum template concate
     * ambiguamente com uma constante.
     *
     * **Não é o hash de um termo nem o de uma instância**: é o do formato, e
     * por isso não muda de escritório para escritório. Mudar o template ou
     * qualquer uma das quatro constantes muda o digest, a comparação com a prova
     * gravada deixa de bater e a emissão reabre o gate sem ninguém decidir
     * isso — que é a razão de o gate ser esse e não uma marca booleana.
     */
    public static function formatDigest(): string
    {
        return hash('sha256', self::join([
            self::canonicalTemplate(),
            (string) self::PERIODO_VIGENCIA_DAYS,
            self::ALGORITMO_CANONICALIZACAO,
            self::REGRA_NORMALIZACAO,
            self::FUSO,
        ]));
    }

    /**
     * O documento, na ordem de elementos do modelo de referência.
     *
     * A ordem é a do modelo e não uma ordem escolhida: o documento é
     * canonicalizado para o digest, e a canonicalização respeita a ordem da
     * árvore. Um elemento fora de ordem é outro documento — e um outro digest.
     *
     * @param  array{contratante_numero: string, contratante_nome: string, autor_numero: string, autor_nome: string, assinatura: string, vigencia: string}  $campos
     */
    private static function document(array $campos): string
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->preserveWhiteSpace = true;
        $documento->formatOutput = false;

        $dados = $documento->appendChild($documento->createElement('termoDeAutorizacao'))
            ->appendChild($documento->createElement('dados'));

        $sistema = $documento->createElement('sistema');
        $sistema->setAttribute('id', self::SISTEMA);
        $dados->appendChild($sistema);

        foreach ([
            'termo' => self::TEXTO_TERMO,
            'avisoLegal' => self::TEXTO_AVISO_LEGAL,
            'finalidade' => self::TEXTO_FINALIDADE,
        ] as $elemento => $texto) {
            $no = $documento->createElement($elemento);
            $no->setAttribute('texto', $texto);
            $dados->appendChild($no);
        }

        foreach (['dataAssinatura' => $campos['assinatura'], 'vigencia' => $campos['vigencia']] as $elemento => $data) {
            $no = $documento->createElement($elemento);
            $no->setAttribute('data', $data);
            $dados->appendChild($no);
        }

        $dados->appendChild(self::parte($documento, 'destinatario', $campos['contratante_numero'], $campos['contratante_nome'], self::PAPEL_CONTRATANTE));
        $dados->appendChild(self::parte($documento, 'assinadoPor', $campos['autor_numero'], $campos['autor_nome'], self::PAPEL_AUTOR));

        $xml = $documento->saveXML();

        if ($xml === false) {
            throw new SerproException('Não foi possível montar o termo de autorização.', SerproFailure::NotSent, 0);
        }

        return $xml;
    }

    private static function parte(DOMDocument $documento, string $elemento, string $numero, string $nome, string $papel): DOMElement
    {
        $no = $documento->createElement($elemento);

        // `setAttribute` e não `createAttribute` + `value`: o primeiro escapa o
        // valor, o segundo emite um aviso de referência de entidade malformada e
        // **grava o atributo vazio**. Uma razão social com `&` no meio — que é
        // letra normal em nome de empresa — viraria um termo sem nome.
        $no->setAttribute('numero', $numero);
        $no->setAttribute('nome', $nome);
        $no->setAttribute('tipo', self::TIPO);
        $no->setAttribute('papel', $papel);

        return $no;
    }

    /**
     * Tira do termo o Unicode invisível, e nada mais.
     *
     * O conjunto é lido da própria regra declarada, e é ele que o
     * `REGRA_NORMALIZACAO` nomeia: `U+200B`, `U+200C`, `U+200D` e `U+FEFF` são
     * o espaço de largura zero, os dois junções e a marca de ordem de byte, e
     * são invisíveis em tela, em PDF e em revisão. Um espaço duro (`U+00A0`) fica
     * no documento, porque a regra não o nomeia e um termo que remove tudo não
     * diria o que cobre.
     */
    private static function normalize(string $xml): string
    {
        $pontos = explode(',', substr(self::REGRA_NORMALIZACAO, strlen(self::PREFIXO_REGRA)));
        $caracteres = array_map(
            static fn (string $ponto): string => mb_chr((int) hexdec(substr(trim($ponto), 2)), 'UTF-8'),
            $pontos,
        );

        return str_replace($caracteres, '', $xml);
    }

    /**
     * O template canonicalizado, que é a primeira parte da entrada do digest.
     *
     * A forma canônica é a inclusiva da REC 2001 — a que
     * `ALGORITMO_CANONICALIZACAO` nomeia e a que a `Reference` do termo assinado
     * declara. `DOMNode::C14N()` não recebe o algoritmo por parâmetro: ele
     * implementa essa forma, e o argumento booleano é a escolha entre
     * exclusiva e inclusiva. Como o termo não declara namespace, as duas
     * coincidem byte a byte aqui — e é essa coincidência que o teste de
     * proveniência vigia, porque é ela que sustenta a decisão de manter a
     * exclusiva no cálculo do digest.
     */
    private static function canonicalTemplate(): string
    {
        $documento = new DOMDocument;
        $documento->preserveWhiteSpace = true;
        $documento->formatOutput = false;

        $anterior = libxml_use_internal_errors(true);

        try {
            $carregado = $documento->loadXML(self::template(), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        $canonico = $carregado === false ? false : $documento->documentElement?->C14N(false, false);

        if (! is_string($canonico)) {
            throw new SerproException('Não foi possível canonicalizar o modelo do termo de autorização.', SerproFailure::NotSent, 0);
        }

        return $canonico;
    }

    /**
     * A concatenação da entrada do digest, com prefixo de comprimento.
     *
     * O prefixo resolve a ambiguidade que a spec aponta: sem ele, um template
     * que terminasse exatamente onde uma constante começa produziria a mesma
     * entrada que outro par template/constante, e duas somas de bytes
     * diferentesariam com o mesmo hash. O `;` fecha cada parte, e nenhum dos
     * dois lados o produz no meio.
     *
     * @param  list<string>  $partes
     */
    private static function join(array $partes): string
    {
        $entrada = '';

        foreach ($partes as $parte) {
            $entrada .= strlen($parte).':'.$parte.';';
        }

        return $entrada;
    }

    /**
     * As duas datas do termo, do **mesmo** instante.
     *
     * Duas chamadas separadas de `now()` seriam duas leituras do relógio, e na
     * fronteira da meia-noite de São Paulo a assinatura cairia num dia e a
     * vigência no outro — e um termo com as duas datas em dias diferentes é
     * outro documento, que é a mesma instabilidade que a renovação não pode ter.
     * Por isso há um instante só, e a vigência é aritmética sobre ele.
     *
     * O período é o de `PERIODO_VIGENCIA_DAYS` e não um número escrito à mão: é
     * a constante que entra na conta, e o teste que amarra a vigência emitida à
     * constante quebra se alguém trocar o `addDays()` sem trocar a constante.
     *
     * @return array{assinatura: string, vigencia: string}
     */
    private function datas(): array
    {
        $instante = Carbon::now(self::FUSO);

        return [
            'assinatura' => $instante->format('Ymd'),
            'vigencia' => $instante->copy()->addDays(self::PERIODO_VIGENCIA_DAYS)->format('Ymd'),
        ];
    }

    /**
     * A razão social do escritório, que é o autor do pedido de dados.
     *
     * **Isto é uma escolha, e a escolha é o nome da conta — não o `subject` do
     * certificado.** O modelo de referência pede "o nome exato do CPF ou CNPJ de
     * quem vai assinar", e o `subject` gravado do e-CNPJ é a fonte mais próxima
     * desse "exato". Ele foi recusado por um motivo prático: é uma distinguished
     * name cujo formato varia com quem montou o certificado — vírgula em um
     * emissor, barra em outro — e o mesmo corte de DN que a razão social da
     * plataforma precisa seria uma heurística aplicada ao nome de uma pessoa
     * jurídica que o escritório recognise. O `Account.name` é o nome com que a
     * conta é conhecida no produto, e é o que alguém que procura o termo procura.
     *
     * **A pergunta que isto deixa em aberto, e que ninguém respondeu:** o `CN`
     * do certificado e o `Account.name` podem ser nomes diferentes da mesma
     * empresa, e nada no cofre os compara — o cofre grava o `document`, extraído
     * do certificado, e nunca o confere contra um nome. Se o provedor recusar um
     * termo por divergência de nome, o lugar de procurar é aqui, e a correção é
     * trocar a fonte pelo `subject` do certificado. Fica escrito porque é
     * exatamente o tipo de substituição que um leitor mais tarde faz sem pensar,
     * e o motivo de não ser o padrão não é o do modelo — é o da DN.
     */
    private function nomeDoEscritorio(Account $account): string
    {
        return $account->name;
    }

    /**
     * A razão social do contratante, que é a plataforma.
     *
     * **De onde vem, e por que não é um campo próprio.** A conta tem coluna de
     * nome; a credencial de plataforma não tem nenhuma, e o único nome que
     * existe no repositório para a plataforma é o `CN` do certificado de e-CNPJ
     * que ela registrou — que é a razão social dela tal como o ICP-Brasil a
     * emitiu, e a única fonte que não é uma constante escrita neste arquivo.
     * É por isso que a assinatura depende da credencial de plataforma existir:
     * um termo que não nomeia o contratante não é um termo.
     *
     * O formato do `CN` é o que o `SerproCertificateIdentity` documenta — razão
     * social e documento separados por `:` —, e o corte só acontece quando o
     * rabo tem catorze posições de documento, para que uma razão social com dois
     * pontos não seja partida ao meio.
     *
     * **A linha vazia é a única recusa aqui, e ela é a que a interface deixou
     * de proteger.** O número do contratante não vem mais de quem chama, e por
     * isso não há com o que ele discordar; o que ainda pode faltar é a razão
     * social, e um `destinatario` sem nome é um termo que não nomeia a parte que
     * está autorizando.
     */
    private function nomeDoContratante(SerproConnection $conexao): string
    {
        $assunto = trim((string) $conexao->certificate_subject);

        if ($assunto === '') {
            throw new SerproException('Não há credencial de plataforma para nomear o contratante do termo.', SerproFailure::NotSent, 0);
        }

        $nome = $this->razaoSocial($assunto);

        if ($nome === '') {
            throw new SerproException('O certificado da plataforma não informa a razão social do contratante do termo.', SerproFailure::NotSent, 0);
        }

        return $nome;
    }

    private function razaoSocial(string $assunto): string
    {
        // O `openssl_x509_parse()` devolve o sujeito como distinguished name, e
        // o que o cofre da plataforma guarda é essa string — que pode vir como
        // `CN=…, O=…` (vírgula) ou como `/CN=…/O=…` (barra), conforme quem
        // montou o certificado.
        $comum = preg_match('/CN=(.*?)(?:,|\/|$)/s', $assunto, $achado) === 1
            ? trim($achado[1])
            : $assunto;

        return preg_match('/^(.*):[A-Z0-9]{14}$/u', $comum, $separado) === 1
            ? trim($separado[1])
            : $comum;
    }
}
