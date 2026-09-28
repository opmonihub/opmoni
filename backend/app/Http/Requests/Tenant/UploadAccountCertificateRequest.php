<?php

namespace App\Http\Requests\Tenant;

use App\Models\AccountCertificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UploadAccountCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', AccountCertificate::class);
    }

    /**
     * A validação do arquivo é de forma, não de conteúdo: `extensions:pfx,p12`
     * e não `mimes`, porque `mimes` compara tipo de mídia e o `pfx` não é um
     * tipo que o Symfony conhece — a regra recusaria todo e-CNPJ de verdade. O
     * conteúdo é aberto pelo `CertificatePkcs12`, que é quem sabe dizer "isto não
     * é um PKCS#12" e "a senha não abre".
     *
     * `document` é `prohibited` porque o documento contratante é extraído do
     * certificado e é o que o provedor compara com o envelope: um `document` no
     * corpo da requisição viraria, se alguém o respeitasse, a fonte da
     * identidade do contratante. Recusar com `422` é melhor do que ignorar em
     * silêncio — o operador descobre que o campo não existe em vez de gravar um
     * CNPJ que não é o do certificado.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'max:2048', 'extensions:pfx,p12'],
            'password' => ['required', 'string', 'max:1024'],
            'document' => ['prohibited'],
        ];
    }
}
