<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdentityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $accounts = Account::query()->join('account_memberships', 'accounts.id', '=', 'account_memberships.account_id')->where('account_memberships.user_id', $this->id)->orderBy('accounts.id')->get(['accounts.id', 'accounts.name', 'accounts.status', 'account_memberships.role'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status, 'role' => $a->role])->all();
        $current = collect($accounts)->first(fn ($a) => $a['id'] == $request->session()->get('current_account_id'));

        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'is_super_admin' => $this->is_super_admin, 'accounts' => $accounts, 'current_account' => $current];
    }
}
