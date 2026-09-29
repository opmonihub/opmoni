<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SerproMonitoring;
use App\Services\SerproMonitoringReader;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * O painel do monitoramento: a carteira sincronizada e a atenção por
 * obrigação, na mesma consulta de onde as listagens saem — overview e
 * listagem só concordam se a conta for a mesma.
 */
class SerproMonitoringOverviewController extends Controller
{
    public function __invoke(SerproMonitoringReader $reader): JsonResponse
    {
        Gate::authorize('viewAny', SerproMonitoring::class);

        return response()->json([
            'data' => $reader->overview((int) resolve(CurrentTenant::class)->accountId),
        ]);
    }
}
