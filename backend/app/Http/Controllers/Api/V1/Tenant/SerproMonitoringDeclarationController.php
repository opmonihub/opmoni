<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SerproMonitoring;
use App\Services\SerproException;
use App\Services\SerproPgdasDocumentReader;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Detalhe e PDFs de declaração PGDAS sob demanda.
 */
class SerproMonitoringDeclarationController extends Controller
{
    public function show(string $obligation, int $client): JsonResponse
    {
        Gate::authorize('viewAny', SerproMonitoring::class);
        abort_unless($obligation === 'declaracoes/pgdas', 404);

        $registro = SerproMonitoring::query()
            ->where('client_id', $client)
            ->where('obligation', $obligation)
            ->first();

        abort_if($registro === null, 404);

        return response()->json([
            'data' => [
                'client_id' => $client,
                'obligation' => $obligation,
                'periods' => $registro->periods ?? [],
                'consulted_at' => $registro->source_at?->toIso8601String(),
            ],
        ]);
    }

    public function download(
        string $obligation,
        int $client,
        string $period,
        string $artifact,
        SerproPgdasDocumentReader $reader,
    ): StreamedResponse {
        Gate::authorize('downloadDeclaration', SerproMonitoring::class);
        abort_unless($obligation === 'declaracoes/pgdas', 404);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $period) === 1, 422);

        $accountId = (int) resolve(CurrentTenant::class)->accountId;

        try {
            $arquivo = $reader->download($accountId, $client, $period, $artifact);
        } catch (SerproException $exception) {
            abort(502, $exception->failure->label());
        }

        return response()->streamDownload(
            static function () use ($arquivo): void {
                echo $arquivo['bytes'];
            },
            $arquivo['filename'],
            ['Content-Type' => $arquivo['content_type']],
        );
    }
}
