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
     * A validação do arquivo é de **nome**, não de conteúdo: `extensions:pfx,p12`
     * olha a extensão que o cliente enviou, e é a única das duas que aceita um
     * e-CNPJ de verdade.
     *
     * **`mimes` também resolve por extensão**, e essa é a parte que parece óbvia
     * e não é: a regra não compara tipo de mídia, ela chama `guessExtension()`.
     * A diferença é de onde a extensão sai. `extensions` lê
     * `getClientOriginalExtension()` — o nome que o cliente mandou. `mimes` pede
     * ao sistema o que ele **adivinha do conteúdo**, e o que este ambiente
     * adivinha de um PKCS#12 real é `application/octet-stream`, cuja extensão
     * canônica no `MimeTypes` do Symfony é `bin`. Resultado medido: um
     * `.p12` de verdade reprova em `mimes:pfx,p12` e passa em `extensions:pfx,p12`
     * — `mimes` recusaria todo e-CNPJ do produto.
     *
     * E o `UploadedFile::fake()` **esconde isso**: o `File` de teste deriva o
     * MIME do **nome** (`MimeType::from($this->name)`) e nunca do conteúdo, então
     * um fake chamado `conta.p12` se apresenta como `application/pkcs12` e passa
     * em `mimes`. Um teste feito com fake não veria a recusa que o usuário
     * encontraria em produção — e é por isso que a escolha aqui é `extensions`,
     * e não porque alguém testou `mimes` e o viu passar.
     *
     * O conteúdo é aberto pelo `CertificatePkcs12`, que é quem sabe dizer "isto
     * não é um PKCS#12" e "a senha não abre" — e é o único que pode, porque as
     * duas regras de arquivo acima cuidam só do **nome**: `extensions` nem
     * olha o conteúdo, e o `mimes` olha, mas para adivinhar a extensão, o que
     * para um PKCS#12 dá `bin`. É o mesmo arranjo que a
     * `StoreClientCertificateRequest` dos certificados de cliente já usa.
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
