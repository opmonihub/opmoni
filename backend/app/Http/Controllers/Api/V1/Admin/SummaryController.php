<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportAccessLogResource;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SupportAccessLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SummaryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $logs = SupportAccessLog::with(['superAdmin', 'account'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get();

        return response()->json(['data' => [
            'totals' => [
                'accounts' => Account::count(),
                'plans' => Plan::count(),
                'subscriptions' => Subscription::count(),
                'users' => User::count(),
            ],
            'recent_support_logs' => SupportAccessLogResource::collection($logs),
        ]]);
    }
}
