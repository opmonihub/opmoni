<?php

namespace App\Actions;

use App\Models\Account;
use App\Support\BrazilianRegions;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdatePlatformAccountContact
{
    public const FIELDS = ['phone', 'phone_whatsapp', 'state', 'city'];

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone_whatsapp' => ['sometimes', 'boolean'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || BrazilianRegions::forState($value) === null) {
                    $fail('Informe uma UF brasileira válida.');
                }
            }],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Merge contacts while the caller holds the Account lock and transaction.
     *
     * @param  array<string, mixed>  $fields
     */
    public function handle(Account $account, array $fields): Account
    {
        $fields = Validator::make(Arr::only($fields, self::FIELDS), self::rules())->validate();
        if ($fields === []) {
            return $account;
        }
        foreach (['phone', 'state', 'city'] as $field) {
            if (isset($fields[$field])) {
                $fields[$field] = trim($fields[$field]);
            }
        }
        if (isset($fields['state'])) {
            $fields['state'] = strtoupper($fields['state']);
        }
        if (isset($fields['phone_whatsapp'])) {
            $fields['phone_whatsapp'] = (bool) $fields['phone_whatsapp'];
        }
        $contact = array_merge($account->platformBillingContact() ?? [], $fields);
        foreach (['phone', 'state', 'city'] as $field) {
            if (array_key_exists($field, $fields) && ($fields[$field] === null || $fields[$field] === '')) {
                unset($contact[$field]);
            }
        }
        $hasPhone = isset($contact['phone']) && $contact['phone'] !== '';
        if (array_key_exists('phone', $fields) && ! $hasPhone) {
            unset($contact['phone_whatsapp']);
        } elseif (! empty($contact['phone_whatsapp']) && ! $hasPhone) {
            throw ValidationException::withMessages(['phone_whatsapp' => 'Informe um telefone para habilitar WhatsApp.']);
        }
        $account->update(['settings' => Account::mergePlatformBillingContact($account->settings, $contact)]);

        return $account;
    }
}
