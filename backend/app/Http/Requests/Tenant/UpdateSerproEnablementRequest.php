<?php

namespace App\Http\Requests\Tenant;

use App\Models\Account;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSerproEnablementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = Account::query()->find(resolve(CurrentTenant::class)->accountId);

        return $account instanceof Account && Gate::allows('update', $account);
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
