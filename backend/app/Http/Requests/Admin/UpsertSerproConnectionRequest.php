<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpsertSerproConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A escrita é do super_admin e já vem pelo middleware; o formulário
        // cuida do formato, não da autorização.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Só o que veio é normalizado: injetar `null` nos campos ausentes faria
        // `sometimes` falhar justamente nos campos que a rotação omite.
        foreach (['consumer_key', 'consumer_secret'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    /**
     * Tudo é opcional: o que falta continua como está — inclusive uma chave em
     * branco, que é como a tela promete que se rotaciona só o segredo. Os
     * campos são `nullable` porque um campo em branco chega como `null`
     * (`ConvertEmptyStringsToNull` roda antes do controller), e sem isso um
     * "preserve o que está gravado" viraria 422. Quem exige a credencial
     * inteira é a primeira gravação, no serviço, com a linha travada; uma
     * segunda regra aqui só duplicaria a autoridade e poderia discordar dela.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'consumer_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'consumer_secret' => ['sometimes', 'nullable', 'string', 'max:512'],
            // `extensions` e não `mimes`, e a diferença é de **onde** a
            // extensão sai. As duas regras terminam comparando uma extensão com a
            // lista: `extensions` lê `getClientOriginalExtension()`, o nome que o
            // cliente mandou; `mimes` chama `guessExtension()`, que pergunta ao
            // **host** o que ele adivinha do conteúdo do arquivo.
            //
            // Isso faz `mimes` depender da base mágica da máquina, e a base
            // varia. Medido neste host (`file-5.45`, cuja base não tem entrada
            // para PKCS#12): um `.p12` de verdade é `application/octet-stream`,
            // cuja extensão canônica no `MimeTypes` do Symfony é `bin`, e
            // `in_array('bin', ['p12','pfx'])` é falso — o `mimes` recusava o
            // e-CNPJ da plataforma. Num host cujo libmagic conhece PKCS#12 (o
            // `MimeTypes` mapeia `'application/pkcs12' => ['p12','pfx']`) a
            // adivinhação dá `p12` e o `mimes` passa. Ou seja: a falha é da
            // máquina, não do formato, e uma regra de validação não pode
            // depender de qual das duas é a máquina.
            //
            // O conteúdo é conferido pelo `SerproConnectionManager`, que abre o
            // PFX e extrai o documento; e o `UploadedFile::fake()` esconde o
            // problema inteiro — o `File` de teste deriva o MIME do **nome**, de
            // modo que um fake chamado `plataforma.pfx` passa em `mimes`. É por
            // isso que
            // `SerproConnectionApiTest::test_o_upload_da_credencial_aceita_um_p12_de_verdade_como_arquivo_real`
            // sobe um `UploadedFile` real sobre um arquivo real.
            'certificate' => ['sometimes', 'file', 'extensions:pfx,p12', 'max:2048'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255', 'required_with:certificate'],
            // O documento contratante é extraído do certificado; aceitá-lo aqui
            // devolveria à request a autoridade que D13 tira dela.
            'contratante_numero' => ['prohibited'],
        ];
    }
}
