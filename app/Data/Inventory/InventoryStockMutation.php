<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\InventoryMovementType;
use Carbon\CarbonImmutable;

final readonly class InventoryStockMutation
{
    public function __construct(
        public int $articleId,
        public InventoryMovementType $type,
        public string $delta,
        public int $position,
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
