<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryMovementType;
use App\Models\Articulo;
use Carbon\CarbonImmutable;

final readonly class InventoryMovementData
{
    public function __construct(
        public Articulo $article,
        public InventoryMovementType $type,
        public string $delta,
        public string $stockBefore,
        public string $stockAfter,
        public string $commandUuid,
        public int $sequence,
        public CarbonImmutable $occurredAt,
        public int $actorId,
        public ?int $authorizerId,
        public ?string $reason,
        public string $unitCode,
        public string $unitSymbol,
        public ?int $saleDetailId,
        public ?int $receiptDetailId,
        public ?int $productionDetailId,
        public ?int $productionConsumptionId,
        public ?int $wasteId,
        public ?int $inventoryCountDetailId,
        public ?int $reversedMovementId,
    ) {
    }
}
