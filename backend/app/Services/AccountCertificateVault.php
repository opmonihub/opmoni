<?php

namespace App\Services;

use App\Jobs\IssueSerproTermJob;
use App\Models\Account;
use App\Models\AccountCertificate;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O cofre do e-CNPJ do escritório: um certificado por conta, guardado **cifrado
 * no banco** e nunca em disco.
 *
 * A leitura do PKCS#12 é a mesma do cofre de cliente —
 * `CertificatePkcs12::inspect()` — e a identidade do contratante é a do plano 01,
 * `SerproCertificateIdentity`. O que é deste cofre são as três decisões que
 * nenhuma das duas unidades pode tomar por ele, porque as duas são compartilhadas
 * com quem tem outro contrato:
 *
 * 1. **Recusa de certificado vencido.** `inspect()` devolve `valid_until` e não
 *    recusa por vigência, porque para o certificado de cliente vencido é
 *    histórico e não erro. Para o escritório é o contrário: é este certificado
 *    que a plataforma assina o termo de autorização, e um e-CNPJ vencido
 *    produziria um termo que o provedor recusa — ou pior, um termo que só falha
 *    quando alguém tenta usar. A recusa está neste arquivo, e não herdada de
 *    passagem: ela é do cofre, é testada aqui e tem frase própria.
 * 2. **A frase da recusa que a leitura compartilhada não pode prometer.** O
 *    `openssl_pkcs12_read` não diz por que falhou. A leitura compartilhada
 *    assume isso e só promete RC2 quando tem byte de RC2, e com isso o
 *    container com RC4 — mesmo sintoma, algoritmo diferente — cai na frase de
 *    senha errada. Um cliente refaz o export porque o operador sabe que o export
 *    é dele; um escritório que recebe "a senha informada" digita a senha certa
 *    de novo, falha de novo e abre um chamado. Aqui a recusa nomeia as três
 *    causas que o cofre **não** consegue distinguir, em vez de jurar qual foi.
 * 3. **O destino.** Cifra no banco (`encrypt-then-base64`) em vez de gravar
 *    arquivo: o disco do container do Laravel é efêmero em produção, e um
 *    certificado em arquivo sumiria a cada recriação do serviço.
 *
 * Sobre a senha: o `finally` esvazia a referência e descarta o que a leitura
 * devolveu, e **não** faz mais do que isso. Atribuir não apaga memória, e um
 * comentário aqui dizendo o contrário seria pior do que a ausência dele.
 */
final class AccountCertificateVault
{
    /**
     * A frase de "não deu para abrir o e-CNPJ enviado".
     *
     * Não é a frase do cofre de cliente, e a diferença é o assunto da frase: o
     * arquivo chegou, a senha foi digitada, e o cofre não sabe qual das três
     * coisas impediu a leitura. Prometer "a senha informada" seria mentir sobre
     * uma das outras duas, e a correção que o escritório precisa fazer é
     * diferente em cada caso.
     */
    private const RECUSA_DE_LEITURA = 'Não foi possível abrir o e-CNPJ enviado: a senha está errada, o arquivo não é um PKCS#12 ou ele usa criptografia legada que este sistema não abre.';

    /**
     * A frase do e-CNPJ vencido, e a chave em que ela sai.
     *
     * A chave é `certificate` e não `password` porque o defeito é do arquivo, e
     * apontar o dedo para o campo da senha faz o operador reler a senha que
     * estava certa. A forma é a mesma da senha errada — `422`, nada gravado —,
     * e a frase é outra porque o conserto é outro: trocar a senha não devolve
     * um certificado vencido.
     */
    private const RECUSA_DE_VIGENCIA = 'O e-CNPJ enviado está vencido: envie um certificado vigente para assinar o termo de autorização.';

    /**
     * O tamanho da coluna `original_filename`, em **caractere** — que é a
     * unidade que o `varchar(n)` do Postgres conta.
     *
     * O número é o da migration — `string()` é `varchar(255)` — e é repetido
     * aqui porque o cofre é quem decide o que entra, e um corte que precisasse
     * confirmar o schema a cada upload seria uma leitura por upload.
     */
    private const LIMITE_DO_NOME = 255;

    public function __construct(
        private CertificatePkcs12 $pkcs12,
        private SerproCertificateIdentity $identity,
    ) {}

