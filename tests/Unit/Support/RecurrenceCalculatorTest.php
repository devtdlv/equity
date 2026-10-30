<?php

use App\Enums\RecurringFrequency;
use App\Exceptions\LedgerIntegrityException;
use App\Support\RecurrenceCalculator;
use Carbon\CarbonImmutable;

test('weekly and biweekly advance by fixed day counts without timestamp math', function (): void {
    $start = RecurrenceCalculator::parseDate('2026-10-06', 'start');

    $weekly = RecurrenceCalculator::datesInInclusiveRange(
        startDate: '2026-10-06',
        rangeStart: '2026-10-06',
        rangeEnd: '2026-10-27',
        frequency: RecurringFrequency::Weekly,
        anchorDay: 6,
        endsOfMonth: false,
    );

    expect($weekly)->toBe([
        '2026-10-06',
        '2026-10-13',
        '2026-10-20',
        '2026-10-27',
    ]);

    $next = RecurrenceCalculator::nextAfter(
        $start,
        $start,
        RecurringFrequency::Biweekly,
        6,
        false,
    );
    expect($next->toDateString())->toBe('2026-10-20');
});

test('monthly preserves original anchor day across short months without drift', function (): void {
    $start = RecurrenceCalculator::parseDate('2026-01-31', 'start');

    $dates = [];
    $cursor = $start;
    for ($i = 0; $i < 5; $i++) {
        $dates[] = $cursor->toDateString();
        $cursor = RecurrenceCalculator::nextAfter(
            $cursor,
            $start,
            RecurringFrequency::Monthly,
            31,
            false,
        );
    }

    expect($dates)->toBe([
        '2026-01-31',
        '2026-02-28',
        '2026-03-31',
        '2026-04-30',
        '2026-05-31',
    ]);
});

test('ends of month always uses the last calendar day of each target month', function (): void {
    $start = RecurrenceCalculator::parseDate('2026-01-31', 'start');

    $dates = RecurrenceCalculator::datesInInclusiveRange(
        startDate: '2026-01-31',
        rangeStart: '2026-01-31',
        rangeEnd: '2026-05-31',
        frequency: RecurringFrequency::Monthly,
        anchorDay: 31,
        endsOfMonth: true,
    );

    expect($dates)->toBe([
        '2026-01-31',
        '2026-02-28',
        '2026-03-31',
        '2026-04-30',
        '2026-05-31',
    ]);

    $fromFeb = RecurrenceCalculator::nextAfter(
        RecurrenceCalculator::parseDate('2026-02-28', 'feb'),
        $start,
        RecurringFrequency::Monthly,
        31,
        true,
    );
    expect($fromFeb->toDateString())->toBe('2026-03-31');
});

test('quarterly advances three calendar months from the original series anchor', function (): void {
    $start = RecurrenceCalculator::parseDate('2026-01-31', 'start');

    $dates = [];
    $cursor = $start;
    for ($i = 0; $i < 4; $i++) {
        $dates[] = $cursor->toDateString();
        $cursor = RecurrenceCalculator::nextAfter(
            $cursor,
            $start,
            RecurringFrequency::Quarterly,
            31,
            false,
        );
    }

    expect($dates)->toBe([
        '2026-01-31',
        '2026-04-30',
        '2026-07-31',
        '2026-10-31',
    ]);
});

test('yearly february 29 returns to february 29 in leap years and clamps to 28 otherwise', function (): void {
    $start = RecurrenceCalculator::parseDate('2024-02-29', 'start');

    $y2025 = RecurrenceCalculator::nextAfter(
        $start,
        $start,
        RecurringFrequency::Yearly,
        29,
        false,
    );
    $y2026 = RecurrenceCalculator::nextAfter(
        $y2025,
        $start,
        RecurringFrequency::Yearly,
        29,
        false,
    );
    $y2027 = RecurrenceCalculator::nextAfter(
        $y2026,
        $start,
        RecurringFrequency::Yearly,
        29,
        false,
    );
    $y2028 = RecurrenceCalculator::nextAfter(
        $y2027,
        $start,
        RecurringFrequency::Yearly,
        29,
        false,
    );

    expect($y2025->toDateString())->toBe('2025-02-28')
        ->and($y2026->toDateString())->toBe('2026-02-28')
        ->and($y2027->toDateString())->toBe('2027-02-28')
        ->and($y2028->toDateString())->toBe('2028-02-29');
});

test('three-month horizon bounds are inclusive calendar dates in UTC', function (): void {
    $today = CarbonImmutable::parse('2026-10-15', 'UTC');

    [$rangeStart, $horizonEnd] = RecurrenceCalculator::horizonBounds('2026-09-01', $today);

    expect($rangeStart)->toBe('2026-09-01')
        ->and($horizonEnd)->toBe('2027-01-15');

    $dates = RecurrenceCalculator::datesThroughHorizon(
        startDate: '2026-10-15',
        frequency: RecurringFrequency::Monthly,
        anchorDay: 15,
        endsOfMonth: false,
        today: $today,
    );

    expect($dates)->toBe([
        '2026-10-15',
        '2026-11-15',
        '2026-12-15',
        '2027-01-15',
    ]);
});

test('rejects ends-of-month for weekly frequencies and mismatched monthly start dates', function (): void {
    expect(fn () => RecurrenceCalculator::assertValidAnchorConfiguration(
        RecurringFrequency::Weekly,
        1,
        true,
    ))->toThrow(LedgerIntegrityException::class, 'Ends-of-month');

    expect(fn () => RecurrenceCalculator::assertStartDateMatchesRule(
        RecurrenceCalculator::parseDate('2026-01-15', 'start'),
        RecurringFrequency::Monthly,
        31,
        false,
    ))->toThrow(LedgerIntegrityException::class, 'start_date must match');
});
