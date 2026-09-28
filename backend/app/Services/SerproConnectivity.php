<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;

/**
 * Resposta a pergunta "está funcionando agora?" sem tocar em nenhum cliente.
 *
 * O teste exercita só a autenticação: nenhum serviço é chamado, nenhum
 * contribuinte é consultado, nenhuma execução é criada. O que ele devolve são os
 * quatro desfechos que exigem ações diferentes de quem vai operar — nada
 * configurado, certificado que não serve, credencial recusada e provedor fora do
 * ar — porque um "falhou" só obriga a procurar em quatro lugares.
 *
 * A distinção entre "recusado" e "indisponível" é a que mais importa: uma
 * credencial recusada se corrige nesta tela, e um provedor fora do ar se espera.
 */
final class SerproConnectivity
{
    /**
     * Textos fixos por desfecho. O operador precisa de uma frase que diga o que
     * fazer; o texto do provedor pode trazer identificador de outra conta, o eco
     * do segredo enviado ou caminho de arquivo, e nenhum dos três pertence a
     * esta resposta.
     */
    private const MESSAGES = [
        'configuracao' => 'A credencial do Integra Contador não está configurada: faltam a chave de integração ou o segredo.',
        'certificado' => 'O certificado do contratante não está configurado, ou não serve para esta credencial.',
        'credencial' => 'O Integra Contador recusou a credencial configurada.',
        'provedor' => 'O serviço de autenticação do Integra Contador está indisponível.',
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

        if ($this->isCertificateUnusable($connection)) {
            return $this->failure('certificado', $checkedAt);
        }

        try {
            $this->tokens->verify();
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
     * O que se descobre aqui já se sabe sem sair da máquina. Gastar uma
     * autenticação recusada para descobrir que falta o certificado troca um nome
     * exato por um erro do provedor que não distingue o motivo — e que, para uma
     * credencial compartilhada, é indistinguível de senha errada.
     */
    private function isCertificateUnusable(SerproConnection $connection): bool
    {
        return $connection->certificate_encrypted === null
            || ($connection->certificate_valid_until !== null && $connection->certificate_valid_until->isPast());
    }

    /**
     * `status` zero nunca chegou ao provedor: é a identidade conferida localmente
     * antes de materializar o PFX, e depois que chave, segredo e certificado
     * estão conferidos o que sobra é o próprio certificado.
     *
     * Os demais `status` vieram do provedor. `5xx` e ligação recusada são
     * indisponibilidade, e qualquer outro `4xx` é recusa — inclusive o `400` com
     * que o SERPRO recusa um certificado que ele não reconheceu, que tratá-lo
     * como indisponibilidade mandaria o operador esperar por um serviço de pé.
     */
    private function elementOf(SerproException $exception): string
    {
        if ($exception->status === 0) {
            return 'certificado';
        }

        if ($exception->failure === SerproFailure::Upstream || $exception->status >= 500) {
            return 'provedor';
        }

        return 'credencial';
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
