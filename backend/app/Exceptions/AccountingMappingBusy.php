<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

class AccountingMappingBusy extends HttpResponseException
{
    public function __construct()
    {
        parent::__construct(response()->json([
            'code' => 'accounting_mapping_busy',
            'message' => 'Entrega contábil ocupada. Tente novamente.',
        ], 503, ['Retry-After' => '1', 'Cache-Control' => 'no-store']));
    }
}
