<?php

namespace Tests\Support;

use App\Erp\Accounting\ErpAccountingMappingService;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use App\Support\CurrentAccount;

trait ApprovesErpAccountingMappings
{
    protected function approveAccountingMapping(ErpOfficeDelegation $delegation, ErpEstablishment $establishment, Client $client): ErpAccountingMapping
    {
        $account = Account::query()->findOrFail($delegation->account_id);
        $approver = User::factory()->create();
        $membership = AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $approver->id, 'role' => 'admin']);

        return app(ErpAccountingMappingService::class)->approve(new CurrentAccount($account, $membership), $delegation->id, $establishment->id, $client->id);
    }
}
