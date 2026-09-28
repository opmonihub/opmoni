<?php

namespace App\Services;

use App\Enums\SerproFailure;
use App\Models\SerproConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SerproTokenProvider
{
    private const CACHE_KEY = 'serpro:token-pair';

    public function __construct(private SerproCertificateMaterializer $materializer) {}

    public function pair(): SerproTokenPair
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof SerproTokenPair) {
            return $cached;
        }

        return $this->authenticate();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Verificação sob demanda: autentica de novo, sem consultar o par guardado.
     *
     * Sem isso o par em cache responderia "está funcionando" com uma
     * autenticação de meia hora atrás, e é por isso que este método não passa
     * por `pair()`: ele vai direto a `authenticate()`, e o que a autenticação
     * bem-sucedida deixa em cache é o par recém-emitido — então o teste não custa
     * uma autenticação à sincronização seguinte. Nenhum serviço é chamado aqui —
     * a pergunta é sobre a credencial, e um gateway que responde bem não diz
     * nada sobre ela.
     *
     * **A falha não descarta o par por igual.** Descartar antes de tentar e
     * falhar depois deixa o cache vazio, e a sincronização seguinte tem de se
     * autenticar no meio da mesma falha que a verificação acabou de ter — que é
     * o inverso do que a spec pede ("SHALL keep a usable token rather than
     * obtaining a new one per call"), e no pior momento possível.
     *
     * O que sobrevive é o par cuja falha **não diz nada sobre a credencial**:
     * `Upstream` (o provedor não deu conta, ou não deu conta de responder), e
     * `Throttled` (ele mandou esperar, e esperar é a única ação). `NotSent` entra
     * pelo mesmo critério — a pasta temporária que não aceitou gravação e o
     * cifrado que não abre não jugam a credencial, e o token guardado continua
     * sendo o único que existe.
     *
     * `DoNotRetry` é o contrário e é o único que descarta: é o rótulo de "a
     * credencial é o problema" em todos os pontos que o produzem — linha
     * ausente, serviço fora do catálogo, certificado vencido, ausente, ilegível
     * ou divergente do documento, e a recusa do que foi enviado. O par em cache
     * saiu daquela mesma credencial, então devolvê-lo só compra um `401` na
     * chamada seguinte, que o `SerproClient` transformaria em uma segunda
     * tentativa com o mesmo token.
     *
     * Qualquer falha fora desses dois grupos também **preserva** o par: o que
     * este método não conhece não tem autoridade para jogar fora um token que
     * talvez esteja válido. Trocar um par bom por nenhum é sempre o erro; trocar
     * um par bom por outro é apenas um token a mais.
     *
     * @throws SerproException
     */
    public function verify(): void
    {
        try {
            $this->authenticate();
        } catch (SerproException $exception) {
            if ($this->discardsCachedPair($exception->failure)) {
                $this->forget();
            }

            throw $exception;
        }
    }

    /**
     * O par em cache só é descartado quando a falha é sobre a credencial que o
     * emitiu, e `DoNotRetry` é o único rótulo que significa isso. A lista
     * explícita é o ponto: um caso novo da taxonomia entra **preservando** o
     * par, que é o lado seguro de se errar.
     */
    private function discardsCachedPair(SerproFailure $failure): bool
    {
        return $failure === SerproFailure::DoNotRetry;
    }

    private function authenticate(): SerproTokenPair
    {
        $connection = SerproConnection::current();

        if ($connection === null) {
            throw new SerproException(
                'Conexão com o Integra Contador não configurada.',
                SerproFailure::DoNotRetry,
                0,
            );
        }

        // A identidade é conferida antes de qualquer autenticação: pedir token
        // com um certificado que não é o do contratante gasta uma chamada
        // recusada para descobrir o que já se sabe localmente.
        $connection->assertIdentity();

        return $this->materializer->withCertificate(
            $connection,
            fn (string $path): SerproTokenPair => $this->request($connection, $path),
        );
    }

    /**
     * O segredo é lido aqui, e não em frente ao `curl`.
     *
     * Um cifrado guardado que não abre com a chave de aplicação atual é uma
     * falha nomeada da credencial, e não um `DecryptException` subindo de dentro
     * de uma requisição HTTP para virar `500` no consumidor. A mensagem não
     * repete o erro do OpenSSL nem o valor guardado.
     *
     * @throws SerproException
     */
    private function secretOf(SerproConnection $connection): string
    {
        try {
            return $connection->consumerSecret();
        } catch (DecryptException) {
            throw new SerproException(
                'O segredo da credencial guardada não pôde ser lido: o conteúdo guardado não abre com a chave de aplicação atual.',
                SerproFailure::NotSent,
                0,
            );
        }
    }

    private function request(SerproConnection $connection, string $certificatePath): SerproTokenPair
    {
        // O segredo não ganha nome: ele é lido, entregue ao `curl` e morre com o
        // `PendingRequest` que o carrega, sem atravessar o tratamento da
        // resposta. Um `$secret` local vivia por todo o resto do método sem
        // necessidade, e zerá-lo no fim seria a mesma ilusão de
        // `SerproCertificateIdentity`: uma cópia local sobrescrita não apaga o
        // segredo de lugar nenhum, e fingir que apaga é pior que não dizer nada.
        try {
            $response = Http::asForm()
                ->withBasicAuth($connection->consumer_key, $this->secretOf($connection))
                ->withHeaders(['Role-Type' => 'TERCEIROS'])
                ->withOptions(['curl' => [
                    CURLOPT_SSLCERT => $certificatePath,
                    CURLOPT_SSLCERTPASSWD => $connection->certificatePassword() ?? '',
                    CURLOPT_SSLCERTTYPE => 'P12',
                ]])
                ->timeout((int) config('integra-contador.timeout', 25))
                ->post((string) config('integra-contador.auth_url'), ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new SerproException(
                'O serviço de autenticação do Integra Contador está indisponível.',
                SerproFailure::Upstream,
                503,
                null,
                null,
            );
        }

        if ($response->failed()) {
            throw $this->refusal($response->status());
        }

        $payload = $response->json();
        $accessToken = (string) data_get($payload, 'access_token', '');
        $jwtToken = (string) data_get($payload, 'jwt_token', '');

        if ($accessToken === '' || $jwtToken === '') {
            throw new SerproException(
                'A resposta de autenticação não trouxe os dois tokens exigidos.',
                SerproFailure::Upstream,
                502,
            );
        }

        $pair = new SerproTokenPair(
            $accessToken,
            $jwtToken,
            (int) data_get($payload, 'expires_in', 0),
        );

        Cache::put(self::CACHE_KEY, $pair, $pair->ttl());

        return $pair;
    }

    /**
     * O que a falha da autenticação é, pelo que a resposta de fato diz.
     *
     * Três grupos, três consertos opostos: o provedor mandou esperar, o provedor
     * não deu conta, e o provedor recusou o que foi enviado. O `429` é o único
     * `4xx` do terceiro grupo que não é recusa — limite de tentativas não se
     * corrige redigitando a credencial, e tratá-lo como recusa punia duas vezes
     * quem está só com o serviço ocupado: com o rótulo errado e com a instrução
     * de refazer chave, segredo e certificado. É o mesmo `Throttled` que
     * `SerproException::classify()` devolve para `429` na chamada de serviço.
     *
     * Esta classe **não** chama `classify()`, e a divergência é deliberada: ele é
     * a regra da chamada de serviço, onde `401` significa token vencido (e
     * `SerproClient::send()` repete a chamada uma vez) e `504` significa "pode ter
     * sido aplicado e ninguém sabe". Na autenticação nada das duas coisas vale —
     * um `401` aqui é chave ou segredo recusado, e repetir seria pedir de novo ao
     * provedor algo que ele acabou de recusar; um `504` aqui não deixa nada
     * pendente para reconciliar, porque a autenticação não aplica nada. O único
     * ponto em que as duas divergiam era o `429`, e ele está nomeado acima.
     *
     * @throws SerproException
     */
    private function refusal(int $status): SerproException
    {
        return match (true) {
            $status === 429 => new SerproException(
                'O serviço de autenticação do Integra Contador recusou a autenticação por limite de tentativas.',
                SerproFailure::Throttled,
                $status,
            ),
            $status >= 500 => new SerproException(
                'O serviço de autenticação do Integra Contador está indisponível.',
                SerproFailure::Upstream,
                $status,
            ),
            default => new SerproException(
                'A credencial do Integra Contador foi recusada.',
                SerproFailure::DoNotRetry,
                $status,
            ),
        };
    }
}
