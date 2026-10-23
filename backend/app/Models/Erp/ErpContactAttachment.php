<?php

namespace App\Models\Erp;

use App\Models\Erp\Concerns\BelongsToErpCompany;
use Database\Factories\Erp\ErpContactAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpContactAttachment extends Model
{
    /** @use HasFactory<ErpContactAttachmentFactory> */
    use BelongsToErpCompany, HasFactory;

    protected $fillable = [
        'erp_company_id', 'erp_contact_note_id', 'original_name', 'mime_type', 'byte_length', 'sha256', 'storage_key', 'status',
    ];

    protected $hidden = ['storage_key'];

    protected static function newFactory(): ErpContactAttachmentFactory
    {
        return ErpContactAttachmentFactory::new();
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(ErpContactNote::class, 'erp_contact_note_id');
    }
}
