<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexSupportLogRequest;
use App\Http\Resources\SupportAccessLogResource;
use App\Models\SupportAccessLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupportLogController extends Controller
{
    public function index(IndexSupportLogRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $logs = SupportAccessLog::with(['superAdmin', 'account'])
            ->when($data['account_id'] ?? null, fn ($query, $accountId) => $query->where('account_id', $accountId))
            ->orderByDesc('id')
            ->paginate(15);

        return SupportAccessLogResource::collection($logs);
    }
}
