<?php

namespace App\Models\Erp\Commercial;

use App\Models\Client;
use App\Models\Erp\ErpCompany;
use Database\Factories\Erp\Commercial\OfficeErpCompanyRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpCompanyRegistration extends Model
{
    /** @use HasFactory<OfficeErpCompanyRegistrationFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'client_id', 'erp_company_id', 'created_by_user_id', 'registered_at'];

    protected function casts(): array
    {
        return ['registered_at' => 'immutable_datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }
}
