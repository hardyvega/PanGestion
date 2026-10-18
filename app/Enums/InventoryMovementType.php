<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryMovementType: string
{
    case InitialBalance = 'saldo_inicial';
    case Sale = 'venta';
    case SaleReversal = 'anulacion_venta';
    case Receipt = 'recepcion';
    case ReceiptReversal = 'anulacion_recepcion';
    case ProductionInput = 'produccion_entrada';
    case ProductionInputReversal = 'anulacion_produccion_entrada';
    case ProductionConsumption = 'produccion_consumo';
    case ProductionConsumptionReversal = 'anulacion_produccion_consumo';
    case Waste = 'merma';
    case WasteReversal = 'anulacion_merma';
    case PositiveCountAdjustment = 'ajuste_conteo_positivo';
    case NegativeCountAdjustment = 'ajuste_conteo_negativo';
    case ManualInput = 'ajuste_manual_entrada';
    case ManualOutput = 'ajuste_manual_salida';

    public function isPositive(): bool
    {
        return match ($this) {
            self::InitialBalance,
            self::SaleReversal,
            self::Receipt,
            self::ProductionInput,
            self::ProductionConsumptionReversal,
            self::WasteReversal,
            self::PositiveCountAdjustment,
            self::ManualInput => true,
            default => false,
        };
    }

    public function isNegative(): bool
    {
        return ! $this->isPositive();
    }

    public function isReversal(): bool
    {
        return $this->originalType() !== null;
    }

    public function reversalType(): ?self
    {
        return match ($this) {
            self::Sale => self::SaleReversal,
            self::Receipt => self::ReceiptReversal,
            self::ProductionInput => self::ProductionInputReversal,
            self::ProductionConsumption => self::ProductionConsumptionReversal,
            self::Waste => self::WasteReversal,
            default => null,
        };
    }

    public function originalType(): ?self
    {
        return match ($this) {
            self::SaleReversal => self::Sale,
            self::ReceiptReversal => self::Receipt,
            self::ProductionInputReversal => self::ProductionInput,
            self::ProductionConsumptionReversal => self::ProductionConsumption,
            self::WasteReversal => self::Waste,
            default => null,
        };
    }

    public function isReversibleOriginal(): bool
    {
        return $this->reversalType() !== null;
    }

    public function allowsRetroactivity(): bool
    {
        return match ($this) {
            self::Receipt,
            self::Waste,
            self::ManualInput,
            self::ManualOutput => true,
            default => false,
        };
    }
}
