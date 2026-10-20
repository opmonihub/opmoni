<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpCatalogImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpCatalogImport extends Model
{
    /** @use HasFactory<ErpCatalogImportFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = ['erp_company_id', 'actor_user_id', 'kind', 'schema_version', 'token_digest', 'content_digest', 'rows', 'errors', 'totals', 'expires_at', 'completed_at', 'completed_key_digest', 'completed_payload_hash', 'result'];

    protected $hidden = ['token_digest', 'content_digest', 'rows', 'errors'];

    protected function casts(): array
    {
        return ['rows' => 'array', 'errors' => 'array', 'totals' => 'array', 'result' => 'array', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): ErpCatalogImportFactory
    {
        return ErpCatalogImportFactory::new();
    }
}
