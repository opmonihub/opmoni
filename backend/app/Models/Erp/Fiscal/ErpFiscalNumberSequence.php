<?php

namespace App\Models\Erp\Fiscal;

use Illuminate\Database\Eloquent\Model;

class ErpFiscalNumberSequence extends Model
{
    protected $fillable = ['erp_company_id', 'model', 'environment', 'series', 'next_number'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['series' => 'integer', 'next_number' => 'integer'];
    }
}
