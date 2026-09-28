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
 * configurado, certificado que não serve, credencial recusada e uma verificação
 * que não se completou — porque um "falhou" só obriga a procurar em quatro
 * lugares. O quarto não é "não respondeu": um provedor que responde recusando por
 * limite também deixa a verificação sem conclusão, e o operador precisa de uma
 * frase que valha para os dois.
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
     * Nenhum deles diz quem recusou. Um desfecho nomeia a ação, não o autor: uma
     * frase que atribuísse a recusa ao SERPRO mandaria o operador procurar do
     * lado errado justamente no desfecho em que ele menos tem para onde ir. É
     * por isso que a frase de `provedor` abre uma disjunção e não a fecha: ela
     * aponta o serviço e a máquina e deixa em aberto qual dos dois foi, e um
     * limite de tentativas — que também cai em `provedor` — não ganha frase
     * própria porque a ação, esperar, é a mesma. `configuracao` é o único
     * elemento que nunca vem da taxonomia — os guard de `certificado` e de
     * `credencial` respondem antes —, e é justamente o desfecho que não tem autor
     * para nomear: ou a credencial não existe, ou existe incompleta.
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
            return $this->failure(self::elementFor($exception->failure, $exception->status), $checkedAt);
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
     * sabe se deu, `Throttled` é quem mandou esperar e `NotSent` é quem não
     * chegou a mandar nada: os quatro são `provedor` porque a ação é a mesma nos
     * quatro casos, e recadastrar a credencial não resolve nenhum deles — trocar
     * o certificado ainda menos. Limite do provedor em especial não é recusa do
     * que foi enviado, e `credencial` puniria duas vezes quem está só com o
     * serviço ocupado: com a frase errada e com o tom de erro que a tela
     * reserva para a credencial.
     *
     * O que chega hoje, sem corrida: `Upstream` de `5xx`, de `ConnectionException`
     * e de resposta sem os dois tokens; `Throttled` do `429` que
     * `SerproTokenProvider::refusal()` nomeia; e `NotSent` da pasta temporária
     * que não aceitou gravação.
     *
     * `Indeterminate` **não** chega, e está no braço por decisão, não por
     * esquecimento. O único produtor dele é `classify()` sobre a resposta do
     * *gateway*, e um `504` da autenticação é `Upstream` de propósito: a
     * autenticação não aplica nada, então não há requisição para reconciliar nem
     * item para deixar indeterminado. Ele fica aqui para o dia em que algum
     * caminho passar a classificar a resposta de autenticação — sem este braço,
     * esse dia entregaria `credencial` sem nenhum teste quebrar.
     *
     * Pelo mesmo motivo `Reauthenticate` e `ResubmitTerm` também não chegam: são
     * códigos de envelope do gateway, e o provedor de token responde em OAuth.
     * `Success` também não chega — `check()` só traduz a exceção de uma falha, e
     * sucesso não é falha.
     *
     * O `NotSent` que chega aqui é o da pasta temporária, e só ele. Os outros dois
     * `NotSent` — segredo ilegível e certificado ilegível — são conferidos antes
     * de `verify()`, por guard que têm nome próprio, e nenhum dos dois é falha de
     * infraestrutura: os dois precisam de recadastro.
     *
     * O braço `default` é a recusa do que foi enviado, e o que cai nele é
     * exatamente a lista de quem não chega: `Reauthenticate`, `ResubmitTerm` e
     * `Success`. Se algum dia algum deles chegar, a tela recebe `credencial` e o
     * teste que declara o destino de cada desfecho avisa.
     *
     * As falhas de identidade que `verify()` reconfere — `SerproConnection::current()`
     * relê a linha, e `assertIdentity()` reexamina vigência e documento — e o
     * materializador achando o certificado ausente **não** caem no `default`:
     * são `DoNotRetry` com `status` zero, e o braço de `DoNotRetry` as manda para
     * `certificado`, que é o conserto certo. Elas chegam aqui só se a linha mudar
     * entre a conferência desta classe e a releitura do provider: milissegundos e
     * uma gravação concorrente, com o guard acima tendo sido verdadeiro para o
     * valor antigo.
     *
     * Estático e público para que a taxonomia inteira seja testável: o erro
     * dela é silencioso, e um desfecho sem destino declarado não quebraria teste
     * nenhum.
     *
     * @param  int  $status  O `status` que a falha carrega, e é o que separa duas
     *                       falhas de rótulo igual e conserto oposto. `0` é
     *                       conferência local — nenhum provedor chegou a ver
     *                       nada —, e um `status` real é recusa do que foi
     *                       enviado.
     */
    public static function elementFor(SerproFailure $failure, int $status = 0): string
    {
        return match ($failure) {
            SerproFailure::Upstream, SerproFailure::Throttled, SerproFailure::Indeterminate, SerproFailure::NotSent => 'provedor',
            // `DoNotRetry` são dois defeitos de mesmo rótulo e consertos
            // opostos, e o `status` é o que os separa. Com `0` é uma
            // conferência que o provedor nunca viu — identidade re-conferida
            // (vencido, divergente, ilegível) e materializador sem certificado:
            // o que está para ser trocado é o certificado. Com `status` real é
            // recusa do que foi enviado, e `SerproTokenProvider::refusal()`
            // sempre repassa o da resposta, nunca zero.
            SerproFailure::DoNotRetry => $status > 0 ? 'credencial' : 'certificado',
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
