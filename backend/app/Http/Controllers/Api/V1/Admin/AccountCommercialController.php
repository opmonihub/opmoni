<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\UpdatePlatformAccountContact;
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
            ...UpdatePlatformAccountContact::rules(),
            'owner_name' => ['prohibited'], 'login_email' => ['prohibited'],
            'password' => ['prohibited'], 'access' => ['prohibited'],
        ]);

        return DB::transaction(function () use ($account, $data): AccountResource {
            $locked = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            app(UpdatePlatformAccountContact::class)->handle($locked, $data);

            return $this->present($locked);
        });
    }

    private function present(Account $account): AccountResource
    {
        return new AccountResource($account->load('subscription.plan')->loadCount('members'));
    }
}
