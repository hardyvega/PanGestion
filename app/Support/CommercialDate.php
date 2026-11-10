<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class CommercialDate
{
    private const TIMEZONE = 'America/Santiago';

    private function __construct()
    {
    }

    public static function fromInstant(DateTimeInterface $instant): string
    {
        return self::format($instant, 'Y-m-d');
    }

    public static function format(?DateTimeInterface $instant, string $format = 'd/m/Y H:i'): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE)->format($format);
    }

    public static function parseLocal(string $value, ?string $occurrence = null): CarbonImmutable
    {
        if (! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $value)) {
            throw new InvalidArgumentException('Ingresa una fecha y hora válidas de Santiago.');
        }

        $format = 'Y-m-d\TH:i';
        $wallTime = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
        if ($wallTime === false || $wallTime->format($format) !== $value) {
            throw new InvalidArgumentException('Ingresa una fecha y hora válidas de Santiago.');
        }

        $zone = new DateTimeZone(self::TIMEZONE);
        $wallTimestamp = $wallTime->getTimestamp();
        // Try each offset around the selected wall time, then check the local round trip.
        $offsets = array_unique(array_column($zone->getTransitions($wallTimestamp - 86400, $wallTimestamp + 86400), 'offset'));
        $instants = [];
        foreach ($offsets as $offset) {
            $instant = CarbonImmutable::createFromTimestampUTC($wallTimestamp - $offset);
            if ($instant->setTimezone($zone)->format($format) === $value) {
                $instants[$instant->getTimestamp()] = $instant;
            }
        }

        if ($instants === []) {
            throw new InvalidArgumentException('Esta hora no existe en Santiago por el cambio de horario. Elige otra hora.');
        }

        ksort($instants, SORT_NUMERIC);
        if (count($instants) > 1) {
            return match ($occurrence) {
                'primera' => reset($instants),
                'segunda' => end($instants),
                default => throw new InvalidArgumentException('Esta hora ocurre dos veces en Santiago. Elige la primera o la segunda vez.'),
            };
        }

        return reset($instants);
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->format('Y-m-d');
    }

    public static function isFuture(string $date): bool
    {
        return self::calendarDate($date)->greaterThan(self::calendarDate(self::today()));
    }

    public static function utcRange(string $date, ?string $through = null): array
    {
        $nextDate = self::calendarDate($through ?? $date)->addDay()->format('Y-m-d');

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
