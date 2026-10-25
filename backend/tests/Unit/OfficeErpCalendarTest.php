<?php

namespace Tests\Unit;

use App\Erp\Commercial\OfficeErpCalendar;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OfficeErpCalendarTest extends TestCase
{
    public function test_periods_derive_month_end_boundaries_from_the_original_anchor(): void
    {
        $calendar = new OfficeErpCalendar;

        $this->assertSame([
            'start' => '2027-02-28',
            'end' => '2027-03-31',
            'due_on' => '2027-03-05',
        ], $calendar->period('2027-01-31', 1, 1, 'America/Sao_Paulo', 5));
        $this->assertSame('2028-02-29', $calendar->period('2024-02-29', 12, 4, 'America/Sao_Paulo', 0)['start']);
    }

    #[DataProvider('cyclePeriods')]
    public function test_period_supports_declared_cycle_intervals(string $anchor, int $intervalMonths, int $index, string $expectedStart, string $expectedEnd): void
    {
        $period = (new OfficeErpCalendar)->period($anchor, $intervalMonths, $index, 'UTC', 0);

        $this->assertSame($expectedStart, $period['start']);
        $this->assertSame($expectedEnd, $period['end']);
    }

    /** @return array<string, array{string, int, int, string, string}> */
    public static function cyclePeriods(): array
    {
        return [
            'monthly' => ['2027-01-31', 1, 2, '2027-03-31', '2027-04-30'],
            'quarterly' => ['2027-01-31', 3, 1, '2027-04-30', '2027-07-31'],
            'semiannual' => ['2027-01-31', 6, 1, '2027-07-31', '2028-01-31'],
            'annual' => ['2024-02-29', 12, 4, '2028-02-29', '2029-02-28'],
        ];
    }

    public function test_period_uses_the_requested_timezone_for_calendar_dates(): void
    {
        $period = (new OfficeErpCalendar)->period('2027-03-13', 1, 0, 'America/Los_Angeles', 1);

        $this->assertSame([
            'start' => '2027-03-13',
            'end' => '2027-04-13',
            'due_on' => '2027-03-14',
        ], $period);
    }

    public function test_period_at_month_offset_keeps_interval_change_boundaries_contiguous(): void
    {
        $calendar = new OfficeErpCalendar;

        $quarterly = $calendar->periodAtMonthOffset('2027-01-31', 3, 0, 'America/Sao_Paulo', 0);
        $nextMonthly = $calendar->periodAtMonthOffset('2027-01-31', 1, 3, 'America/Sao_Paulo', 0);

        $this->assertSame('2027-04-30', $quarterly['end']);
        $this->assertSame($quarterly['end'], $nextMonthly['start']);
        $this->assertSame('2027-05-31', $nextMonthly['end']);
    }

    public function test_period_at_month_offset_applies_cumulative_months_without_day_arithmetic(): void
    {
        $period = (new OfficeErpCalendar)->periodAtMonthOffset('2027-01-31', 3, 9, 'UTC', 2);

        $this->assertSame([
            'start' => '2027-10-31',
            'end' => '2028-01-31',
            'due_on' => '2027-11-02',
        ], $period);
    }

    #[DataProvider('invalidPeriods')]
    public function test_period_rejects_invalid_interval_index_due_days_and_timezone(int $intervalMonths, int $index, int $dueInDays, string $timezone): void
    {
        $this->expectException(ValidationException::class);

        (new OfficeErpCalendar)->period('2027-01-31', $intervalMonths, $index, $timezone, $dueInDays);
    }

    /** @return array<string, array{int, int, int, string}> */
    public static function invalidPeriods(): array
    {
        return [
            'unsupported interval' => [2, 0, 0, 'UTC'],
            'negative period index' => [1, -1, 0, 'UTC'],
            'negative due days' => [1, 0, -1, 'UTC'],
            'unknown timezone' => [1, 0, 0, 'Not/A-Timezone'],
        ];
    }

    public function test_period_at_month_offset_rejects_negative_offsets(): void
    {
        $this->expectException(ValidationException::class);

        (new OfficeErpCalendar)->periodAtMonthOffset('2027-01-31', 1, -1, 'UTC', 0);
    }

    public function test_period_rejects_invalid_anchor_dates(): void
    {
        $this->expectException(ValidationException::class);

        (new OfficeErpCalendar)->period('2027-02-29', 1, 0, 'UTC', 0);
    }
}
