<?php

namespace App\Models\Erp\Stock;

use Database\Factories\Erp\Stock\ErpPurchaseImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpPurchaseImport extends Model
{
    /** @use HasFactory<ErpPurchaseImportFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['token_digest', 'source_snapshot', 'completed_key_digest', 'completed_payload_hash', 'original_result'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'errors' => 'array', 'original_result' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function newFactory(): ErpPurchaseImportFactory
    {
        return ErpPurchaseImportFactory::new();
    }
}
