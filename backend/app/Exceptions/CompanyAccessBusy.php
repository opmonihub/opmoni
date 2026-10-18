<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

class CompanyAccessBusy extends HttpResponseException
{
    public function __construct()
    {
        parent::__construct(response()->json([
            'code' => 'company_access_busy',
            'message' => 'Acesso à empresa ocupado. Tente novamente.',
        ], 503, ['Retry-After' => '1', 'Cache-Control' => 'no-store']));
    }
}
