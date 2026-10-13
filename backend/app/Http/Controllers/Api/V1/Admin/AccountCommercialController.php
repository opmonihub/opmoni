<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountCommercialController extends Controller
{
    public function show(Account $account): AccountResource
    {
        return $this->present($account);
    }

    public function update(Request $request, Account $account): AccountResource
    {
        $data = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'phone_whatsapp' => ['sometimes', 'nullable', 'boolean'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'owner_name' => ['prohibited'], 'login_email' => ['prohibited'],
            'password' => ['prohibited'], 'access' => ['prohibited'],
        ]);

        return DB::transaction(function () use ($account, $data): AccountResource {
            $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $contact = array_merge($locked->platformBillingContact() ?? [], $data);
            if (isset($contact['state'])) {
                $contact['state'] = strtoupper($contact['state']);
            }
            $contact = array_filter($contact, fn ($value) => $value !== null && $value !== '');
            if (! isset($contact['phone'])) {
                unset($contact['phone_whatsapp']);
            }
            $locked->update(['settings' => Account::mergePlatformBillingContact($locked->settings, $contact)]);

            return $this->present($locked);
        });
    }

    private function present(Account $account): AccountResource
    {
        return new AccountResource($account->load('subscription.plan')->loadCount('members'));
    }
}
