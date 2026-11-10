<?php

declare(strict_types=1);

namespace App\Data\Dashboard;

final readonly class DashboardMetrics
{
    public function __construct(
        public bool $canViewFinancials,
        public string $salesToday,
        public int $salesTodayCount,
        public string $cashToday,
        public string $debitToday,
        public ?string $salesWeek,
        public ?string $salesMonth,
    ) {
    }

    public static function withoutFinancialAccess(): self
    {
        return new self(false, '0.00', 0, '0.00', '0.00', null, null);
    }

    public function hasPeriodTotals(): bool
    {
        return $this->salesWeek !== null && $this->salesMonth !== null;
    }
}
