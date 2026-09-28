<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Resposta a pergunta "está funcionando agora?" sem tocar em nenhum cliente.
 *
 * O teste exercita só a autenticação: nenhum serviço é chamado, nenhum
 * contribuinte é consultado, nenhuma execução é criada. O que ele devolve são os
 * quatro desfechos que exigem ações diferentes de quem vai operar — nada
 * configurado, certificado que não serve, credencial recusada e nada que
 * respondeu — porque um "falhou" só obriga a procurar em quatro lugares.
 *
 * Cada guarda é atribuída ao que ela de fato conferiu, em vez de ser adivinhada
 * a partir do código de uma exceção de outra camada: foi o `status` zero que
 * mandava toda falha local para `certificado`, e ele não distingue um certificado
 * vencido de uma pasta temporária sem gravação.
 */
final class SerproConnectivity
{
    /**
     * Textos fixos por desfecho. O operador precisa de uma frase que diga o que
     * fazer; o texto do provedor pode trazer identificador de outra conta, o eco
     * do segredo enviado ou caminho de arquivo, e nenhum dos três pertence a
     * esta resposta.
     *
     * Nenhum deles diz quem recusou. Um desfecho nomeia a ação, não o autor: os
     * quatro chegam por mais de um caminho, e uma frase que atribuísse a recusa
     * ao SERPRO mandaria o operador procurar do lado errado justamente no
     * desfecho em que ele menos tem para onde ir.
     */
    private const MESSAGES = [
        'configuracao' => 'A credencial do Integra Contador não está configurada: faltam a chave de integração ou o segredo.',
        'certificado' => 'O certificado do contratante não está configurado, ou não serve para esta credencial.',
        'credencial' => 'A credencial configurada não pôde ser usada; reveja a chave de integração, o segredo e o certificado gravados.',
        'provedor' => 'A verificação não pôde ser concluída: o serviço de autenticação do Integra Contador ou a máquina que o executa não respondeu como esperado.',
    ];

    public function __construct(private SerproTokenProvider $tokens) {}

    /**
     * @return array{ok: bool, failed_element: ?string, message: ?string, checked_at: string}
     */
    public function check(): array
    {
        $checkedAt = now()->toISOString();
        $connection = SerproConnection::current();

        if ($connection === null || ! $connection->isConfigured()) {
            return $this->failure('configuracao', $checkedAt);
        }

        // A ausência do certificado é conferida aqui porque `assertIdentity()`
        // volta sem reclamar quando não há PFX: quem recusaria isso mais adiante
        // é o materializador, e lá o veredito seria o de uma credencial recusada.
        if ($connection->certificate_encrypted === null) {
            return $this->failure('certificado', $checkedAt);
        }

        try {
            // Vigência e documento do contratante são conferidos aqui, e não só
            // lá dentro de `verify()`: este é o guard que sabe dizer o que
            // falhou, e nenhum `status` de exceção seria verdadeiro sobre ele.
            // Cifrado ilegível entra por aqui como `NotSent` e vira `certificado`,
            // porque o que está para ser trocado é o certificado.
            $connection->assertIdentity();
        } catch (SerproException) {
            return $this->failure('certificado', $checkedAt);
        }

        // O segredo guardado abre com a chave de aplicação atual? Uma linha
        // restaurada de outro ambiente, uma coluna truncada ou uma chave girada
        // não abrem, e o conserto é recadastrar a credencial, não caçar o
        // SERPRO. A leitura é descartada de propósito — o `SerproTokenProvider`
        // abre o mesmo valor de novo logo abaixo, e é lá que a identidade da
        // falha mora. O que importa aqui é a resposta nomeada no lugar do `500`
        // que a exceção crua viraria.
        try {
            $connection->consumerSecret();
        } catch (DecryptException) {
            return $this->failure('credencial', $checkedAt);
        }

        try {
            $this->tokens->verify();
        } catch (SerproException $exception) {
            return $this->failure(self::elementFor($exception->failure), $checkedAt);
        }

        return [
            'ok' => true,
            'failed_element' => null,
            'message' => null,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * Só a taxonomia decide aqui, e o que chega já passou pelas guardas acima.
     *
     * `Upstream` é quem não deu conta do lado de lá, `Indeterminate` é quem não
     * sabe se deu, e `NotSent` é quem não chegou a mandar nada: os três são
     * `provedor` porque a ação é a mesma nos três casos, e recadastrar a
     * credencial não resolve nenhum deles — trocar o certificado ainda menos.
     *
     * `Indeterminate` não chega hoje, e está aqui de propósito: o provedor de
     * token classifica `5xx` como `Upstream` sem passar por `classify()`, mas um
     * `504` da autenticação é `Indeterminate` em qualquer outro caminho, e sem
     * este braço ele cairia no `default` e viraria `credencial` — mandando o
     * operador trocar uma credencial boa à espera de um serviço que não
     * respondeu.
     *
     * O `NotSent` que chega aqui é o da pasta temporária, e só ele. Os outros dois
     * `NotSent` — segredo ilegível e certificado ilegível — são conferidos antes
     * de `verify()`, por guard que têm nome próprio, e nenhum dos dois é falha de
     * infraestrutura: os dois precisam de recadastro.
     *
     * O braço `default` é a recusa do que foi enviado, e é onde cai também o
     * resto do que `verify()` sabe fazer: as falhas de identidade que ela
     * reconfere (`SerproConnection::current()` relê a linha, e
     * `assertIdentity()` reexamina vigência e documento) e o materializador
     * achando o certificado ausente. Todas essas são `DoNotRetry` com
     * `status` zero, e todas deveriam ser `certificado` — troque-se o
     * certificado, não a credencial.
     *
     * Chegam aqui só se a linha mudar entre a conferência desta classe e a
     * releitura do provider: milissegundos e uma gravação concorrente, com o
     * guard acima tendo sido verdadeiro para o valor antigo. Fica nomeado em vez
     * de mascarado por um `status`, e a próxima verificação acerta.
     *
     * Estático e público para que a taxonomia inteira seja testável: o erro
     * dela é silencioso, e um caso novo que caia no `default` não quebraria
     * teste nenhum.
     */
    public static function elementFor(SerproFailure $failure): string
    {
        return match ($failure) {
            SerproFailure::Upstream, SerproFailure::Indeterminate, SerproFailure::NotSent => 'provedor',
            default => 'credencial',
        };
    }

    /**
     * @return array{ok: false, failed_element: string, message: string, checked_at: string}
     */
    private function failure(string $element, string $checkedAt): array
    {
        return [
            'ok' => false,
            'failed_element' => $element,
            'message' => self::MESSAGES[$element],
            'checked_at' => $checkedAt,
        ];
    }
}
