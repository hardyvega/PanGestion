<?php

namespace Tests\Feature\Database\BlockFour;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ArticleCostHistoryConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articleId = $this->createArticle($this->createArticleCategory(), $this->createUnitOfMeasure());
        $this->userId = $this->createUser($this->createRole());
    }

    public function test_article_cost_history_can_be_created_with_defaults(): void
    {
        $id = $this->createArticleCostHistory($this->articleId, $this->userId);
        $history = DB::table('historial_costos_articulo')->where('id', $id)->first();

        $this->assertNotNull($history);
        $this->assertSame($id, $history->id);
        $this->assertSame($this->articleId, $history->articulo_id);
        $this->assertNull($history->proveedor_id);
        $this->assertNull($history->costo_anterior);
        $this->assertSame('1000.00', $history->costo_nuevo);
        $this->assertSame($this->userId, $history->usuario_id);
        $this->assertNull($history->motivo);
        $this->assertNotNull($history->vigente_desde);
        $this->assertNotNull($history->created_at);
    }

    #[DataProvider('validCostTransitions')]
    public function test_valid_cost_transition_is_accepted(?string $previous, ?string $next): void
    {
        $id = $this->createArticleCostHistory($this->articleId, $this->userId, [
            'costo_anterior' => $previous,
            'costo_nuevo' => $next,
        ]);
        $history = DB::table('historial_costos_articulo')->where('id', $id)->first();

        $this->assertNotNull($history);
        $this->assertSame($previous, $history->costo_anterior);
        $this->assertSame($next, $history->costo_nuevo);
    }

    public static function validCostTransitions(): array
    {
        return [
            'initial cost' => [null, '1000.00'],
            'cost increase' => ['1000.00', '1200.00'],
            'cost removed' => ['1200.00', null],
            'from zero' => ['0.00', '1000.00'],
            'to zero' => ['1000.00', '0.00'],
        ];
    }

    #[DataProvider('approvedSupplierAssociations')]
    public function test_article_cost_history_accepts_approved_supplier_association(bool $withSupplier): void
    {
        $supplierId = null;

        if ($withSupplier) {
            $supplierId = $this->createSupplier();
            $this->assertFalse(DB::table('articulo_proveedor')->where([
                'articulo_id' => $this->articleId,
                'proveedor_id' => $supplierId,
            ])->exists());
        }

        $id = $this->createArticleCostHistory($this->articleId, $this->userId, [
            'proveedor_id' => $supplierId,
        ]);

        $this->assertSame(
            $supplierId,
            DB::table('historial_costos_articulo')->where('id', $id)->value('proveedor_id'),
        );

        if ($withSupplier) {
            $this->assertFalse(DB::table('articulo_proveedor')->where([
                'articulo_id' => $this->articleId,
                'proveedor_id' => $supplierId,
            ])->exists());
        }
    }

    public static function approvedSupplierAssociations(): array
    {
        return [
            'cost not attributed to a concrete supplier' => [false],
            'direct supplier reference without article supplier row' => [true],
        ];
    }

    public function test_both_costs_null_are_rejected(): void
    {
        $data = $this->articleCostHistoryData($this->articleId, $this->userId, [
            'costo_anterior' => null,
            'costo_nuevo' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::table('historial_costos_articulo')->insert($data);
    }

    #[DataProvider('invalidHistoryCosts')]
    public function test_invalid_history_cost_is_rejected(string $column, string $cost): void
    {
        $costs = ['costo_anterior' => '1000.00', 'costo_nuevo' => '1200.00'];
        $costs[$column] = $cost;
        $data = $this->articleCostHistoryData($this->articleId, $this->userId, $costs);

        $this->expectException(QueryException::class);
        DB::table('historial_costos_articulo')->insert($data);
    }

    public static function invalidHistoryCosts(): array
    {
        return [
            'previous negative' => ['costo_anterior', '-0.01'],
            'previous NaN' => ['costo_anterior', 'NaN'],
            'next negative' => ['costo_nuevo', '-0.01'],
            'next NaN' => ['costo_nuevo', 'NaN'],
        ];
    }

    #[DataProvider('costHistoryParents')]
    public function test_article_cost_history_rejects_missing_parent(string $column, string $parentTable): void
    {
        $missingId = (int) DB::table($parentTable)->max('id') + 1;
        $this->assertFalse(DB::table($parentTable)->where('id', $missingId)->exists());
        $data = $this->articleCostHistoryData($this->articleId, $this->userId, [$column => $missingId]);

        $this->expectException(QueryException::class);
        DB::table('historial_costos_articulo')->insert($data);
    }

    public static function costHistoryParents(): array
    {
        return [
            'article' => ['articulo_id', 'articulos'],
            'supplier' => ['proveedor_id', 'proveedores'],
            'user' => ['usuario_id', 'usuarios'],
        ];
    }

    #[DataProvider('approvedCostHistoryReasons')]
    public function test_approved_cost_history_reason_is_preserved(?string $reason): void
    {
        $id = $this->createArticleCostHistory($this->articleId, $this->userId, ['motivo' => $reason]);

        $this->assertSame(
            $reason,
            DB::table('historial_costos_articulo')->where('id', $id)->value('motivo'),
        );
    }

    public static function approvedCostHistoryReasons(): array
    {
        return [
            'null' => [null],
            'nonempty reason' => ['Actualizacion de costo informado'],
        ];
    }

    #[DataProvider('invalidCostHistoryReasons')]
    public function test_invalid_cost_history_reason_is_rejected(string $reason): void
    {
        $data = $this->articleCostHistoryData($this->articleId, $this->userId, ['motivo' => $reason]);

        $this->expectException(QueryException::class);
        DB::table('historial_costos_articulo')->insert($data);
    }

    public static function invalidCostHistoryReasons(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Actualizacion'],
            'trailing space' => ['Actualizacion '],
        ];
    }
}
