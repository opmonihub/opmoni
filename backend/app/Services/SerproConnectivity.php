<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Support\AccessSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

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

    public function __construct(
        private SerproCredentialLifecycle $lifecycle,
        private SerproDiagnosticAuthenticator $authenticator,
        private SerproCertificateIdentity $identity,
    ) {}

    /** @return array{ok: bool, failed_element: ?string, message: ?string, checked_at: string} */
    public function check(): array
    {
        AccessSession::assertOwnership(request());
        $checkedAt = now()->toISOString();
        $snapshot = $this->lifecycle->snapshot();
        try {
            if ($snapshot === null || ! $snapshot->isConfigured() || $snapshot->consumerSecret() === '') {
                return $this->failure('configuracao', $checkedAt);
            }
        } catch (DecryptException) {
            return $this->failure('credencial', $checkedAt);
        }
        try {
            if (! $snapshot->hasUsableCertificate()
                || $this->identity->document($snapshot->certificateBytes() ?? '', $snapshot->certificatePassword() ?? '') !== $snapshot->document) {
                return $this->failure('certificado', $checkedAt);
            }
        } catch (DecryptException|ValidationException) {
            return $this->failure('certificado', $checkedAt);
        }
        $failure = null;
        try {
            $this->authenticator->authenticate($snapshot);
        } catch (SerproException $exception) {
            $failure = $exception;
        }
        AccessSession::assertOwnership(request());
        if (! $this->lifecycle->isCurrent($snapshot)) {
            throw new HttpResponseException(response()->json([
                'code' => 'connection_changed',
                'message' => 'A conexão mudou durante a verificação. Teste novamente.',
            ], 409, ['Cache-Control' => 'no-store']));
        }
        if ($failure !== null) {
            return $this->failure(self::elementFor($failure->failure, $failure->status), $checkedAt);
        }

        return ['ok' => true, 'failed_element' => null, 'message' => null, 'checked_at' => $checkedAt];
    }

    /**
     * Preserve the public verdict taxonomy. Local credential/certificate guards
     * run before authentication; provider refusals never expose their body.
     * A generation change is handled separately as a 409 error envelope.
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
