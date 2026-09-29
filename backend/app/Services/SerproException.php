<?php

namespace App\Services;

use App\Enums\SerproFailure;
use RuntimeException;

final class SerproException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly SerproFailure $failure,
        public readonly int $status,
        public readonly ?string $providerCode = null,
        public readonly ?string $responseId = null,
        // A tag sai junto porque a tentativa também é auditada quando falha:
        // o relatório de cobrança do provedor a indexa por ela, e uma exceção
        // sem tag não chega ao `serpro_calls`.
        public readonly ?string $requestTag = null,
    ) {
        parent::__construct($message);
    }

    public static function classify(int $status, string $providerCode): SerproFailure
    {
        if ($status === 200 || $status === 202) {
            return SerproFailure::Success;
        }

        if ($status === 401 || in_array($providerCode, SerproFailure::reauthenticateCodes(), true)) {
            return SerproFailure::Reauthenticate;
        }

        if (in_array($providerCode, SerproFailure::resubmitTermCodes(), true)) {
            return SerproFailure::ResubmitTerm;
        }

        if ($status === 429) {
            return SerproFailure::Throttled;
        }

        if ($status === 504) {
            return SerproFailure::Indeterminate;
        }

        if ($status >= 500) {
            return SerproFailure::Upstream;
        }

        return SerproFailure::DoNotRetry;
    }
}
