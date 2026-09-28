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

        // `assertIdentity()` volta sem reclamar de um certificado ausente — quem
        // recusa isso é o materializador, mais adiante, e lá o veredito seria o
        // de uma credencial recusada. A ausência é conferida aqui.
        if ($connection->certificate_encrypted === null) {
            return $this->failure('certificado', $checkedAt);
        }

        try {
            // Vigência e documento do contratante são conferidos aqui, e não só
            // lá dentro de `verify()`: este é o guard que sabe dizer o que
            // falhou, e nenhum `status` de exceção seria verdadeiro sobre ele.
            $connection->assertIdentity();
        } catch (SerproException) {
            return $this->failure('certificado', $checkedAt);
        }

        try {
            $this->tokens->verify();
        } catch (DecryptException) {
            // O segredo, o certificado ou a senha guardados não abrem com a
            // chave de aplicação atual: a credencial guardada está ilegível, e
            // nenhum byte dela — nem a razão da falha — pertence a esta
            // resposta. Um `500` aqui seria a resposta menos informativa
            // possível a "por que a minha credencial está quebrada?".
            return $this->failure('credencial', $checkedAt);
        } catch (SerproException $exception) {
            return $this->failure($this->elementOf($exception), $checkedAt);
        }

        return [
            'ok' => true,
            'failed_element' => null,
            'message' => null,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * Só a taxonomia do provedor decide, e ela é a única fonte: o que não saiu
     * daqui é o que o provedor respondeu.
     *
     * `Upstream` é quem não deu conta do lado de lá, e `Indeterminate` é quem
     * deixou ninguém saber — inclusive a pasta efêmera do PFX que não aceitou a
     * gravação, que é `Indeterminate` pelo mesmo motivo que um gateway em
     * timeout. Os dois são `provedor` porque a ação é a mesma nos dois casos:
     * recadastrar a credencial não resolve, e trocar o certificado menos ainda.
     *
     * O que sobra é o provedor recusando o que foi enviado, que é `credencial`.
     * E a única falha local que ainda poderia chegar aqui é o certificado
     * vencendo no intervalo de milissegundos entre a conferência de cima e a que
     * `verify()` refaz; ela sai como `credencial` até a próxima verificação.
     */
    private function elementOf(SerproException $exception): string
    {
        return match ($exception->failure) {
            SerproFailure::Upstream, SerproFailure::Indeterminate => 'provedor',
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
