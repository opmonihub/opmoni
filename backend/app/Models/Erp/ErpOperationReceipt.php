<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Model;

class ErpOperationReceipt extends Model
{
    protected $fillable = ['erp_company_id', 'actor_user_id', 'operation', 'key_digest', 'canonical_version', 'payload_hash', 'response_status', 'response_body', 'resource_refs', 'recorded_at'];

    protected function casts(): array
    {
        return ['response_body' => 'array', 'resource_refs' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
