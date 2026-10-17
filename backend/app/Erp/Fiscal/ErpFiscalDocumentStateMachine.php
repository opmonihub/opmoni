<?php

namespace App\Erp\Fiscal;

use InvalidArgumentException;

final class ErpFiscalDocumentStateMachine
{
    public const DRAFT = 'draft';

    public const QUEUED = 'queued';

    public const SUBMITTING = 'submitting';

    public const AUTHORIZED = 'authorized';

    public const REJECTED = 'rejected';

    public const INDETERMINATE = 'indeterminate';

    public const CANCELED = 'canceled';

    public const CONTINGENCY_LOCAL = 'contingency_local';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::DRAFT => [self::QUEUED, self::CONTINGENCY_LOCAL],
        self::QUEUED => [self::SUBMITTING, self::CONTINGENCY_LOCAL],
        self::SUBMITTING => [self::AUTHORIZED, self::REJECTED, self::INDETERMINATE],
        self::AUTHORIZED => [self::CANCELED],
        self::REJECTED => [self::QUEUED],
        self::INDETERMINATE => [self::AUTHORIZED, self::REJECTED, self::QUEUED],
        self::CONTINGENCY_LOCAL => [self::QUEUED],
    ];

    public function transition(string $from, string $to): string
    {
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException("Invalid fiscal transition from {$from} to {$to}.");
        }

        return $to;
    }

    public function queueFromDraft(string $status): string
    {
        return $this->transition($status, self::QUEUED);
    }
}
