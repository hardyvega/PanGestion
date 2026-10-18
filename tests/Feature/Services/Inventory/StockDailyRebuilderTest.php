<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Services\Inventory\StockDailyRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class StockDailyRebuilderTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $userId;

    private int $articleId;

    private int $otherArticleId;

    private int $uuidSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'stock_daily_rebuilder'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'rebuild_articulo_uno',
            'nombre' => 'Rebuild articulo uno',
            'stock_actual' => '99.000',
        ]);
        $this->otherArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'rebuild_articulo_dos',
            'nombre' => 'Rebuild articulo dos',
            'stock_actual' => '50.000',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_rebuild_requires_an_existing_transaction(): void
    {
        $this->runWithoutTransactions(function (): void {
            $this->expectException(LogicException::class);

            (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');
        });
    }

    #[DataProvider('invalidRebuildInputs')]
    public function test_rebuild_rejects_invalid_article_ids_or_commercial_dates(
        array $articleIds,
        string $fromDate,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        (new StockDailyRebuilder())->rebuild($articleIds, $fromDate);
    }

    public static function invalidRebuildInputs(): array
    {
        return [
            'zero article' => [[0], '2026-09-10'],
            'negative article' => [[-1], '2026-09-10'],
            'numeric string article' => [['1'], '2026-09-10'],
            'invalid date format' => [[1], '2026-9-10'],
            'impossible date' => [[1], '2026-02-30'],
        ];
    }

    public function test_projection_is_derived_from_ledger_and_never_from_current_stock(): void
    {
        $this->setToday('2026-09-10');
        $movementId = $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '5.000',
            '0.000',
            '5.000',
            '2026-09-10T12:00:00+00:00',
        );

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');
        $row = $this->daily($this->articleId, '2026-09-10');

        $this->assertSame('0.000', $row->stock_apertura);
        $this->assertSame('5.000', $row->total_entradas);
        $this->assertSame('0.000', $row->total_salidas);
        $this->assertSame('5.000', $row->stock_cierre);
        $this->assertSame($movementId, $row->ultimo_movimiento_id);
        $this->assertNotNull($row->calculado_en);
        $this->assertSame('99.000', $this->currentStock($this->articleId));
    }

    public function test_occurrence_instants_are_grouped_by_america_santiago_commercial_date(): void
    {
        $this->setToday('2026-09-12');
        $firstMovementId = $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '2.000',
            '0.000',
            '2.000',
            '2026-09-12T02:30:00+00:00',
        );
        $secondMovementId = $this->movement(
            $this->articleId,
            'ajuste_manual_salida',
            '-0.500',
            '2.000',
            '1.500',
            '2026-09-12T03:30:00+00:00',
        );

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-11');
        $dayEleven = $this->daily($this->articleId, '2026-09-11');
        $dayTwelve = $this->daily($this->articleId, '2026-09-12');

        $this->assertSame('2.000', $dayEleven->total_entradas);
        $this->assertSame('0.000', $dayEleven->total_salidas);
        $this->assertSame('2.000', $dayEleven->stock_cierre);
        $this->assertSame($firstMovementId, $dayEleven->ultimo_movimiento_id);
        $this->assertSame('2.000', $dayTwelve->stock_apertura);
        $this->assertSame('0.000', $dayTwelve->total_entradas);
        $this->assertSame('0.500', $dayTwelve->total_salidas);
        $this->assertSame('1.500', $dayTwelve->stock_cierre);
        $this->assertSame($secondMovementId, $dayTwelve->ultimo_movimiento_id);
    }

    public function test_daily_entries_outputs_and_closing_use_exact_decimal_equation(): void
    {
        $this->setToday('2026-09-10');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '10.000',
            '0.000',
            '10.000',
            '2026-09-10T12:00:00+00:00',
        );
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '2.500',
            '10.000',
            '12.500',
            '2026-09-10T13:00:00+00:00',
        );
        $this->movement(
            $this->articleId,
            'ajuste_manual_salida',
            '-3.250',
            '12.500',
            '9.250',
            '2026-09-10T14:00:00+00:00',
        );

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');
        $row = $this->daily($this->articleId, '2026-09-10');

        $this->assertSame('0.000', $row->stock_apertura);
        $this->assertSame('12.500', $row->total_entradas);
        $this->assertSame('3.250', $row->total_salidas);
        $this->assertSame('9.250', $row->stock_cierre);
    }

    public function test_first_rebuilt_day_opens_from_ledger_history_before_the_requested_range(): void
    {
        $this->setToday('2026-09-11');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '10.000',
            '0.000',
            '10.000',
            '2026-09-10T12:00:00+00:00',
        );
        $this->movement(
            $this->articleId,
            'ajuste_manual_salida',
            '-2.000',
            '10.000',
            '8.000',
            '2026-09-11T12:00:00+00:00',
        );

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-11');
        $row = $this->daily($this->articleId, '2026-09-11');

        $this->assertSame('10.000', $row->stock_apertura);
        $this->assertSame('0.000', $row->total_entradas);
        $this->assertSame('2.000', $row->total_salidas);
        $this->assertSame('8.000', $row->stock_cierre);
    }

    public function test_days_without_movements_preserve_daily_continuity_and_last_watermark(): void
    {
        $this->setToday('2026-09-13');
        $firstMovementId = $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '5.000',
            '0.000',
            '5.000',
            '2026-09-10T12:00:00+00:00',
        );
        $secondMovementId = $this->movement(
            $this->articleId,
            'ajuste_manual_salida',
            '-1.000',
            '5.000',
            '4.000',
            '2026-09-12T12:00:00+00:00',
        );

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');

        $this->assertSame(
            ['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13'],
            DB::table('stock_diario')->where('articulo_id', $this->articleId)
                ->orderBy('fecha_comercial')->pluck('fecha_comercial')->all(),
        );
        $emptyMiddle = $this->daily($this->articleId, '2026-09-11');
        $emptyEnd = $this->daily($this->articleId, '2026-09-13');
        $this->assertSame('5.000', $emptyMiddle->stock_apertura);
        $this->assertSame('0.000', $emptyMiddle->total_entradas);
        $this->assertSame('0.000', $emptyMiddle->total_salidas);
        $this->assertSame('5.000', $emptyMiddle->stock_cierre);
        $this->assertSame($firstMovementId, $emptyMiddle->ultimo_movimiento_id);
        $this->assertSame('4.000', $emptyEnd->stock_apertura);
        $this->assertSame('4.000', $emptyEnd->stock_cierre);
        $this->assertSame($secondMovementId, $emptyEnd->ultimo_movimiento_id);
    }

    public function test_horizon_extends_to_latest_of_today_existing_projection_and_ledger(): void
    {
        $this->setToday('2026-09-12');
        $movementId = $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '5.000',
            '0.000',
            '5.000',
            '2026-09-10T12:00:00+00:00',
        );
        $this->createStockDaily($this->articleId, [
            'fecha_comercial' => '2026-09-14',
            'stock_apertura' => '77.000',
            'total_entradas' => '0.000',
            'total_salidas' => '0.000',
            'stock_cierre' => '77.000',
            'ultimo_movimiento_id' => $movementId,
        ]);

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');

        $this->assertSame('2026-09-14', DB::table('stock_diario')
            ->where('articulo_id', $this->articleId)->max('fecha_comercial'));
        $this->assertSame(5, DB::table('stock_diario')->where('articulo_id', $this->articleId)->count());
        $this->assertSame('5.000', $this->daily($this->articleId, '2026-09-14')->stock_cierre);
    }

    public function test_retroactive_rebuild_preserves_prior_day_and_recalculates_forward(): void
    {
        $this->setToday('2026-09-13');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '10.000',
            '0.000',
            '10.000',
            '2026-09-10T12:00:00+00:00',
        );
        $this->movement(
            $this->articleId,
            'ajuste_manual_salida',
            '-2.000',
            '10.000',
            '8.000',
            '2026-09-12T12:00:00+00:00',
        );
        $rebuilder = new StockDailyRebuilder();
        $rebuilder->rebuild([$this->articleId], '2026-09-10');
        $dayTenBefore = (array) $this->daily($this->articleId, '2026-09-10');

        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '1.000',
            '10.000',
            '11.000',
            '2026-09-11T12:00:00+00:00',
            '2026-09-13T12:00:00+00:00',
        );
        $rebuilder->rebuild([$this->articleId], '2026-09-11');

        $this->assertSame($dayTenBefore, (array) $this->daily($this->articleId, '2026-09-10'));
        $this->assertSame('11.000', $this->daily($this->articleId, '2026-09-11')->stock_cierre);
        $this->assertSame('9.000', $this->daily($this->articleId, '2026-09-12')->stock_cierre);
        $this->assertSame('9.000', $this->daily($this->articleId, '2026-09-13')->stock_cierre);
    }

    public function test_last_movement_watermark_uses_occurred_at_then_id_and_carries_forward(): void
    {
        $this->setToday('2026-09-12');
        $laterFirst = $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '2.000',
            '0.000',
            '2.000',
            '2026-09-11T15:00:00+00:00',
        );
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '1.000',
            '2.000',
            '3.000',
            '2026-09-11T14:00:00+00:00',
        );
        $laterTie = $this->movement(
            $this->articleId,
            'ajuste_manual_salida',
            '-0.500',
            '3.000',
            '2.500',
            '2026-09-11T15:00:00+00:00',
        );

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-11');

        $this->assertGreaterThan($laterFirst, $laterTie);
        $this->assertSame($laterTie, $this->daily($this->articleId, '2026-09-11')->ultimo_movimiento_id);
        $this->assertSame($laterTie, $this->daily($this->articleId, '2026-09-12')->ultimo_movimiento_id);
    }

    public function test_multi_article_input_is_deduplicated_and_ledger_queries_are_ordered(): void
    {
        $this->setToday('2026-09-10');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '2.000',
            '0.000',
            '2.000',
            '2026-09-10T12:00:00+00:00',
        );
        $this->movement(
            $this->otherArticleId,
            'ajuste_manual_entrada',
            '3.000',
            '0.000',
            '3.000',
            '2026-09-10T12:00:00+00:00',
        );
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        (new StockDailyRebuilder())->rebuild([
            $this->otherArticleId,
            $this->articleId,
            $this->otherArticleId,
        ], '2026-09-10');
        $ledgerQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'movimientos_inventario'),
        ));

        $this->assertSame(2, DB::table('stock_diario')->where('fecha_comercial', '2026-09-10')->count());
        $this->assertSame('2.000', $this->daily($this->articleId, '2026-09-10')->stock_cierre);
        $this->assertSame('3.000', $this->daily($this->otherArticleId, '2026-09-10')->stock_cierre);
        $this->assertNotEmpty(array_filter(
            $ledgerQueries,
            static fn (string $sql): bool => str_contains($sql, 'order by')
                && str_contains($sql, 'articulo_id')
                && str_contains($sql, 'ocurrido_en')
                && str_contains($sql, 'id'),
        ));
    }

    public function test_rebuild_never_modifies_current_stock_for_any_article(): void
    {
        $this->setToday('2026-09-10');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '4.000',
            '0.000',
            '4.000',
            '2026-09-10T12:00:00+00:00',
        );
        $before = DB::table('articulos')->orderBy('id')->pluck('stock_actual', 'id')->all();

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');

        $this->assertSame($before, DB::table('articulos')->orderBy('id')->pluck('stock_actual', 'id')->all());
    }

    public function test_repeating_rebuild_is_derived_idempotent_without_duplicate_days(): void
    {
        $this->setToday('2026-09-12');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '6.000',
            '0.000',
            '6.000',
            '2026-09-10T12:00:00+00:00',
        );
        $rebuilder = new StockDailyRebuilder();

        $rebuilder->rebuild([$this->articleId], '2026-09-10');
        $first = $this->businessProjection($this->articleId);
        $rebuilder->rebuild([$this->articleId], '2026-09-10');
        $second = $this->businessProjection($this->articleId);

        $this->assertCount(3, $second);
        $this->assertSame($first, $second);
        $this->assertSame(3, DB::table('stock_diario')->where('articulo_id', $this->articleId)
            ->distinct()->count('fecha_comercial'));
    }

    public function test_article_without_ledger_does_not_invent_daily_history(): void
    {
        $this->setToday('2026-09-12');

        (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');

        $this->assertSame(0, DB::table('stock_diario')->where('articulo_id', $this->articleId)->count());
        $this->assertSame('99.000', $this->currentStock($this->articleId));
    }

    public function test_caller_rollback_removes_the_projection_without_touching_ledger_or_stock(): void
    {
        $this->setToday('2026-09-10');
        $this->movement(
            $this->articleId,
            'ajuste_manual_entrada',
            '5.000',
            '0.000',
            '5.000',
            '2026-09-10T12:00:00+00:00',
        );
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            (new StockDailyRebuilder())->rebuild([$this->articleId], '2026-09-10');
            $this->assertSame(1, DB::table('stock_diario')->count());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, DB::table('stock_diario')->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->count());
        $this->assertSame('99.000', $this->currentStock($this->articleId));
    }

    private function movement(
        int $articleId,
        string $type,
        string $delta,
        string $stockBefore,
        string $stockAfter,
        string $occurredAt,
        ?string $createdAt = null,
    ): int {
        $snapshot = DB::table('articulos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->where('articulos.id', $articleId)
            ->first([
                'articulos.sku',
                'articulos.nombre',
                'unidades_medida.codigo as unidad_codigo',
                'unidades_medida.simbolo as unidad_simbolo',
            ]);
        $this->assertNotNull($snapshot);

        return $this->createInventoryMovement(
            $articleId,
            $this->userId,
            $type,
            $this->nextUuid(),
            [
                'cantidad_delta' => $delta,
                'stock_anterior' => $stockBefore,
                'stock_posterior' => $stockAfter,
                'ocurrido_en' => $occurredAt,
                'created_at' => $createdAt ?? $occurredAt,
                'motivo' => 'Proyeccion diaria autorizada',
                'articulo_sku_snapshot' => $snapshot->sku,
                'articulo_nombre_snapshot' => $snapshot->nombre,
                'unidad_codigo_snapshot' => $snapshot->unidad_codigo,
                'unidad_simbolo_snapshot' => $snapshot->unidad_simbolo,
            ],
        );
    }

    private function daily(int $articleId, string $date): object
    {
        $row = DB::table('stock_diario')
            ->where('articulo_id', $articleId)
            ->where('fecha_comercial', $date)
            ->first();

        $this->assertNotNull($row);

        return $row;
    }

    private function businessProjection(int $articleId): array
    {
        return DB::table('stock_diario')
            ->where('articulo_id', $articleId)
            ->orderBy('fecha_comercial')
            ->get([
                'articulo_id',
                'fecha_comercial',
                'stock_apertura',
                'total_entradas',
                'total_salidas',
                'stock_cierre',
                'ultimo_movimiento_id',
            ])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    private function currentStock(int $articleId): string
    {
        return (string) DB::table('articulos')->where('id', $articleId)->value('stock_actual');
    }

    private function setToday(string $date): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' 12:00:00', 'America/Santiago'));
    }

    private function nextUuid(): string
    {
        return sprintf('94000000-0000-4000-8000-%012d', $this->uuidSequence++);
    }

    private function runWithoutTransactions(callable $callback): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();

        for ($remaining = $level; $remaining > 0; $remaining--) {
            $connection->rollBack();
        }

        try {
            $callback();
        } finally {
            for ($remaining = $level; $remaining > 0; $remaining--) {
                $connection->beginTransaction();
            }
        }
    }
}
