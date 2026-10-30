<?php

namespace App\Support;

use App\Enums\RecurringFrequency;
use App\Exceptions\LedgerIntegrityException;
use Carbon\CarbonImmutable;

/**
 * Deterministic calendar-date recurrence engine.
 *
 * All inputs and outputs are UTC calendar dates (Y-m-d). Due dates are never
 * derived from timestamps. Monthly/quarterly/yearly advancement always uses the
 * original schedule anchor (or ends-of-month), never a previously clamped day.
 */
final class RecurrenceCalculator
{
    /**
     * Inclusive generation horizon length in calendar months from "today" (UTC).
     */
    public const HORIZON_MONTHS = 3;

    /**
     * Maximum occurrence dates returned by a single range enumeration.
     * Bounds loops even for weekly rules across the three-month horizon.
     */
    private const MAX_DATES_PER_RANGE = 64;

    /**
     * Resolves the inclusive generation window for an obligation.
     *
     * Horizon end is today (UTC) advanced by {@see HORIZON_MONTHS} calendar months
     * using year/month integer arithmetic with day-of-month clamping to the target
     * month length (so 2026-10-31 + 3 months → 2027-01-31).
     *
     * Lower bound is the obligation start_date (never before start).
     * Upper bound is horizon end (inclusive).
     * Dates before today are still generated when start_date is in the past so
     * overdue scheduled rows exist inside the window.
     *
     * @return array{0: string, 1: string} [rangeStart, horizonEnd] as Y-m-d
     */
    public static function horizonBounds(string $startDate, ?CarbonImmutable $today = null): array
    {
        $start = self::parseDate($startDate, 'Obligation start date');
        $todayUtc = ($today ?? CarbonImmutable::now('UTC'))->startOfDay();
        $horizonEnd = self::addCalendarMonthsClamped($todayUtc, self::HORIZON_MONTHS);

        return [$start->toDateString(), $horizonEnd->toDateString()];
    }

    /**
     * Returns inclusive due dates on the schedule from startDate through horizonEnd,
     * limited to dates where due >= startDate and due <= horizonEnd.
     *
     * @return list<string> Y-m-d dates in ascending order
     */
    public static function datesThroughHorizon(
        string $startDate,
        RecurringFrequency $frequency,
        int $anchorDay,
        bool $endsOfMonth,
        ?CarbonImmutable $today = null,
    ): array {
        [$rangeStart, $horizonEnd] = self::horizonBounds($startDate, $today);

        return self::datesInInclusiveRange(
            startDate: $startDate,
            rangeStart: $rangeStart,
            rangeEnd: $horizonEnd,
            frequency: $frequency,
            anchorDay: $anchorDay,
            endsOfMonth: $endsOfMonth,
        );
    }

    /**
     * @return list<string>
     */
    public static function datesInInclusiveRange(
        string $startDate,
        string $rangeStart,
        string $rangeEnd,
        RecurringFrequency $frequency,
        int $anchorDay,
        bool $endsOfMonth,
    ): array {
        self::assertValidAnchorConfiguration($frequency, $anchorDay, $endsOfMonth);

        $seriesStart = self::parseDate($startDate, 'Obligation start date');
        $from = self::parseDate($rangeStart, 'Range start date');
        $to = self::parseDate($rangeEnd, 'Range end date');

        if ($from->greaterThan($to)) {
            return [];
        }

        self::assertStartDateMatchesRule($seriesStart, $frequency, $anchorDay, $endsOfMonth);

        $cursor = $seriesStart;
        // Fast-forward weekly/biweekly without scanning every day.
        if (in_array($frequency, [RecurringFrequency::Weekly, RecurringFrequency::Biweekly], true)) {
            $stepDays = $frequency === RecurringFrequency::Weekly ? 7 : 14;
            if ($cursor->lessThan($from)) {
                $daysBehind = $cursor->diffInDays($from);
                $steps = intdiv((int) $daysBehind, $stepDays);
                $cursor = $cursor->addDays($steps * $stepDays);
                if ($cursor->lessThan($from)) {
                    $cursor = $cursor->addDays($stepDays);
                }
            }
        } else {
            while ($cursor->lessThan($from)) {
                $cursor = self::nextAfter($cursor, $seriesStart, $frequency, $anchorDay, $endsOfMonth);
            }
        }

        $dates = [];
        for ($generated = 0; $generated < self::MAX_DATES_PER_RANGE; $generated++) {
            if ($cursor->greaterThan($to)) {
                return $dates;
            }

            if ($cursor->greaterThanOrEqualTo($seriesStart) && $cursor->greaterThanOrEqualTo($from)) {
                $dates[] = $cursor->toDateString();
            }

            $cursor = self::nextAfter($cursor, $seriesStart, $frequency, $anchorDay, $endsOfMonth);
        }

        throw new LedgerIntegrityException(
            'Recurrence enumeration exceeded the maximum permitted dates for the requested range.',
        );
    }

