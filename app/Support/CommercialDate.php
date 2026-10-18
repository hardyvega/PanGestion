<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final class CommercialDate
{
    private const TIMEZONE = 'America/Santiago';

    private function __construct()
    {
    }

    public static function fromInstant(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone(self::TIMEZONE)
            ->format('Y-m-d');
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d');
    }

    public static function isFuture(string $date): bool
    {
        return self::calendarDate($date)->greaterThan(self::calendarDate(self::today()));
    }

    public static function utcRange(string $date): array
    {
        $calendarDate = self::calendarDate($date);
        $nextDate = $calendarDate->addDay()->format('Y-m-d');

        return [
            'start' => self::localBoundary($date)->setTimezone('UTC'),
            'end' => self::localBoundary($nextDate)->setTimezone('UTC'),
        ];
    }

    private static function calendarDate(string $date): CarbonImmutable
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            throw new InvalidArgumentException('La fecha comercial debe usar el formato Y-m-d.');
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('La fecha comercial no es valida.');
        }

        return $parsed;
    }

    private static function localBoundary(string $date): CarbonImmutable
    {
        $calendarDate = self::calendarDate($date);

        return CarbonImmutable::create(
            (int) $calendarDate->format('Y'),
            (int) $calendarDate->format('m'),
            (int) $calendarDate->format('d'),
            0,
            0,
            0,
            self::TIMEZONE,
        );
    }
}
