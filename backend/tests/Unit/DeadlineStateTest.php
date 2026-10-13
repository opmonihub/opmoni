<?php

namespace Tests\Unit;

use App\Enums\DeadlineStatus;
use App\Services\DeadlineState;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DeadlineStateTest extends TestCase
{
    public function test_derives_deadline_states_at_boundaries(): void
    {
        CarbonImmutable::setTestNow('2026-09-22 12:00:00');
        try {
            $service = new DeadlineState;

            $this->assertSame(DeadlineStatus::Missing, $service->for(null));
            $this->assertSame(DeadlineStatus::Expired, $service->for(CarbonImmutable::parse('2026-09-21')));
            $this->assertSame(DeadlineStatus::Expiring, $service->for(CarbonImmutable::parse('2026-09-22')));
            $this->assertSame(DeadlineStatus::Expiring, $service->for(CarbonImmutable::parse('2026-10-22')));
            $this->assertSame(DeadlineStatus::Valid, $service->for(CarbonImmutable::parse('2026-10-23')));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