    /**
     * Next due date strictly after $after on the series defined by $seriesStart.
     */
    public static function nextAfter(
        CarbonImmutable $after,
        CarbonImmutable $seriesStart,
        RecurringFrequency $frequency,
        int $anchorDay,
        bool $endsOfMonth,
    ): CarbonImmutable {
        self::assertValidAnchorConfiguration($frequency, $anchorDay, $endsOfMonth);

        return match ($frequency) {
            RecurringFrequency::Weekly => $after->addDays(7),
            RecurringFrequency::Biweekly => $after->addDays(14),
            RecurringFrequency::Monthly => self::addSeriesMonths($seriesStart, $after, 1, $anchorDay, $endsOfMonth),
            RecurringFrequency::Quarterly => self::addSeriesMonths($seriesStart, $after, 3, $anchorDay, $endsOfMonth),
            RecurringFrequency::Yearly => self::addSeriesMonths($seriesStart, $after, 12, $anchorDay, $endsOfMonth),
        };
    }

    /**
     * Calendar date for a target year-month using anchor day or ends-of-month.
     */
    public static function dateForYearMonth(
        int $year,
        int $month,
        int $anchorDay,
        bool $endsOfMonth,
    ): CarbonImmutable {
        if ($year < 1 || $year > 9999 || $month < 1 || $month > 12) {
            throw new LedgerIntegrityException('Recurrence year and month must form a valid calendar month.');
        }

        if ($endsOfMonth) {
            return CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->endOfMonth()->startOfDay();
        }

        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new LedgerIntegrityException('Recurring obligation anchor_day must be between 1 and 31.');
        }

        $daysInMonth = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->daysInMonth;
        $day = min($anchorDay, $daysInMonth);

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }

    public static function assertValidAnchorConfiguration(
        RecurringFrequency $frequency,
        int $anchorDay,
        bool $endsOfMonth,
    ): void {
        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new LedgerIntegrityException('Recurring obligation anchor_day must be between 1 and 31.');
        }

        if ($endsOfMonth && in_array($frequency, [RecurringFrequency::Weekly, RecurringFrequency::Biweekly], true)) {
            throw new LedgerIntegrityException(
                'Ends-of-month scheduling is only valid for monthly, quarterly, or yearly frequencies.',
            );
        }
    }

    public static function assertStartDateMatchesRule(
        CarbonImmutable $startDate,
        RecurringFrequency $frequency,
        int $anchorDay,
        bool $endsOfMonth,
    ): void {
        self::assertValidAnchorConfiguration($frequency, $anchorDay, $endsOfMonth);

        if (in_array($frequency, [RecurringFrequency::Weekly, RecurringFrequency::Biweekly], true)) {
            return;
        }

        $expected = self::dateForYearMonth(
            $startDate->year,
            $startDate->month,
            $anchorDay,
            $endsOfMonth,
        );

        if ($startDate->toDateString() !== $expected->toDateString()) {
            throw new LedgerIntegrityException(
                'Obligation start_date must match the anchor day / ends-of-month rule for its calendar month.',
            );
        }
    }

    public static function parseDate(string $value, string $label): CarbonImmutable
    {
        $trimmed = trim($value);

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $trimmed) !== 1) {
            throw new LedgerIntegrityException("{$label} must be a valid Y-m-d calendar date.");
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $trimmed, 'UTC');

        if ($date === false || $date->format('Y-m-d') !== $trimmed) {
            throw new LedgerIntegrityException("{$label} must be a valid Y-m-d calendar date.");
        }

        return $date->startOfDay();
    }

    /**
     * Advance from an on-series due date by $stepMonths along the original series.
     * Month offsets are counted from the series start month, never from a clamped prior due date.
     */
    private static function addSeriesMonths(
        CarbonImmutable $seriesStart,
        CarbonImmutable $after,
        int $stepMonths,
        int $anchorDay,
        bool $endsOfMonth,
    ): CarbonImmutable {
        $startIndex = ($seriesStart->year * 12) + ($seriesStart->month - 1);
        $afterIndex = ($after->year * 12) + ($after->month - 1);
        $monthsAfterStart = $afterIndex - $startIndex;
        $absoluteMonth = $startIndex + $monthsAfterStart + $stepMonths;
        $year = intdiv($absoluteMonth, 12);
        $month = ($absoluteMonth % 12) + 1;

        return self::dateForYearMonth($year, $month, $anchorDay, $endsOfMonth);
    }

    private static function addCalendarMonthsClamped(CarbonImmutable $date, int $months): CarbonImmutable
    {
        $absoluteMonth = (($date->year * 12) + ($date->month - 1)) + $months;
        $year = intdiv($absoluteMonth, 12);
        $month = ($absoluteMonth % 12) + 1;
        $daysInMonth = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->daysInMonth;
        $day = min($date->day, $daysInMonth);

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
