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
            'certificate' => ['sometimes', 'file', 'mimes:pfx,p12', 'max:2048'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255', 'required_with:certificate'],
            // O documento contratante é extraído do certificado; aceitá-lo aqui
            // devolveria à request a autoridade que D13 tira dela.
            'contratante_numero' => ['prohibited'],
        ];
    }
}
