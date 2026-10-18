<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Models\Articulo;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\LockedArticleSet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class InventoryLockManagerTest extends TestCase
{
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $firstArticleId;

    private int $secondArticleId;

    private int $thirdArticleId;

    protected function setUp(): void
    {
        parent::setUp();

        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->firstArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'lock_articulo_uno',
            'nombre' => 'Articulo lock uno',
            'stock_actual' => '10.000',
        ]);
        $this->secondArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'lock_articulo_dos',
            'nombre' => 'Articulo lock dos',
            'stock_actual' => '5.000',
        ]);
        $this->thirdArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'lock_articulo_tres',
            'nombre' => 'Articulo lock tres',
            'stock_actual' => '1.000',
        ]);
    }

    public function test_locking_outside_an_existing_transaction_is_rejected(): void
    {
        $this->runWithoutTransactions(function (): void {
            $this->expectException(LogicException::class);

            (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        });
    }

    public function test_empty_input_returns_an_empty_set_without_querying_articles(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $set = (new InventoryLockManager())->lockArticles([]);
        $articleQueries = array_values(array_filter(
            $queries,
            static fn (QueryExecuted $query): bool => str_contains(strtolower($query->sql), 'articulos'),
        ));

        $this->assertInstanceOf(LockedArticleSet::class, $set);
        $this->assertSame([], $set->ids());
        $this->assertSame([], $articleQueries);
    }

    #[DataProvider('invalidIds')]
    public function test_ids_must_be_strictly_positive_integers(mixed $invalidId): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        try {
            (new InventoryLockManager())->lockArticles([$this->firstArticleId, $invalidId]);
            $this->fail('The lock manager accepted an invalid article ID.');
        } catch (InvalidArgumentException) {
            $articleQueries = array_values(array_filter(
                $queries,
                static fn (QueryExecuted $query): bool => str_contains(strtolower($query->sql), 'articulos'),
            ));

            $this->assertSame([], $articleQueries);
        }
    }

    public static function invalidIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'numeric string' => ['2'],
            'float' => [2.0],
            'null' => [null],
            'boolean' => [true],
        ];
    }

    public function test_ids_are_deduplicated_sorted_and_locked_with_one_for_update_query(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query;
        });

        $set = (new InventoryLockManager())->lockArticles([
            $this->thirdArticleId,
            $this->firstArticleId,
            $this->secondArticleId,
            $this->firstArticleId,
        ]);
        $lockQueries = array_values(array_filter(
            $queries,
            static fn (QueryExecuted $query): bool => str_contains(strtolower($query->sql), 'from "articulos"')
                && str_contains(strtolower($query->sql), 'for update'),
        ));

        $this->assertSame([
            $this->firstArticleId,
            $this->secondArticleId,
            $this->thirdArticleId,
        ], $set->ids());
        $this->assertCount(1, $lockQueries);
        $this->assertStringContainsString('order by "id" asc', strtolower($lockQueries[0]->sql));
        $this->assertSame(
            [$this->firstArticleId, $this->secondArticleId, $this->thirdArticleId],
            array_map(static fn (mixed $binding): int => (int) $binding, $lockQueries[0]->bindings),
        );
    }

    public function test_returned_set_contains_the_persisted_articles_with_exact_stock_values(): void
    {
        $set = (new InventoryLockManager())->lockArticles([
            $this->secondArticleId,
            $this->firstArticleId,
        ]);

        $this->assertInstanceOf(Articulo::class, $set->get($this->firstArticleId));
        $this->assertInstanceOf(Articulo::class, $set->get($this->secondArticleId));
        $this->assertSame('10.000', $set->get($this->firstArticleId)->stock_actual);
        $this->assertSame('5.000', $set->get($this->secondArticleId)->stock_actual);
    }

    public function test_any_missing_article_rejects_the_whole_request(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new InventoryLockManager())->lockArticles([
            $this->firstArticleId,
            9_999_999_999,
            $this->thirdArticleId,
        ]);
    }

    public function test_locking_has_no_stock_ledger_or_daily_projection_side_effects(): void
    {
        $before = DB::table('articulos')->orderBy('id')->pluck('stock_actual', 'id')->all();

        (new InventoryLockManager())->lockArticles([
            $this->thirdArticleId,
            $this->firstArticleId,
            $this->secondArticleId,
        ]);

        $this->assertSame($before, DB::table('articulos')->orderBy('id')->pluck('stock_actual', 'id')->all());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('stock_diario')->count());
    }

    public function test_locking_leaves_the_callers_transaction_level_unchanged(): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();

        (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        $this->assertGreaterThan(0, $level);
        $this->assertSame($level, $connection->transactionLevel());
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