    /**
     * Grava o e-CNPJ do escritório, substituindo o que estivesse valendo.
     *
     * Tudo em uma transação, com a conta trancada: sem ela, dois uploads
     * simultâneos leriam ambos "não há certificado" e deixariam duas linhas
     * correntes — e a próxima troca escolheria uma delas pelo `latest('id')`, sem
     * que nada tivesse falhado. O `account_id` vai explícito em toda linha
     * escrita, e o cofre não mexe no tenant corrente — diferente do cofre de
     * cliente, que faz `??=` porque o modelo dele preenche `account_id` a partir
     * do singleton: aqui a coluna chega montada, e depender do `CurrentTenant`
     * seria o que faz um `queue:work` herdar a conta do job anterior.
     *
     * O arquivo só é decifrado e gravado depois de passar pela leitura, pela
     * vigência e pela identidade — o que significa que um upload recusado não
     * troca o certificado que o escritório já tinha, e é isso que o teste de
     * senha errada com certificado gravado prova.
     *
     * @throws ValidationException
     */
    public function replace(Account $account, UploadedFile $file, string $password): AccountCertificate
    {
        $bytes = $file->get();

        try {
            /*
             * **O arquivo é aberto uma vez, e a identidade lê o que já foi
             * aberto.**
             *
             * `inspect()` devolve `cert` e `pkey` além dos metadados, e este
             * cofre entrega o `cert` à identidade em vez de descartá-lo. A
             * segunda leitura dos mesmos bytes saiu: um e-CNPJ de verdade, de
             * até 2 MiB, é parseado uma vez por upload.
             *
             * As três saídas possíveis, e por que a terceira foi a escolhida:
             *
             * 1. **Usar só o `parse()` do `SerproCertificateIdentity`.** Perde a
             *    detecção de RC2 — que é o `LegacyPkcs12Ciphertext` —, e com ela a
             *    frase que diz "este certificado usa criptografia legada". Trocaria
             *    um problema de performance por um problema de mensagem. Não.
             * 2. **Extrair o CNPJ do `subject` que `inspect()` já devolveu.** É
             *    escrever um segundo validador de CNPJ, que é a coisa que o
             *    `SerproCertificateIdentity` existe para não acontecer, e é o
             *    `BrazilianTaxId` que confere o dígito alfanumérico. Não.
             * 3. **Passar o certificado aberto para a identidade extrair o
             *    documento dele.** É o que está aqui: a classificação continua
             *    vindo do `inspect()` — inclusive o container legado —, e a
             *    extração do CNPJ continua sendo da identidade, que é quem sabe
             *    ler documento. Nenhuma das duas perdeu o que só ela sabe.
             *
             * A chave privada que `inspect()` também devolve continua sem uso
             * aqui, e é o certo: a assinatura acontece em outro processo, que
             * reabre o PKCS#12 cifrado com a senha guardada. Segurar a chave em
             * uma requisição de upload só daria a ela um tempo de vida a mais.
             *
             * O teste `test_o_cofre_extrai_o_documento_do_certificado_que_ja_abriu`
             * fixa o caminho, e `test_a_linha_gravada_descreve_o_mesmo_certificado_que_o_cofre_abriu`
             * fixa que a linha continua descrevendo um arquivo só.
             */
            $inspected = $this->pkcs12->inspect($bytes, $password);

            $this->refuseExpired($inspected['valid_until']);

            // O documento é o que está dentro do certificado, nunca o que veio no
            // formulário — e a identidade é quem valida que ele fecha, porque
            // CNPJ alfanumérico não se confere com uma segunda conta aqui.
            $document = $this->identity->documentFromCertificate($inspected['cert']);

            $attributes = [
                'document' => $document,
                'subject' => $inspected['subject'],
                'serial_number' => $inspected['serial'],
                'valid_from' => $inspected['valid_from'],
                'valid_until' => $inspected['valid_until'],
                'original_filename' => $this->boundedFilename($file->getClientOriginalName()),
                'sha256' => $inspected['sha256'],
                'certificate_encrypted' => Crypt::encryptString(base64_encode($bytes)),
                'password_encrypted' => Crypt::encryptString($password),
            ];

            return DB::transaction(function () use ($account, $attributes): AccountCertificate {
                $locked = Account::whereKey($account->getKey())->lockForUpdate()->firstOrFail();

                $this->supersede($locked->getKey());

                // `forceCreate`, e não `create`: as duas colunas cifradas e o
                // documento **não** são `Fillable` do modelo, e é essa a
                // garantia de que nenhum `fill()` de requisição os alcance. Este
                // cofre é o autor desses valores — ele os cifrou neste mesmo
                // passo —, e é por isso que ele, e só ele, atravessa a lista.
                $certificado = AccountCertificate::forceCreate(array_merge($attributes, [
                    'account_id' => $locked->getKey(),
                ]));

                /*
                 * **A emissão do termo é agendada dentro da transação e
                 * `afterCommit()`, e é aqui que ela tem de estar.**
                 *
                 * Sem o `afterCommit()`, o job sai para a fila ainda dentro
                 * da transação, e o worker pode executá-lo antes do `commit`:
                 * ele leria um e-CNPJ que ainda não existe para o resto do
                 * banco, e a emissão falharia por uma conta que o operador
                 * acabou de entregar com sucesso. Pior, se a transação
                 * rollbackasse depois, o job já estaria na fila e tentaria
                 * emitir para um certificado que ninguém tem.
                 *
                 * E o que o job faz com a falha é o que fecha o ciclo: ele
                 * não registra estado, porque quem escreve estado é o
                 * `SerproTermManager` e ele sabe a diferença entre recusa do
                 * provedor e indisponibilidade. E o gate de emissão decide se
                 * há assinatura a fazer — com a prova de contrato por pagar, o
                 * job falha com a mensagem que diz que o gate está fechado, e
                 * o `200` do upload não muda por isso. O que o upload promete
                 * é que o e-CNPJ foi guardado; o termo é uma etapa seguinte
                 * que depende de um teste de contrato que ainda não existe.
                 */
                IssueSerproTermJob::dispatch($locked->getKey())->afterCommit();

                return $certificado;
            });
        } catch (ValidationException $exception) {
            throw $this->broadeningTheRejection($exception);
        } finally {
            $password = '';
            $bytes = '';
            unset($password, $bytes);
        }
    }

