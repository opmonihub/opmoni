<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpMemberAuthority;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpAccessDeliveryController
{
    public function show(Request $request, int $company, int $delivery, OfficeErpAdmission $admission, OfficeErpMemberAuthority $authority): JsonResponse
    {
        $ctx = $admission->context($request);
        $currentManagement = $authority->resolve($ctx, $company);
        $record = OfficeErpRecords::query($ctx, OfficeErpAccessDelivery::class)
            ->where('erp_company_id', $company)
            ->whereKey($delivery)
            ->firstOrFail();
        abort_unless((int) $record->registration_id === (int) $currentManagement['registration']->getKey(), 404);

        return response()->json(['data' => [
            'id' => (int) $record->getKey(),
            'state' => (string) $record->state,
            'expires_at' => $record->expires_at->toIso8601String(),
            'authority_invalidated_at' => $record->authority_invalidated_at?->toIso8601String(),
        ]])->header('Cache-Control', 'no-store');
    }
}
