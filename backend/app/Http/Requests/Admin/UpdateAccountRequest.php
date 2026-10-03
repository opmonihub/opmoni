<?php

namespace App\Http\Requests\Admin;

use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Account $account */
        $account = $this->route('account');

        return Gate::allows('update', $account);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,suspended'],
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

    /**
     * @return array<string, mixed>
     */
    public function billingContact(): array
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

        return $contact;
    }

    /**
     * @return array<string, mixed>
     */
    public function accessPayload(): array
    {
        $data = $this->validated();

        return [
            'owner_name' => $data['owner_name'] ?? null,
            'login_email' => $data['login_email'] ?? null,
            'access' => $data['access'] ?? 'none',
            'password' => $data['password'] ?? null,
        ];
    }
}