    /**
     * Tira o e-CNPJ do escritório, e com ele a capacidade de autorizar a
     * integração, e devolve a linha que tirou — ou `null` quando não havia
     * certificado para tirar.
     *
     * A linha **não** é apagada: ela é marcada como removida e perde as duas
     * colunas cifradas, e é dela que se responde "que certificado o escritório
     * teve em março". Uma remoção que não deixa rastro é indistinguível de um
     * escritório que nunca entregou certificado, e essa distinção é a que a
     * auditoria precisa.
     *
     * **O retorno existe para que a auditoria nomeie a linha que foi removida.**
     * Quem chamava antes lia a corrente por fora, para montar o registro, e o
     * cofre a lia de novo aqui dentro da transação: duas queries para uma linha,
     * e duas chances de verem linhas diferentes. Com um upload concorrente entre
     * as duas, a auditoria registraria o `document` de um certificado que já não
     * era o do escritório — e o `document` é o que o registro existe para dizer.
     * O registro agora sai da linha que a própria transação marcou, e a leitura
     * fora dela não existe mais.
     *
     * Remover sem certificado nenhum é um sucesso: a tela de remoção não pode
     * falhar por causa de um estado que o usuário só queria alcançar. O `null` é
     * o que diz que não havia nada, e o registro de um `null` é uma remoção com
     * `resource_id` e `document` nulos — que é o que de fato aconteceu, e vale
     * mais do que a ausência do registro.
     */
    public function remove(Account $account): ?AccountCertificate
    {
        return DB::transaction(function () use ($account): ?AccountCertificate {
            $locked = Account::whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $current = AccountCertificate::query()
                ->where('account_id', $locked->getKey())
                ->whereNull('replaced_at')
                ->whereNull('removed_at')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($current === null) {
                return null;
            }

            $this->withdraw($current, 'removed_at');

            return $current;
        });
    }

