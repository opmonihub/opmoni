<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportAccessLogResource;
use App\Models\SupportAccessLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupportLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'account_id' => ['sometimes', 'integer', 'exists:accounts,id'],
        ]);

        $logs = SupportAccessLog::with(['superAdmin', 'account'])
            ->when($data['account_id'] ?? null, fn ($query, $accountId) => $query->where('account_id', $accountId))
            ->orderByDesc('id')
            ->paginate(15);

        return SupportAccessLogResource::collection($logs);
    }
}
