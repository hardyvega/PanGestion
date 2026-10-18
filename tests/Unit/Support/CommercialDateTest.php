<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommercialDateTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('utcInstants')]
    public function test_from_instant_converts_utc_to_the_chile_commercial_date(
        string $instant,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            CommercialDate::fromInstant(CarbonImmutable::parse($instant, 'UTC')),
        );
    }

    public static function utcInstants(): array
    {
        return [
            'same summer day' => ['2026-01-15T15:00:00+00:00', '2026-01-15'],
            'previous summer day' => ['2026-01-15T02:59:59+00:00', '2026-01-14'],
            'summer midnight' => ['2026-01-15T03:00:00+00:00', '2026-01-15'],
            'previous winter day' => ['2026-07-15T03:59:59+00:00', '2026-07-14'],
            'winter midnight' => ['2026-07-15T04:00:00+00:00', '2026-07-15'],
        ];
    }

    public function test_today_uses_a_deterministic_clock_and_chile_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15T02:30:00+00:00'));

        $this->assertSame('2026-07-14', CommercialDate::today());
    }

    public function test_is_future_compares_commercial_dates_in_chile(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15T12:00:00+00:00'));

        $this->assertFalse(CommercialDate::isFuture('2026-07-15'));
        $this->assertTrue(CommercialDate::isFuture('2026-07-16'));
        $this->assertFalse(CommercialDate::isFuture('2026-07-14'));
    }

    public function test_utc_range_is_inclusive_exclusive_for_a_normal_chile_day(): void
    {
        $range = CommercialDate::utcRange('2026-07-15');

        $this->assertSame(['start', 'end'], array_keys($range));
        $this->assertInstanceOf(CarbonImmutable::class, $range['start']);
        $this->assertInstanceOf(CarbonImmutable::class, $range['end']);
        $this->assertSame('UTC', $range['start']->getTimezone()->getName());
        $this->assertSame('UTC', $range['end']->getTimezone()->getName());
        $this->assertSame('2026-07-15T04:00:00+00:00', $range['start']->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2026-07-16T04:00:00+00:00', $range['end']->format('Y-m-d\TH:i:sP'));
        $this->assertSame(86400, $range['end']->getTimestamp() - $range['start']->getTimestamp());
        $this->assertSame('2026-07-15', CommercialDate::fromInstant($range['end']->subMicrosecond()));
        $this->assertSame('2026-07-16', CommercialDate::fromInstant($range['end']));
    }

    public function test_utc_range_covers_the_twenty_five_hour_dst_day_from_local_tzdata(): void
    {
        $range = CommercialDate::utcRange('2026-04-04');

        $this->assertSame('2026-04-04T03:00:00+00:00', $range['start']->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2026-04-05T04:00:00+00:00', $range['end']->format('Y-m-d\TH:i:sP'));
        $this->assertSame(90000, $range['end']->getTimestamp() - $range['start']->getTimestamp());
    }

    public function test_utc_range_covers_the_twenty_three_hour_dst_day_from_local_tzdata(): void
    {
        $range = CommercialDate::utcRange('2026-09-06');

        $this->assertSame('2026-09-06T04:00:00+00:00', $range['start']->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2026-09-07T03:00:00+00:00', $range['end']->format('Y-m-d\TH:i:sP'));
        $this->assertSame(82800, $range['end']->getTimestamp() - $range['start']->getTimestamp());
    }
}
