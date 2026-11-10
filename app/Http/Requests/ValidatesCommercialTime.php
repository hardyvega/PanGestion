<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;

trait ValidatesCommercialTime
{
    /** @var array<string, CarbonImmutable> */
    private array $commercialInstants = [];

    public function commercialInstant(string $field): ?CarbonImmutable
    {
        return $this->commercialInstants[$field] ?? null;
    }

    protected function commercialTimeRule(bool $pastOnly = false): Closure
    {
        return function (string $field, string $value, Closure $fail) use ($pastOnly): void {
            $occurrence = $this->input($field.'_occurrence');

            try {
                $instant = CommercialDate::parseLocal($value, is_string($occurrence) ? $occurrence : null);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());

                return;
            }

            if ($pastOnly && $instant->isFuture()) {
                $fail('La fecha y hora de Santiago no pueden estar en el futuro.');

                return;
            }

            $this->commercialInstants[$field] = $instant;
        };
    }
}