    /**
     * O nome do arquivo como vai para a coluna, e ele precisa caber nela.
     *
     * O nome é o que o cliente digitou, e o cliente não tem teto: um nome de 300
     * caracteres é um `500` de banco de dados no meio de um upload cujo arquivo
     * está inteiramente correto — e um `500` que não se resolve reenviando,
     * porque reenviar dá o mesmo nome de novo. O Postgres não trunca `varchar`,
     * ele recusa; o SQLite da suíte aceita em silêncio, o que é parte do motivo
     * de isto ser um teste e não uma confiança no banco.
     *
     * **O corte é no limite da coluna, e é visível de propósito.** Recusar o
     * e-CNPJ por causa do nome com que ele chegou seria trocar um `500` por um
     * `422` que não é do Operador corrigir; e o nome é metadado de exibição, que
     * o `CertificatePkcs12` não valida e que ninguém consome programaticamente.
     * Cortar preserva a informação que interessa — que o escritório mandou um
     * arquivo — e o que some é a repetição da letra.
     *
     * `mb_substr` e não `substr`, e a razão é de contagem: o `varchar(255)` do
     * Postgres conta **caractere**, e é caractere que o corte tem de contar. Um
     * corte em bytes faria duas coisas erradas ao mesmo tempo — cortaria um nome
     * de 204 caracteres antes do fim, porque ele tem 404 bytes, e cairia no meio
     * de um caractere multibyte, produzindo um nome com byte inválido que a
     * tela mostra com caractere de replacement. O nome completo está no
     * `subject` do certificado, que vem do próprio X.509 e não é do formulário.
     *
     * O `ClientCertificateVault` grava a mesma coluna sem este corte. Não é uma
     * justificativa para repetir o defeito: é o motivo de o corte estar aqui
     * como decisão consciente, e o do cliente é corrigido quando aquele cofre for
     * tocado, com a suíte dele rodando.
     */
    private function boundedFilename(string $name): string
    {
        return mb_substr($name, 0, self::LIMITE_DO_NOME);
    }

    /**
     * A recusa que a leitura compartilhada devolveu, na frase deste cofre.
     *
     * Só a chave `password` é reescrita, e é a única que precisa: as recusas de
     * metadados, de chave privada e o `LegacyPkcs12Ciphertext` do RC2 chegam
     * pela chave `certificate` e sobem **inteiras** — a frase de RC2 já diz o
     * algoritmo, e acrescentar o nome da conta a ela seria a resposta a "de quem
     * é este erro", que é o que o cofre de cliente faz e que o escritório não
     * precisa. Um `withSubject()` aqui nomearia o escritório em um `422`, e o
     * escritório não entra em mensagem de erro nenhuma.
     */
    private function broadeningTheRejection(ValidationException $exception): ValidationException
    {
        if (! array_key_exists('password', $exception->errors())) {
            return $exception;
        }

        return ValidationException::withMessages(['password' => self::RECUSA_DE_LEITURA]);
    }

    /**
     * O e-CNPJ vencido é recusado aqui, e não por quem assina o termo depois.
     *
     * Recusar na hora do upload é o que faz o defeito ser um `422` com nada
     * gravado, com o nome do problema dito ao operador, em vez de um termo que
     * o provedor recusa na hora de usá-lo — ou, pior, de uma falha de
     * assinatura que só aparece quando alguém tenta assinar. `inspect()` devolve
     * `valid_until` e não recusa porque o certificado de cliente vencido é
     * histórico; e o `SerproCertificateIdentity`, que o cofre chama logo abaixo
     * para extrair o documento, recusa com a frase **dele**. É de propósito que
     * as duas coisas sejam verdade ao mesmo tempo: esta vem primeiro e é a que o
     * escritório lê, e a do plano 01 continua ali como a segunda tranca do mesmo
     * cadeado — ainda que ela venha do certificado já aberto, e não de uma
     * segunda leitura dos bytes.
     *
     * @throws ValidationException
     */
    private function refuseExpired(Carbon $validUntil): void
    {
        if (! $validUntil->isPast()) {
            return;
        }

        throw ValidationException::withMessages(['certificate' => self::RECUSA_DE_VIGENCIA]);
    }

    /**
     * Marca a linha que estava valendo como trocada e apaga o conteúdo cifrado
     * dela, para que existam duas linhas e uma só corrente.
     */
    private function supersede(int $accountId): void
    {
        $current = AccountCertificate::query()
            ->where('account_id', $accountId)
            ->whereNull('replaced_at')
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->latest('id')
            ->first();

        if ($current === null) {
            return;
        }

        $this->withdraw($current, 'replaced_at');
    }

    /**
     * O segredo sai da linha; os metadados ficam.
     *
     * O `forceFill` ignora o `Fillable` de propósito: `replaced_at`,
     * `removed_at` e as duas colunas cifradas não são preenchíveis, e quem
     * decide que esta linha sai de vigência é este cofre.
     */
    private function withdraw(AccountCertificate $certificate, string $marcador): void
    {
        $certificate->forceFill([
            $marcador => now(),
            'certificate_encrypted' => null,
            'password_encrypted' => null,
        ])->save();
    }
}
