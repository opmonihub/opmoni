<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\Commercial\OfficeErpTrialLinks;
use App\Erp\Commercial\OfficeErpTrialRegistration;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErpTrialController extends Controller
{
    /** @var array<string, string> */
    private const REGISTRATION_CONFLICT_MESSAGES = [
        'client_association_requires_owner_verification' => 'Entre na conta proprietária do escritório para confirmar a associação deste cliente.',
        'confirm_existing_association_required' => 'Confirme que este cliente pertence à carteira do escritório.',
        'company_association_required' => 'Esta empresa já está vinculada a outra conta.',
        'company_client_association_required' => 'A associação desta empresa ao cliente precisa ser confirmada pelo escritório.',
        'trial_already_used' => 'Este CNPJ já utilizou o período de teste.',
        'client_not_active' => 'O cliente associado não está ativo e não pode ser usado no cadastro.',
        'company_subscription_ineligible' => 'Esta empresa já possui uma assinatura incompatível com o período de teste.',
        'trial_link_changed' => 'Este link foi atualizado. Recarregue a página e tente novamente.',
        'trial_plan_modules_changed' => 'O plano deste link foi atualizado. Recarregue a página e tente novamente.',
        'idempotency_conflict' => 'Esta chave de operação já foi usada em outra solicitação. Atualize a página e tente novamente.',
    ];

    public function show(Request $request, string $code, OfficeErpTrialLinks $links): JsonResponse
    {
        return response()->json(['data' => $links->resolvePublic($code)])
            ->header('Cache-Control', 'no-store');
    }

    public function register(Request $request, string $code, OfficeErpTrialRegistration $registration): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20'],
            'tax_regime' => ['required', 'string', 'in:mei,simple_national,presumed_profit,actual_profit,other'],
            'establishment_name' => ['nullable', 'string', 'max:255'],
            'confirm_existing_association' => ['sometimes', 'boolean'],
            'client_id' => ['prohibited'],
            'account_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
        ]);

        try {
            $result = $registration->register(
                $request,
                $code,
                $input,
                (string) $request->header('Idempotency-Key', ''),
            );
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            $errorCode = $exception->getMessage();
            if ($errorCode === '') {
                $errorCode = 'idempotency_conflict';
            }
            $message = self::REGISTRATION_CONFLICT_MESSAGES[$errorCode] ?? null;
            if ($message === null) {
                throw $exception;
            }

            return response()->json(['code' => $errorCode, 'message' => $message], 409)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }

    public function current(Request $request, OfficeErpTrialRegistration $registration): JsonResponse
    {
        return response()->json([
            'data' => $registration->current($request, (string) $request->header('Idempotency-Key', '')),
        ])->header('Cache-Control', 'no-store');
    }
}
