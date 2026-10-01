<?php

namespace App\Http\Requests\Tenant;

use App\Models\Account;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSerproEnablementRequest extends FormRequest
{
    /**
     * O flag de habilitação é da plataforma, e não do escritório: quem liga o
     * SERPRO para a conta é o `is_super_admin`, na conta corrente — o mesmo
     * arranjo do modo suporte. `AccountPolicy::update` não é o guarda daqui
     * porque ele cobre a edição da Account, que o `admin` da conta faz.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        $account = Account::query()->find(resolve(CurrentTenant::class)->accountId);

        return $user instanceof User && $user->isSuperAdmin() && $account instanceof Account;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
