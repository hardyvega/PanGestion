<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryBootstrapService;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\StockDailyRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;
use Throwable;

final class InventoryAdjustmentServiceTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const ENTRY_COMMAND = 'b1300000-0000-4000-8000-000000000001';

    private const EXIT_COMMAND = 'b1300000-0000-4000-8000-000000000002';

    private const LATER_COMMAND = 'b1300000-0000-4000-8000-000000000003';

    private const ENTRY_REASON = 'Correccion documentada de entrada';

    private const EXIT_REASON = 'Correccion documentada de salida';

    private const BOOTSTRAP_COMMAND = 'b1390000-0000-4000-8000-000000000001';

    private User $actor;

    private User $otherActor;

    private Articulo $article;

    private Articulo $fractionalArticle;

    private Articulo $inactiveArticle;

    private int $roleId;

    /** @var array<string, int> */
    private array $permissionIds = [];

    private ?CarbonImmutable $previousTestNow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTestNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09T15:30:00.123456+00:00'));
        $this->roleId = $this->createRole([
            'codigo' => 'operador_ajustes_inventario',
            'nombre' => 'Operador de ajustes de inventario',
            'activo' => true,
        ]);

        foreach ([
            PermissionCode::InventoryInitialBalanceExecute,
            PermissionCode::InventoryAdjustmentsInput,
            PermissionCode::InventoryAdjustmentsOutput,
            PermissionCode::InventoryAdjustmentsRetroactive,
        ] as $permission) {
            $permissionId = $this->createPermission([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'activo' => true,
            ]);
            $this->attachPermission($this->roleId, $permissionId);
            $this->permissionIds[$permission->value] = $permissionId;
        }

        $this->actor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'inventory_adjustment_actor',
            'activo' => true,
        ]));
        $this->otherActor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'inventory_adjustment_other_actor',
            'activo' => true,
        ]));
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->article = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'ajuste_manual_principal',
            'nombre' => 'Articulo de ajuste manual',
            'stock_actual' => '5.000',
            'precio_venta_actual' => '1500.00',
            'activo' => true,
        ]));
        $this->fractionalArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'ajuste_manual_fraccional',
            'nombre' => 'Articulo fraccional',
            'stock_actual' => '4.125',
            'precio_venta_actual' => '900.00',
            'activo' => true,
        ]));
        $this->inactiveArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'ajuste_manual_inactivo',
            'nombre' => 'Articulo inactivo con stock',
            'stock_actual' => '3.000',
            'precio_venta_actual' => '700.00',
            'activo' => true,
        ]));

        $bootstrap = $this->app->make(InventoryBootstrapService::class);
        $bootstrap->initialize(
            $this->actor,
            (int) $this->article->getKey(),
            self::BOOTSTRAP_COMMAND,
            'Saldo inicial para ajustes',
        );
        $bootstrap->initialize(
            $this->actor,
            (int) $this->fractionalArticle->getKey(),
            'b1390000-0000-4000-8000-000000000002',
            'Saldo inicial para ajustes',
        );
        $bootstrap->initialize(
            $this->actor,
            (int) $this->inactiveArticle->getKey(),
            'b1390000-0000-4000-8000-000000000003',
            'Saldo inicial para ajustes',
        );
        DB::table('articulos')->where('id', $this->inactiveArticle->getKey())->update(['activo' => false]);
        $this->inactiveArticle->refresh();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11T15:30:00.654321+00:00'));
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow($this->previousTestNow);
        } finally {
            parent::tearDown();
        }
    }

    public function test_public_api_and_constructor_are_limited_to_the_approved_surface(): void
    {
        $methods = (new ReflectionClass(InventoryAdjustmentService::class))
            ->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
        sort($methodNames);
        $this->assertSame(['__construct', 'manualEntry', 'manualExit'], $methodNames);

        $constructor = new ReflectionMethod(InventoryAdjustmentService::class, '__construct');
        $this->assertSame([
            EffectivePermissionResolver::class,
            InventoryLockManager::class,
            InventoryStockService::class,
            StockDailyRebuilder::class,
        ], array_map(
            static fn ($parameter): string => $parameter->getType()->getName(),
            $constructor->getParameters(),
        ));

        foreach (['manualEntry', 'manualExit'] as $methodName) {
            $method = new ReflectionMethod(InventoryAdjustmentService::class, $methodName);
            $this->assertSame(
                ['actor', 'articleId', 'quantity', 'commandUuid', 'motivo', 'occurredAt'],
                array_map(static fn ($parameter): string => $parameter->getName(), $method->getParameters()),
            );
            $this->assertSame(
                [User::class, 'int', 'string', 'string', 'string', CarbonImmutable::class],
                array_map(static fn ($parameter): string => $parameter->getType()->getName(), $method->getParameters()),
            );
            $this->assertTrue($method->getParameters()[5]->allowsNull());
            $this->assertTrue($method->getParameters()[5]->isDefaultValueAvailable());
            $this->assertNull($method->getParameters()[5]->getDefaultValue());
            $this->assertSame(MovimientoInventario::class, $method->getReturnType()->getName());
        }
    }

    public function test_manual_entry_updates_stock_and_writes_one_exact_ledger_row(): void
    {
        $movement = $this->entry('3.250');

        $this->assertSame('8.250', $this->stockOf($this->article));
        $this->assertMovement(
            $movement,
            $this->article,
            InventoryMovementType::ManualInput,
            '3.250',
            '5.000',
            '8.250',
            self::ENTRY_COMMAND,
            self::ENTRY_REASON,
            null,
        );
        $this->assertDaily($this->article, '2026-10-11', '5.000', '3.250', '0.000', '8.250', $movement);
    }

    public function test_manual_exit_updates_stock_and_writes_one_exact_ledger_row(): void
    {
        $movement = $this->exit('2.250');

        $this->assertSame('2.750', $this->stockOf($this->article));
        $this->assertMovement(
            $movement,
            $this->article,
            InventoryMovementType::ManualOutput,
            '-2.250',
            '5.000',
            '2.750',
            self::EXIT_COMMAND,
            self::EXIT_REASON,
            null,
        );
        $this->assertDaily($this->article, '2026-10-11', '5.000', '0.000', '2.250', '2.750', $movement);
    }

    public function test_fractional_entry_preserves_exact_thousandths(): void
    {
        $movement = $this->service()->manualEntry(
            $this->actor,
            (int) $this->fractionalArticle->getKey(),
            '2.375',
            self::ENTRY_COMMAND,
            self::ENTRY_REASON,
        );

        $this->assertSame('6.500', $this->stockOf($this->fractionalArticle));
        $this->assertSame('4.125', $movement->stock_anterior);
        $this->assertSame('2.375', $movement->cantidad_delta);
        $this->assertSame('6.500', $movement->stock_posterior);
    }

    #[DataProvider('invalidQuantities')]
    public function test_zero_negative_malformed_excess_scale_or_overflow_quantity_is_rejected(
        string $operation,
        string $quantity,
        string $exceptionClass,
    ): void {
        $this->assertRejectedWithoutChanges(
            $exceptionClass,
            fn () => $this->invoke($operation, $this->article, $quantity, self::ENTRY_COMMAND, self::ENTRY_REASON),
        );
    }

    public static function invalidQuantities(): array
    {
        return [
            'entry zero' => ['entry', '0', InvalidArgumentException::class],
            'exit zero' => ['exit', '0.000', InvalidArgumentException::class],
            'entry negative' => ['entry', '-2.000', InvalidArgumentException::class],
            'exit negative' => ['exit', '-2.000', InvalidArgumentException::class],
            'excess scale' => ['entry', '1.0001', InvalidArgumentException::class],
            'scientific notation' => ['entry', '1e3', InvalidArgumentException::class],
            'conceptual NaN' => ['entry', 'NaN', InvalidArgumentException::class],
            'numeric overflow' => ['entry', '100000000000.000', OutOfRangeException::class],
        ];
    }

    #[DataProvider('manualOperations')]
    public function test_blank_reason_is_rejected_for_both_operations(string $operation): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->invoke($operation, $this->article, '1.000', self::ENTRY_COMMAND, " \t\n "),
        );
    }

    public static function manualOperations(): array
    {
        return ['entry' => ['entry'], 'exit' => ['exit']];
    }

    public function test_reason_is_trimmed_and_outer_whitespace_is_idempotently_equivalent(): void
    {
        $original = $this->entry('1.000', self::ENTRY_COMMAND, "  \t".self::ENTRY_REASON." \n");
        $before = $this->operationalState();
        $retry = $this->entry();

        $this->assertSame(self::ENTRY_REASON, $original->motivo);
        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame($before, $this->operationalState());
    }

    #[DataProvider('manualOperations')]
    public function test_missing_article_is_rejected_without_effects(string $operation): void
    {
        $this->assertRejectedWithoutChanges(
            ModelNotFoundException::class,
            fn () => $this->invoke($operation, null, '1.000', self::ENTRY_COMMAND, self::ENTRY_REASON, PHP_INT_MAX),
        );
    }

    public function test_manual_entry_rejects_an_inactive_article(): void
    {
        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->service()->manualEntry(
                $this->actor,
                (int) $this->inactiveArticle->getKey(),
                '1.000',
                self::ENTRY_COMMAND,
                self::ENTRY_REASON,
            ),
        );
    }

    public function test_manual_exit_allows_an_inactive_article_with_sufficient_stock(): void
    {
        $movement = $this->service()->manualExit(
            $this->actor,
            (int) $this->inactiveArticle->getKey(),
            '1.250',
            self::EXIT_COMMAND,
            self::EXIT_REASON,
        );

        $this->assertFalse($this->inactiveArticle->refresh()->activo);
        $this->assertSame('1.750', $this->stockOf($this->inactiveArticle));
        $this->assertSame(InventoryMovementType::ManualOutput, $movement->tipo_movimiento);
        $this->assertSame('-1.250', $movement->cantidad_delta);
    }

    public function test_manual_exit_rejects_insufficient_stock_atomically(): void
    {
        $this->assertRejectedWithoutChanges(
            InsufficientStockException::class,
            fn () => $this->exit('5.001'),
        );
        $this->assertSame('5.000', $this->stockOf($this->article));
    }

    public function test_manual_exit_can_reduce_stock_exactly_to_zero(): void
    {
        $movement = $this->exit('5.000');

        $this->assertSame('0.000', $this->stockOf($this->article));
        $this->assertSame('0.000', $movement->stock_posterior);
    }

    public function test_entry_and_exit_permissions_are_separate_and_exact(): void
    {
        $this->revoke(PermissionCode::InventoryAdjustmentsInput);
        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->entry('1.000'));
        $this->assertSame(InventoryMovementType::ManualOutput, $this->exit('1.000')->tipo_movimiento);
    }

    public function test_exit_permission_does_not_grant_entry_and_entry_permission_does_not_grant_exit(): void
    {
        $this->revoke(PermissionCode::InventoryAdjustmentsOutput);
        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->exit('1.000'));
        $this->assertSame(InventoryMovementType::ManualInput, $this->entry('1.000')->tipo_movimiento);
    }

    public function test_normal_adjustment_uses_server_now_and_has_no_authorizer(): void
    {
        $movement = $this->entry();

        $this->assertTrue($movement->ocurrido_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertNull($movement->usuario_autorizador_id);
        $this->assertTrue($movement->ocurrido_en->lessThanOrEqualTo($movement->created_at));
    }

    #[DataProvider('manualOperations')]
    public function test_future_occurrence_is_always_rejected(string $operation): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->invoke(
                $operation,
                $this->article,
                '1.000',
                self::ENTRY_COMMAND,
                self::ENTRY_REASON,
                occurredAt: CarbonImmutable::now('UTC')->addMicrosecond(),
            ),
        );
    }

    #[DataProvider('manualOperations')]
    public function test_past_occurrence_requires_the_exact_retroactive_permission(string $operation): void
    {
        $this->revoke(PermissionCode::InventoryAdjustmentsRetroactive);

        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->invoke(
                $operation,
                $this->article,
                '1.000',
                self::ENTRY_COMMAND,
                self::ENTRY_REASON,
                occurredAt: CarbonImmutable::now('UTC')->subDay(),
            ),
        );
    }

    public function test_retroactive_entry_preserves_the_exact_instant_and_self_authorizes(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T12:15:30.123456+00:00');
        $movement = $this->entry('2.000', occurredAt: $occurredAt);

        $this->assertTrue($movement->ocurrido_en->equalTo($occurredAt));
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_autorizador_id);
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_id);
        $this->assertSame('7.000', $this->stockOf($this->article));
    }

    public function test_retroactive_exit_preserves_the_exact_instant_and_self_authorizes(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T13:45:15.654321+00:00');
        $movement = $this->exit('1.500', occurredAt: $occurredAt);

        $this->assertTrue($movement->ocurrido_en->equalTo($occurredAt));
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_autorizador_id);
        $this->assertSame('-1.500', $movement->cantidad_delta);
        $this->assertSame('3.500', $this->stockOf($this->article));
    }

    public function test_invalid_uuid_is_rejected_without_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->entry(command: 'not-a-uuid'),
        );
    }

    public function test_normal_entry_retry_returns_the_original_without_a_second_effect(): void
    {
        $original = $this->entry('2.000');
        $before = $this->operationalState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));

        $retry = $this->entry('2.000');

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame('7.000', $this->stockOf($this->article));
        $this->assertSame($before, $this->operationalState());
    }

    public function test_normal_exit_retry_returns_the_original_without_a_second_decrement(): void
    {
        $original = $this->exit('2.000');
        $before = $this->operationalState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));

        $retry = $this->exit('2.000');

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame('3.000', $this->stockOf($this->article));
        $this->assertSame($before, $this->operationalState());
    }

    public function test_retry_after_a_later_legal_movement_keeps_current_stock_and_original_evidence(): void
    {
        $original = $this->entry('2.000');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $later = $this->entry('1.000', self::LATER_COMMAND, 'Segundo ajuste documentado');
        $before = $this->operationalState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());

        $retry = $this->entry('2.000');

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertNotSame($original->getKey(), $later->getKey());
        $this->assertSame('8.000', $this->stockOf($this->article));
        $this->assertSame($before, $this->operationalState());
    }

    public function test_uuid_reuse_for_another_article_conflicts_without_effects(): void
    {
        $this->entry();
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->manualEntry(
                $this->actor,
                (int) $this->fractionalArticle->getKey(),
                '1.000',
                self::ENTRY_COMMAND,
                self::ENTRY_REASON,
            ),
        );
    }

    public function test_uuid_reuse_by_another_actor_conflicts_without_effects(): void
    {
        $this->entry();
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->manualEntry(
                $this->otherActor,
                (int) $this->article->getKey(),
                '1.000',
                self::ENTRY_COMMAND,
                self::ENTRY_REASON,
            ),
        );
    }

    public function test_uuid_reuse_between_entry_and_exit_conflicts_without_effects(): void
    {
        $this->entry();
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->exit('1.000', self::ENTRY_COMMAND, self::ENTRY_REASON),
        );
    }

    public function test_uuid_reuse_with_different_quantity_conflicts_without_effects(): void
    {
        $this->entry();
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->entry('1.001'),
        );
    }

    public function test_uuid_reuse_with_different_reason_conflicts_without_effects(): void
    {
        $this->entry();
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->entry('1.000', self::ENTRY_COMMAND, 'Motivo contractual distinto'),
        );
    }

    public function test_retroactive_retry_requires_the_same_explicit_instant(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T11:00:00.123456+00:00');
        $this->entry('1.000', occurredAt: $occurredAt);

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->entry('1.000', occurredAt: $occurredAt->addSecond()),
        );
    }

    public function test_uuid_owned_by_another_ledger_type_is_not_claimed_by_manual_adjustment(): void
    {
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->entry('1.000', self::BOOTSTRAP_COMMAND),
        );
    }

    public function test_retroactive_adjustment_rebuilds_history_and_forward_continuity(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T12:00:00.123456+00:00');
        $movement = $this->entry('2.000', occurredAt: $occurredAt);

        $this->assertDaily($this->article, '2026-10-10', '5.000', '2.000', '0.000', '7.000', $movement);
        $this->assertDaily($this->article, '2026-10-11', '7.000', '0.000', '0.000', '7.000', $movement);
        $this->assertSame(3, DB::table('stock_diario')->where('articulo_id', $this->article->getKey())->count());
    }

    public function test_adjustments_do_not_touch_cost_price_cash_or_other_operational_aggregates(): void
    {
        $before = $this->unrelatedDomainState();
        $prices = DB::table('articulos')->orderBy('id')->pluck('precio_venta_actual', 'id')->all();

        $this->entry('1.000');

        $this->assertSame($before, $this->unrelatedDomainState());
        $this->assertSame($prices, DB::table('articulos')->orderBy('id')->pluck('precio_venta_actual', 'id')->all());
    }

    public function test_later_adjustment_does_not_mutate_prior_ledger_evidence(): void
    {
        $first = $this->entry('1.250');
        $evidence = (array) DB::table('movimientos_inventario')->where('id', $first->getKey())->first();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());

        $this->exit('0.500', self::LATER_COMMAND, 'Correccion posterior documentada');

        $this->assertSame(
            $evidence,
            (array) DB::table('movimientos_inventario')->where('id', $first->getKey())->first(),
        );
        $this->assertNull($first->refresh()->movimiento_revertido_id);
    }

    public function test_workflow_owns_one_transaction_and_uses_idempotency_then_article_lock_before_writes(): void
    {
        $connection = DB::connection();
        $baseLevel = $connection->transactionLevel();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'level' => $query->connection->transactionLevel(),
            ];
        });

        $this->entry('1.000');

        $idempotency = $this->queryIndex($queries, 'from "movimientos_inventario"', 'for update');
        $articleLock = $this->queryIndex($queries, 'from "articulos"', 'for update');
        $stockUpdate = $this->queryIndex($queries, 'update "articulos"');
        $ledgerInsert = $this->queryIndex($queries, 'insert into "movimientos_inventario"');
        $dailyInsert = $this->queryIndex($queries, 'insert into "stock_diario"');

        $this->assertLessThan($articleLock, $idempotency);
        $this->assertLessThan($stockUpdate, $articleLock);
        $this->assertLessThan($ledgerInsert, $stockUpdate);
        $this->assertLessThan($dailyInsert, $ledgerInsert);
        foreach ([$idempotency, $articleLock, $stockUpdate, $ledgerInsert, $dailyInsert] as $index) {
            $this->assertSame($baseLevel + 1, $queries[$index]['level']);
        }
        $this->assertSame($baseLevel, $connection->transactionLevel());
    }

    private function service(): InventoryAdjustmentService
    {
        return $this->app->make(InventoryAdjustmentService::class);
    }

    private function entry(
        string $quantity = '1.000',
        string $command = self::ENTRY_COMMAND,
        string $motivo = self::ENTRY_REASON,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        return $this->service()->manualEntry(
            $this->actor,
            (int) $this->article->getKey(),
            $quantity,
            $command,
            $motivo,
            $occurredAt,
        );
    }

    private function exit(
        string $quantity = '1.000',
        string $command = self::EXIT_COMMAND,
        string $motivo = self::EXIT_REASON,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        return $this->service()->manualExit(
            $this->actor,
            (int) $this->article->getKey(),
            $quantity,
            $command,
            $motivo,
            $occurredAt,
        );
    }

    private function invoke(
        string $operation,
        ?Articulo $article,
        string $quantity,
        string $command,
        string $motivo,
        ?int $articleId = null,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        $method = $operation === 'entry' ? 'manualEntry' : 'manualExit';

        return $this->service()->{$method}(
            $this->actor,
            $articleId ?? (int) $article?->getKey(),
            $quantity,
            $command,
            $motivo,
            $occurredAt,
        );
    }

    private function assertMovement(
        MovimientoInventario $movement,
        Articulo $article,
        InventoryMovementType $type,
        string $delta,
        string $before,
        string $after,
        string $command,
        string $reason,
        ?int $authorizerId,
    ): void {
        $this->assertSame($type, $movement->tipo_movimiento);
        $this->assertSame((int) $article->getKey(), $movement->articulo_id);
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_id);
        $this->assertSame($authorizerId, $movement->usuario_autorizador_id);
        $this->assertSame($delta, $movement->cantidad_delta);
        $this->assertSame($before, $movement->stock_anterior);
        $this->assertSame($after, $movement->stock_posterior);
        $this->assertSame($command, $movement->clave_idempotencia);
        $this->assertSame(1, $movement->secuencia_idempotencia);
        $this->assertSame($reason, $movement->motivo);
        $this->assertSame($article->sku, $movement->articulo_sku_snapshot);
        $this->assertSame($article->nombre, $movement->articulo_nombre_snapshot);
        foreach ([
            'detalle_venta_id',
            'detalle_recepcion_mercaderia_id',
            'detalle_produccion_id',
            'consumo_produccion_id',
            'merma_id',
            'detalle_conteo_inventario_id',
            'movimiento_revertido_id',
        ] as $column) {
            $this->assertNull($movement->getAttribute($column), $column);
        }
    }

    private function assertDaily(
        Articulo $article,
        string $date,
        string $opening,
        string $entries,
        string $outputs,
        string $closing,
        MovimientoInventario $watermark,
    ): void {
        $row = DB::table('stock_diario')
            ->where('articulo_id', $article->getKey())
            ->where('fecha_comercial', $date)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame($opening, $row->stock_apertura);
        $this->assertSame($entries, $row->total_entradas);
        $this->assertSame($outputs, $row->total_salidas);
        $this->assertSame($closing, $row->stock_cierre);
        $this->assertSame((int) $watermark->getKey(), $row->ultimo_movimiento_id);
    }

    private function stockOf(Articulo $article): string
    {
        return (string) DB::table('articulos')->where('id', $article->getKey())->value('stock_actual');
    }

    private function revoke(PermissionCode $permission): void
    {
        DB::table('rol_permiso')
            ->where('rol_id', $this->roleId)
            ->where('permiso_id', $this->permissionIds[$permission->value])
            ->delete();
    }

    private function operationalState(): array
    {
        return $this->tableState([
            'articulos',
            'movimientos_inventario',
            'stock_diario',
            'historial_costos_articulo',
            'historial_precios_venta',
            'sesiones_caja',
            'movimientos_caja',
            'pedidos',
            'ventas',
            'producciones',
            'mermas',
            'conteos_inventario',
            'recepciones_mercaderia',
        ]);
    }

    private function unrelatedDomainState(): array
    {
        return $this->tableState([
            'historial_costos_articulo',
            'historial_precios_venta',
            'sesiones_caja',
            'movimientos_caja',
            'pedidos',
            'ventas',
            'producciones',
            'mermas',
            'conteos_inventario',
            'recepciones_mercaderia',
        ]);
    }

    private function tableState(array $tables): array
    {
        return array_map(
            static fn (string $table): array => DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
            $tables,
        );
    }

    private function assertRejectedWithoutChanges(string $exceptionClass, callable $operation): void
    {
        $before = $this->operationalState();
        $caught = null;

        try {
            $operation();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf($exceptionClass, $caught);
        $this->assertSame($before, $this->operationalState());
    }

    /** @param array<int, array{sql: string, level: int}> $queries */
    private function queryIndex(array $queries, string ...$needles): int
    {
        foreach ($queries as $index => $query) {
            if (array_all($needles, static fn (string $needle): bool => str_contains($query['sql'], $needle))) {
                return $index;
            }
        }

        $this->fail('No se encontro la consulta esperada: '.implode(' + ', $needles));
    }
}
