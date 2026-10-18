<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Services\Inventory\LockedArticleSet;
use InvalidArgumentException;

final readonly class InventoryStockSimulation
{
    /**
     * @param array<InventoryStockMutation> $mutations
     * @param array<int, string> $before
     * @param array<int, string> $deltas
     * @param array<int, string> $after
     */
    public function __construct(
        public LockedArticleSet $articles,
        public string $commandUuid,
        public array $mutations,
        private array $before,
        private array $deltas,
        private array $after,
    ) {
    }

    public function articleIds(): array
    {
        return $this->articles->ids();
    }

    public function stockBefore(int $articleId): string
    {
        return $this->before[$articleId]
            ?? throw new InvalidArgumentException('El articulo no pertenece a la simulacion.');
    }

    public function totalDelta(int $articleId): string
    {
        return $this->deltas[$articleId]
            ?? throw new InvalidArgumentException('El articulo no pertenece a la simulacion.');
    }

    public function stockAfter(int $articleId): string
    {
        return $this->after[$articleId]
            ?? throw new InvalidArgumentException('El articulo no pertenece a la simulacion.');
    }
}
