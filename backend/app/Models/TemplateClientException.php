<?php

namespace App\Models;

use App\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['template_id', 'client_id', 'kind'])]
class TemplateClientException extends Model
{
    use BelongsToAccount;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'template_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
