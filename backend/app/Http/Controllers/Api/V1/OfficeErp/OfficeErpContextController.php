<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpContextController
{
    public function __invoke(Request $request, OfficeErpAdmission $admission): JsonResponse
    {
        $capabilities = $admission->capabilities($admission->context($request));
        $capabilities['reasons'] = (object) $capabilities['reasons'];

        return response()->json(['data' => $capabilities]);
    }
}
