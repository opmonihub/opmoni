<?php

namespace App\Http\Requests\Admin;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Criação manual pelo painel Admin. Telefone/localidade ficam em contato
 * comercial da plataforma; login do administrador é opcional neste passo.
 */
class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Account::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,suspended'],
            'owner_name' => ['nullable', 'string', 'max:255', 'required_with:login_email'],
            'login_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'access' => ['nullable', Rule::in(['none', 'password_now', 'first_access'])],
            'password' => ['nullable', 'string', 'min:8', 'required_if:access,password_now'],
            'phone' => ['nullable', 'string', 'max:20'],
            'phone_whatsapp' => ['nullable', 'boolean'],
            'state' => ['nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'city' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $access = $this->input('access');

        if ($access === null || $access === '') {
            $this->merge([
                'access' => $this->filled('login_email') ? 'password_now' : 'none',
            ]);
        }
    }

    /**
     * @return array{
     *     name: string,
     *     status: string,
     *     settings: array<string, mixed>|null,
     *     owner_name?: string|null,
     *     login_email?: string|null,
     *     access: string,
     *     password?: string|null
     * }
     */
    public function provisionerPayload(): array
    {
        $data = $this->validated();
        $contact = [];

        if (! empty($data['phone'])) {
            $contact['phone'] = $data['phone'];
            $contact['phone_whatsapp'] = (bool) ($data['phone_whatsapp'] ?? false);
        }

        if (! empty($data['state'])) {
            $contact['state'] = strtoupper($data['state']);
        }

        if (! empty($data['city'])) {
            $contact['city'] = $data['city'];
        }

        return [
            'name' => $data['name'],
            'status' => $data['status'] ?? 'active',
            'settings' => Account::settingsWithPlatformBillingContact($contact),
            'owner_name' => $data['owner_name'] ?? null,
            'login_email' => $data['login_email'] ?? null,
            'access' => $data['access'] ?? 'none',
            'password' => $data['password'] ?? null,
        ];
    }
}
