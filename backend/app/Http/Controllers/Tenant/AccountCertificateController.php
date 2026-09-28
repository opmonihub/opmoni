<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UploadAccountCertificateRequest;
use App\Http\Resources\AccountCertificateResource;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Services\AccountCertificateVault;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * O e-CNPJ do escritório: uma vez para cada conta, e sem ninguém da conta
 * assinar nada.
 *
 * O controller não decifra, não grava e não formata segredo. Ele valida a
 * forma do que chegou, delega ao cofre e devolve o que `AccountCertificateResource`
 * lista — que é metadado, nunca conteúdo.
 */
class AccountCertificateController extends Controller
{
    public function __construct(private AccountCertificateVault $vault) {}

    /**
     * O certificado que a conta corrente tem hoje.
     *
     * `404` quando não tem nenhum: a rota é da conta corrente e não da linha, e
     * um "não configurado" com `200` obrigaria a tela a distinguir um estado
     * que é ausência de recurso de um estado que é recurso vazio. O `404` aqui
     * também é a resposta de isolamento — a conta alheia não aparece em nenhuma
     * hipótese, porque a busca é por `account_id` explícito.
     */
    public function show(): AccountCertificateResource
    {
        Gate::authorize('viewAny', AccountCertificate::class);

        $certificate = AccountCertificate::currentFor($this->currentAccount()->getKey());

        if ($certificate === null) {
            abort(404);
        }

        return new AccountCertificateResource($certificate);
    }

    public function store(UploadAccountCertificateRequest $request): JsonResponse
    {
        $account = $this->currentAccount();
        $validated = $request->validated();

        try {
            $certificate = $this->vault->replace(
                $account,
                $validated['certificate'],
                (string) $validated['password'],
            );
        } finally {
            /*
             * A referência local da senha é descartada assim que o cofre
             * termina, e o `finally` roda também quando o cofre recusa — que é
             * o caso em que ela está na memória e não tem outro lugar para onde
             * ir.
             *
             * **Isto tira a senha do escopo deste método, e não da requisição.**
             * O mesmo texto continua no objeto `Request` — em `$request->input`
             * e nos parâmetros do corpo — que segue vivo até o fim do
             * ciclo, e é por isso que este comentário não diz que a senha "sai
             * do escopo da requisição": ela não sai, e o que o `finally` faz é
             * impedir que a cópia do controller vaza para o log de exceção e
             * para a auditoria. Apagar memória exigiria algo que PHP não tem, e
             * o cofre diz a mesma coisa no `finally` dele
             * (`AccountCertificateVault::replace`).
             */
            $validated['password'] = '';
            unset($validated);
        }

        // O que a auditoria de suporte registra é quem fez o quê: o documento,
        // que a API já publica. Nunca o arquivo, a senha ou o texto cifrado.
        SupportAudit::logWrite($request, 'account_certificates', 'create', $certificate->getKey(), [
            'document' => $certificate->document,
        ]);

        // `200` explícito porque a resource sozinha devolveria `201`: o upload
        // sempre cria uma linha nova, mas o recurso que a tela quer é o
        // certificado do escritório, que já existia ou não. É o mesmo ajuste que
        // a credencial da plataforma faz, e pelo mesmo motivo: a criação da linha
        // continua registrada no histórico, que é onde ela é consultável.
        return (new AccountCertificateResource($certificate))->response()->setStatusCode(200);
    }

    /**
     * A linha que a auditoria nomeia é a que o cofre removeu, e é o motivo de o
     * cofre devolver a linha: ler a corrente aqui, fora da transação, seria uma
     * segunda leitura que pode ver outra linha.
     */
    public function destroy(Request $request): Response
    {
        Gate::authorize('delete', AccountCertificate::class);

        $removed = $this->vault->remove($this->currentAccount());

        SupportAudit::logWrite($request, 'account_certificates', 'delete', $removed?->getKey(), [
            'document' => $removed?->document,
        ]);

        return response()->noContent();
    }

    /**
     * A conta corrente, e não uma conta que venha no corpo ou na rota.
     *
     * `ResolveTenant` já recusou quem não tem conta corrente, então o
     * `findOrFail` aqui não é uma guarda: é o `->getKey()` de que o cofre
     * precisa para trancar a linha e escrever o `account_id` explícito.
     */
    private function currentAccount(): Account
    {
        return Account::findOrFail(resolve(CurrentTenant::class)->accountId);
    }
}
