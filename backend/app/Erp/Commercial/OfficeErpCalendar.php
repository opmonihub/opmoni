<?php

namespace App\Erp\Commercial;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class OfficeErpCalendar
{
    /**
     * @return array{start: string, end: string, due_on: string}
     */
    public function period(string $anchor, int $intervalMonths, int $index, string $timezone, int $dueInDays): array
    {
        $this->assertSupportedInterval($intervalMonths);

        if ($index < 0 || $index > intdiv(PHP_INT_MAX, max(1, $intervalMonths))) {
            throw ValidationException::withMessages(['index' => 'The period index must be a nonnegative calendar index.']);
        }

        return $this->periodAtMonthOffset($anchor, $intervalMonths, $index * $intervalMonths, $timezone, $dueInDays);
    }

    /**
     * @return array{start: string, end: string, due_on: string}
     */
    public function periodAtMonthOffset(string $anchor, int $intervalMonths, int $monthOffset, string $timezone, int $dueInDays): array
    {
        $this->assertSupportedInterval($intervalMonths);

        if ($monthOffset < 0 || $monthOffset > PHP_INT_MAX - $intervalMonths) {
            throw ValidationException::withMessages(['month_offset' => 'The month offset must be nonnegative.']);
        }

        if ($dueInDays < 0) {
            throw ValidationException::withMessages(['due_in_days' => 'The due period must be nonnegative.']);
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) && $timezone !== 'UTC') {
            throw ValidationException::withMessages(['timezone' => 'The billing timezone must be a valid IANA timezone.']);
        }

        $zone = new DateTimeZone($timezone);
        $anchorDate = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, $zone);
        $dateErrors = DateTimeImmutable::getLastErrors();

        if ($anchorDate === false || $anchorDate->format('Y-m-d') !== $anchor || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw ValidationException::withMessages(['anchor' => 'The billing anchor must be a valid calendar date in YYYY-MM-DD format.']);
        }

        try {
            $start = $this->dateAtMonthOffset($anchorDate, $monthOffset);
            $end = $this->dateAtMonthOffset($anchorDate, $monthOffset + $intervalMonths);
            $dueOn = $start->modify(sprintf('+%d days', $dueInDays));

            if ($dueOn === false) {
                throw new \OutOfRangeException('Due date exceeds the supported date range.');
            }
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['month_offset' => 'The calendar offset is outside the supported date range.']);
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'due_on' => $dueOn->format('Y-m-d'),
        ];
    }

    private function assertSupportedInterval(int $intervalMonths): void
    {
        if (! in_array($intervalMonths, [1, 3, 6, 12], true)) {
            throw ValidationException::withMessages(['interval_months' => 'The billing interval must be 1, 3, 6, or 12 months.']);
        }
    }

    private function dateAtMonthOffset(DateTimeImmutable $anchor, int $monthOffset): DateTimeImmutable
    {
        $monthIndex = ((int) $anchor->format('Y') - 1) * 12 + ((int) $anchor->format('n') - 1) + $monthOffset;
        $year = intdiv($monthIndex, 12) + 1;
        $month = $monthIndex % 12 + 1;

        if ($year > 9999) {
            throw new \OutOfRangeException('Calendar period exceeds the supported year range.');
        }

        $firstOfMonth = $anchor->setDate($year, $month, 1);
        $day = min((int) $anchor->format('j'), (int) $firstOfMonth->format('t'));

        return $firstOfMonth->setDate($year, $month, $day);
    }
}
