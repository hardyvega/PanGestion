<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\InventoryMovementType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryMovementTypeTest extends TestCase
{
    private const VALUES = [
        'saldo_inicial',
        'venta',
        'anulacion_venta',
        'recepcion',
        'anulacion_recepcion',
        'produccion_entrada',
        'anulacion_produccion_entrada',
        'produccion_consumo',
        'anulacion_produccion_consumo',
        'merma',
        'anulacion_merma',
        'ajuste_conteo_positivo',
        'ajuste_conteo_negativo',
        'ajuste_manual_entrada',
        'ajuste_manual_salida',
    ];

    public function test_enum_contains_exactly_the_fifteen_approved_backed_values(): void
    {
        $actual = array_map(
            static fn (InventoryMovementType $type): string => $type->value,
            InventoryMovementType::cases(),
        );

        $this->assertSame(self::VALUES, $actual);
        $this->assertCount(15, $actual);
    }

    #[DataProvider('movementSigns')]
    public function test_each_movement_has_exactly_one_approved_sign(string $value, bool $positive): void
    {
        $type = InventoryMovementType::from($value);

        $this->assertSame($positive, $type->isPositive());
        $this->assertSame(! $positive, $type->isNegative());
        $this->assertNotSame($type->isPositive(), $type->isNegative());
    }

    public static function movementSigns(): array
    {
        return [
            'initial balance' => ['saldo_inicial', true],
            'sale' => ['venta', false],
            'sale annulment' => ['anulacion_venta', true],
            'receipt' => ['recepcion', true],
            'receipt annulment' => ['anulacion_recepcion', false],
            'production output' => ['produccion_entrada', true],
            'production output annulment' => ['anulacion_produccion_entrada', false],
            'production consumption' => ['produccion_consumo', false],
            'production consumption annulment' => ['anulacion_produccion_consumo', true],
            'waste' => ['merma', false],
            'waste annulment' => ['anulacion_merma', true],
            'positive count adjustment' => ['ajuste_conteo_positivo', true],
            'negative count adjustment' => ['ajuste_conteo_negativo', false],
            'manual input' => ['ajuste_manual_entrada', true],
            'manual output' => ['ajuste_manual_salida', false],
        ];
    }

    #[DataProvider('reversalPairs')]
    public function test_reversal_pairs_are_bidirectional_and_only_originals_are_reversible(
        string $originalValue,
        string $reversalValue,
    ): void {
        $original = InventoryMovementType::from($originalValue);
        $reversal = InventoryMovementType::from($reversalValue);

        $this->assertFalse($original->isReversal());
        $this->assertTrue($original->isReversibleOriginal());
        $this->assertSame($reversal, $original->reversalType());
        $this->assertNull($original->originalType());
        $this->assertTrue($reversal->isReversal());
        $this->assertFalse($reversal->isReversibleOriginal());
        $this->assertNull($reversal->reversalType());
        $this->assertSame($original, $reversal->originalType());
    }

    public static function reversalPairs(): array
    {
        return [
            'sale' => ['venta', 'anulacion_venta'],
            'receipt' => ['recepcion', 'anulacion_recepcion'],
            'production output' => ['produccion_entrada', 'anulacion_produccion_entrada'],
            'production consumption' => ['produccion_consumo', 'anulacion_produccion_consumo'],
            'waste' => ['merma', 'anulacion_merma'],
        ];
    }

    #[DataProvider('nonReversibleMovements')]
    public function test_non_reversible_movements_do_not_invent_a_reversal(string $value): void
    {
        $type = InventoryMovementType::from($value);

        $this->assertFalse($type->isReversal());
        $this->assertFalse($type->isReversibleOriginal());
        $this->assertNull($type->reversalType());
        $this->assertNull($type->originalType());
    }

    public static function nonReversibleMovements(): array
    {
        return [
            'initial balance' => ['saldo_inicial'],
            'positive count adjustment' => ['ajuste_conteo_positivo'],
            'negative count adjustment' => ['ajuste_conteo_negativo'],
            'manual input' => ['ajuste_manual_entrada'],
            'manual output' => ['ajuste_manual_salida'],
        ];
    }

    #[DataProvider('retroactivityRules')]
    public function test_only_approved_original_movements_allow_retroactivity(
        string $value,
        bool $allowed,
    ): void {
        $this->assertSame($allowed, InventoryMovementType::from($value)->allowsRetroactivity());
    }

    public static function retroactivityRules(): array
    {
        $allowed = [
            'recepcion',
            'merma',
            'ajuste_manual_entrada',
            'ajuste_manual_salida',
        ];

        $rows = [];

        foreach (self::VALUES as $value) {
            $rows[$value] = [$value, in_array($value, $allowed, true)];
        }

        return $rows;
    }
}
