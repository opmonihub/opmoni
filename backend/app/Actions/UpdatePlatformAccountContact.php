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
        $contact = array_merge($account->platformBillingContact() ?? [], $fields);
        foreach (['phone', 'state', 'city'] as $field) {
            if (isset($contact[$field])) {
                $contact[$field] = trim($contact[$field]);
            }
        }
        if (isset($contact['state'])) {
            $contact['state'] = strtoupper($contact['state']);
        }
        $contact = array_filter($contact, fn ($value) => $value !== null && $value !== '');
        if (array_key_exists('phone', $fields) && ! isset($contact['phone'])) {
            unset($contact['phone_whatsapp']);
        } elseif (! empty($contact['phone_whatsapp']) && ! isset($contact['phone'])) {
            throw ValidationException::withMessages(['phone_whatsapp' => 'Informe um telefone para habilitar WhatsApp.']);
        }
        if (isset($contact['phone_whatsapp'])) {
            $contact['phone_whatsapp'] = (bool) $contact['phone_whatsapp'];
        }
        $account->update(['settings' => Account::mergePlatformBillingContact($account->settings, $contact)]);

        return $account;
    }
}
