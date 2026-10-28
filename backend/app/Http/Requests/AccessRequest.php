<?php

namespace App\Http\Requests;

use App\Actions\UpdatePlatformAccountContact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'company', 'owner_name', 'email', 'login_email'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => in_array($key, ['email', 'login_email']) ? mb_strtolower(trim($this->input($key))) : trim($this->input($key))]);
            }
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $id = $this->route('id');
            if ($id !== null && (! ctype_digit((string) $id) || strlen((string) $id) > 16 || (int) $id < 1 || (int) $id > 9007199254740991)) {
                $validator->errors()->add('id', 'Identificador inválido.');
            }
            if ($this->route()->getName() === 'access.admin-list' && $this->query->has('direction') && ! $this->query->has('sort')) {
                $validator->errors()->add('direction', 'A direção exige um campo de ordenação.');
            }
        });
    }

    public function rules(): array
    {
        $name = ['required', 'string', 'min:2', 'max:255'];
        $email = ['required', 'string', 'email', 'max:254'];
        $password = ['required', 'string', 'min:8', 'max:128'];
        $rules = match ($this->route()->getName()) {
            'access.register' => ['name' => $name, 'email' => $email, 'password' => $password, 'company' => $name, 'size' => ['required', Rule::in(['solo', '2-10', '11-50', '51-200', '200+'])]],
            'access.login' => ['email' => $email, 'password' => ['required', 'string', 'min:1', 'max:128']],
            'access.switch' => ['account_id' => ['required', 'integer', 'min:1', 'max:9007199254740991']],
            'access.activate' => ['email' => $email, 'token' => ['required', 'string', 'size:64'], 'password' => [...$password, 'confirmed'], 'password_confirmation' => $password],
            'access.activation-status' => ['email' => ['sometimes', 'nullable', 'string', 'max:254'], 'token' => ['sometimes', 'nullable', 'string', 'max:128']],
            'access.admin-create' => ['name' => $name, 'owner_name' => $name, 'login_email' => $email, ...UpdatePlatformAccountContact::rules()],
            'access.admin-reissue' => ['owner_name' => $name, 'login_email' => $email],
            'access.admin-update' => ['name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'], 'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])], ...UpdatePlatformAccountContact::rules()],
            'access.admin-list' => [
                'q' => ['sometimes', 'nullable', 'string', 'max:255'],
                'status' => ['sometimes', 'nullable', Rule::in(['active', 'suspended'])],
                'page' => ['sometimes', 'integer', 'min:1', 'max:9007199254740991'],
                'sort' => ['sometimes', 'string', Rule::in(['id', 'name', 'status'])],
                'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            ],
            default => []
        };
        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $key) {
            $rules[$key] = ['prohibited'];
        }
        if ($this->route()->getName() === 'access.admin-update' && ! $this->hasAny(['name', 'status', ...UpdatePlatformAccountContact::FIELDS])) {
            $rules['name'] = ['required'];
        }

        return $rules;
    }
}
