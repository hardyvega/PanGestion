<?php

namespace Tests\Feature\Database\BlockThree;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class PriceHistoryConstraintsTest extends TestCase
{
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

    public function test_price_history_can_be_created_with_defaults(): void
    {
        $id = $this->createPriceHistory($this->articleId, $this->userId);
        $history = DB::table('historial_precios_venta')->where('id', $id)->first();

        $this->assertNotNull($history);
        $this->assertSame($id, $history->id);
        $this->assertSame($this->articleId, $history->articulo_id);
        $this->assertSame($this->userId, $history->usuario_id);
        $this->assertNull($history->precio_anterior);
        $this->assertSame('1500.00', $history->precio_nuevo);
        $this->assertNull($history->motivo);
        $this->assertNotNull($history->vigente_desde);
        $this->assertNotNull($history->created_at);
    }

    #[DataProvider('validPriceTransitions')]
    public function test_valid_price_transition_is_accepted(?string $previous, ?string $next): void
    {
        $id = $this->createPriceHistory($this->articleId, $this->userId, [
            'precio_anterior' => $previous,
            'precio_nuevo' => $next,
        ]);
        $history = DB::table('historial_precios_venta')->where('id', $id)->first();

        $this->assertNotNull($history);
        $this->assertSame($previous, $history->precio_anterior);
        $this->assertSame($next, $history->precio_nuevo);
    }

    public static function validPriceTransitions(): array
    {
        return [
            'initial price' => [null, '1500.00'],
            'price increase' => ['1500.00', '1800.00'],
            'price removed' => ['1800.00', null],
            'from zero' => ['0.00', '1000.00'],
            'to zero' => ['1000.00', '0.00'],
        ];
    }

    public function test_both_prices_null_are_rejected(): void
    {
        $data = $this->priceHistoryData($this->articleId, $this->userId, [
            'precio_anterior' => null,
            'precio_nuevo' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::table('historial_precios_venta')->insert($data);
    }

    #[DataProvider('invalidHistoryPrices')]
    public function test_invalid_history_price_is_rejected(string $column, string $price): void
    {
        $prices = ['precio_anterior' => '1000.00', 'precio_nuevo' => '1000.00'];
        $prices[$column] = $price;
        $data = $this->priceHistoryData($this->articleId, $this->userId, $prices);

        $this->expectException(QueryException::class);
        DB::table('historial_precios_venta')->insert($data);
    }

    public static function invalidHistoryPrices(): array
    {
        return [
            'previous negative' => ['precio_anterior', '-0.01'],
            'previous NaN' => ['precio_anterior', 'NaN'],
            'next negative' => ['precio_nuevo', '-0.01'],
            'next NaN' => ['precio_nuevo', 'NaN'],
        ];
    }

    #[DataProvider('historyParents')]
    public function test_history_rejects_missing_parent(string $column, string $parentTable): void
    {
        $missingId = (int) DB::table($parentTable)->max('id') + 1;
        $this->assertFalse(DB::table($parentTable)->where('id', $missingId)->exists());
        $data = $this->priceHistoryData($this->articleId, $this->userId, [$column => $missingId]);

        $this->expectException(QueryException::class);
        DB::table('historial_precios_venta')->insert($data);
    }

    public static function historyParents(): array
    {
        return [
            'article' => ['articulo_id', 'articulos'],
            'user' => ['usuario_id', 'usuarios'],
        ];
    }

    #[DataProvider('validHistoryReasons')]
    public function test_valid_history_reason_is_preserved(?string $reason): void
    {
        $id = $this->createPriceHistory($this->articleId, $this->userId, ['motivo' => $reason]);

        $this->assertSame($reason, DB::table('historial_precios_venta')->where('id', $id)->value('motivo'));
    }

    public static function validHistoryReasons(): array
    {
        return [
            'null' => [null],
            'nonempty reason' => ['Actualizacion de lista'],
        ];
    }

    #[DataProvider('invalidHistoryReasons')]
    public function test_invalid_history_reason_is_rejected(string $reason): void
    {
        $data = $this->priceHistoryData($this->articleId, $this->userId, ['motivo' => $reason]);

        $this->expectException(QueryException::class);
        DB::table('historial_precios_venta')->insert($data);
    }

    public static function invalidHistoryReasons(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Actualizacion'],
            'trailing space' => ['Actualizacion '],
        ];
    }
}
