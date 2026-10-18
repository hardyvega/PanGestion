<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;

final class PostgresUniqueViolation
{
    public static function for(QueryException $exception, string $constraint): bool
    {
        if ($exception->getCode() !== '23505') {
            return false;
        }

        $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        return str_contains($detail, $constraint);
    }
}
