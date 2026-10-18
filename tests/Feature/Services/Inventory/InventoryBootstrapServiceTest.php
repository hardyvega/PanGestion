<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryBootstrapService;
use App\Services\Inventory\InventoryLedgerWriter;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\StockDailyRebuilder;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;
use Throwable;

final class InventoryBootstrapServiceTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const COMMAND = 'b1000000-0000-4000-8000-000000000001';

    private const OTHER_COMMAND = 'b1000000-0000-4000-8000-000000000002';

    private const REASON = 'Apertura documentada de inventario';

    private User $actor;

    private Articulo $article;

    private Articulo $otherArticle;

    private int $roleId;

    private int $permissionId;

    private ?CarbonImmutable $previousTestNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTestNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08T01:30:00.123456+00:00'));
        $this->roleId = $this->createRole([
            'codigo' => 'operador_bootstrap',
            'nombre' => 'Operador de saldo inicial',
            'activo' => true,
        ]);
        $this->permissionId = $this->createPermission([
            'codigo' => PermissionCode::InventoryInitialBalanceExecute->value,
            'nombre' => 'Ejecutar saldo inicial',
            'modulo' => 'inventario',
            'activo' => true,
        ]);
        $this->attachPermission($this->roleId, $this->permissionId);
        $userId = $this->createUser($this->roleId, [
            'nombre_usuario' => 'bootstrap_actor',
            'password' => Hash::make('bootstrap-test-only'),
            'activo' => true,
        ]);
        $this->actor = User::query()->findOrFail($userId);
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->article = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'bootstrap_articulo',
            'nombre' => 'Articulo de apertura',
            'stock_actual' => '10.000',
            'activo' => true,
        ]));
        $this->otherArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'bootstrap_otro',
            'nombre' => 'Otro articulo de apertura',
            'stock_actual' => '4.000',
            'activo' => true,
        ]));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->previousTestNow)) {
                CarbonImmutable::setTestNow($this->previousTestNow);
            } else {
                CarbonImmutable::setTestNow();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_missing_effective_permission_is_rejected_without_any_inventory_effect(): void
    {
        DB::table('rol_permiso')->where('rol_id', $this->roleId)->delete();

        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->initialize());

        $this->assertSame('10.000', $this->article->refresh()->stock_actual);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('stock_diario', 0);
    }

    #[DataProvider('revokedAuthorization')]
    public function test_even_an_identical_retry_reauthorizes_against_current_database_state(string $target): void
    {
        $this->initialize();

        match ($target) {
            'user' => DB::table('usuarios')->where('id', $this->actor->getKey())->update(['activo' => false]),
            'role' => DB::table('roles')->where('id', $this->roleId)->update(['activo' => false]),
            'permission' => DB::table('permisos')->where('id', $this->permissionId)->update(['activo' => false]),
            'pivot' => DB::table('rol_permiso')->where('rol_id', $this->roleId)->delete(),
        };

        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->initialize());
    }

    public static function revokedAuthorization(): array
    {
        return [
            'inactive actor' => ['user'],
            'inactive role' => ['role'],
            'inactive permission' => ['permission'],
            'removed permission assignment' => ['pivot'],
        ];
    }

    #[DataProvider('positiveBalances')]
    public function test_positive_balance_documents_locked_stock_without_updating_the_article(
        string $stock,
        bool $active,
    ): void {
        DB::table('articulos')->where('id', $this->article->getKey())->update([
            'stock_actual' => $stock,
            'activo' => $active,
        ]);
        $before = (array) DB::table('articulos')->where('id', $this->article->getKey())->first();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $movement = $this->initialize();

        $this->assertInstanceOf(MovimientoInventario::class, $movement);
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertSame(InventoryMovementType::InitialBalance, $movement->tipo_movimiento);
        $this->assertSame((int) $this->article->getKey(), $movement->articulo_id);
        $this->assertSame('0.000', $movement->stock_anterior);
        $this->assertSame($stock, $movement->cantidad_delta);
        $this->assertSame($stock, $movement->stock_posterior);
        $this->assertSame(self::COMMAND, $movement->clave_idempotencia);
        $this->assertSame(1, $movement->secuencia_idempotencia);
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_id);
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_autorizador_id);
        $this->assertSame(self::REASON, $movement->motivo);

        foreach ([
            'detalle_venta_id', 'detalle_recepcion_mercaderia_id', 'detalle_produccion_id',
            'consumo_produccion_id', 'merma_id', 'detalle_conteo_inventario_id', 'movimiento_revertido_id',
        ] as $column) {
            $this->assertNull($movement->getAttribute($column), $column);
        }

        $this->assertSame('bootstrap_articulo', $movement->articulo_sku_snapshot);
        $this->assertSame('Articulo de apertura', $movement->articulo_nombre_snapshot);
        $this->assertSame('kg', $movement->unidad_codigo_snapshot);
        $this->assertSame('kg', $movement->unidad_simbolo_snapshot);
        $this->assertSame($stock, $this->article->refresh()->stock_actual);
        $this->assertSame($before, (array) DB::table('articulos')->where('id', $this->article->getKey())->first());
        $this->assertNoArticleUpdates($queries);
    }

    public static function positiveBalances(): array
    {
        return [
            'active integral balance' => ['10.000', true],
            'inactive article is allowed' => ['10.000', false],
            'exact fractional balance' => ['14.375', true],
        ];
    }

    public function test_zero_balance_and_its_retry_are_physical_no_ops(): void
    {
        DB::table('articulos')->where('id', $this->article->getKey())->update(['stock_actual' => '0.000']);
        $before = $this->inventoryState();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertNull($this->initialize());
        $this->assertNull($this->initialize());

        $this->assertSame($before, $this->inventoryState());
        $this->assertSame('0.000', $this->article->refresh()->stock_actual);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('stock_diario', 0);
        $this->assertNoArticleUpdates($queries);
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1,
        )));
    }

    public function test_blank_reason_is_rejected_before_inventory_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->initialize(motivo: " \t\n "),
        );
    }

    public function test_invalid_uuid_is_rejected_before_inventory_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->initialize(command: 'not-a-uuid'),
        );
    }

    #[DataProvider('invalidArticleIds')]
    public function test_article_id_must_be_positive(int $id): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->service()->initialize($this->actor, $id, self::COMMAND, self::REASON),
        );
    }

    public static function invalidArticleIds(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    public function test_missing_article_is_rejected_without_inventory_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            ModelNotFoundException::class,
            fn () => $this->service()->initialize($this->actor, PHP_INT_MAX, self::COMMAND, self::REASON),
        );
    }

    public function test_uuid_case_and_trimmed_reason_share_the_same_logical_retry(): void
    {
        $original = $this->initialize(command: strtoupper(self::COMMAND), motivo: '  '.self::REASON."\t");
        $before = $this->inventoryState();

        $retry = $this->initialize();

        $this->assertSame(self::COMMAND, $original->clave_idempotencia);
        $this->assertSame(self::REASON, $original->motivo);
        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame($before, $this->inventoryState());
    }

    public function test_server_utc_instant_drives_the_correct_chilean_daily_projection(): void
    {
        $instant = CarbonImmutable::now('UTC');

        $movement = $this->initialize();
        $row = DB::table('stock_diario')->where('articulo_id', $this->article->getKey())->sole();
        $stored = MovimientoInventario::query()->findOrFail($movement->getKey());

        $this->assertSame('2026-10-08 01:30:00.123456+00:00', $stored->ocurrido_en->format('Y-m-d H:i:s.uP'));
        $this->assertTrue($stored->ocurrido_en->equalTo($instant));
        $this->assertTrue($stored->ocurrido_en->lessThanOrEqualTo($stored->created_at));
        $this->assertSame('UTC', DB::selectOne('SHOW TIME ZONE')->TimeZone);
        $this->assertSame('2026-10-07', $row->fecha_comercial);
        $this->assertSame(CommercialDate::fromInstant($instant), $row->fecha_comercial);
        $this->assertSame('0.000', $row->stock_apertura);
        $this->assertSame('10.000', $row->total_entradas);
        $this->assertSame('0.000', $row->total_salidas);
        $this->assertSame('10.000', $row->stock_cierre);
        $this->assertSame((int) $movement->getKey(), $row->ultimo_movimiento_id);
        $this->assertDatabaseCount('stock_diario', 1);
    }

    public function test_a_distinct_command_cannot_create_a_second_initial_balance(): void
    {
        $this->initialize();

        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->initialize(command: self::OTHER_COMMAND),
        );
        $this->assertDatabaseCount('movimientos_inventario', 1);
    }

    public function test_identical_retry_returns_the_same_movement_without_rebuilding_or_duplicating(): void
    {
        $original = $this->initialize();
        $before = $this->inventoryState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDay());

        $retry = $this->initialize();

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame($before, $this->inventoryState());
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertDatabaseCount('stock_diario', 1);
    }

    public function test_retry_uses_original_evidence_even_after_a_subsequent_legal_stock_movement(): void
    {
        $original = $this->initialize();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->createManualInput('2.000', '10.000', '12.000');
        DB::table('articulos')->where('id', $this->article->getKey())->update(['stock_actual' => '12.000']);
        (new StockDailyRebuilder())->rebuild(
            [(int) $this->article->getKey()],
            CommercialDate::fromInstant($original->ocurrido_en),
        );
        $before = $this->inventoryState();

        $retry = $this->initialize();

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame('10.000', $retry->cantidad_delta);
        $this->assertSame('12.000', $this->article->refresh()->stock_actual);
        $this->assertSame($before, $this->inventoryState());
        $this->assertDatabaseCount('movimientos_inventario', 2);
    }

    public function test_reusing_the_uuid_with_a_different_reason_is_a_conflict(): void
    {
        $this->initialize();

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->initialize(motivo: 'Otro motivo autorizado'),
        );
    }

    public function test_reusing_the_uuid_for_another_article_is_a_conflict(): void
    {
        $this->initialize();

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->initialize(
                $this->actor, (int) $this->otherArticle->getKey(), self::COMMAND, self::REASON,
            ),
        );
        $this->assertSame('4.000', $this->otherArticle->refresh()->stock_actual);
    }

    public function test_reusing_the_uuid_by_another_authorized_actor_is_a_conflict(): void
    {
        $this->initialize();
        $otherActor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'bootstrap_other_actor',
            'password' => Hash::make('bootstrap-test-only'),
            'activo' => true,
        ]));

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->initialize(
                $otherActor, (int) $this->article->getKey(), self::COMMAND, self::REASON,
            ),
        );
    }

    #[DataProvider('incompatibleCommands')]
    public function test_existing_uuid_must_have_the_exact_bootstrap_command_structure(
        string $type,
        int $sequence,
        bool $autoauthorized,
    ): void {
        $this->createInventoryMovement(
            (int) $this->article->getKey(), (int) $this->actor->getKey(), $type, self::COMMAND,
            [
                'cantidad_delta' => '10.000',
                'stock_anterior' => '0.000',
                'stock_posterior' => '10.000',
                'secuencia_idempotencia' => $sequence,
                'usuario_autorizador_id' => $autoauthorized ? (int) $this->actor->getKey() : null,
                'motivo' => self::REASON,
                'ocurrido_en' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ],
        );

        $this->assertRejectedWithoutChanges(IdempotencyConflictException::class, fn () => $this->initialize());
    }

    public static function incompatibleCommands(): array
    {
        return [
            'other movement type' => ['ajuste_manual_entrada', 1, true],
            'wrong sequence' => ['saldo_inicial', 2, true],
            'missing actual authorizer' => ['saldo_inicial', 1, false],
        ];
    }

    public function test_a_multi_row_command_cannot_be_recognized_as_this_single_movement_workflow(): void
    {
        $this->initialize();
        $this->createInventoryMovement(
            (int) $this->otherArticle->getKey(), (int) $this->actor->getKey(), 'ajuste_manual_entrada',
            self::COMMAND,
            [
                'cantidad_delta' => '4.000',
                'stock_anterior' => '0.000',
                'stock_posterior' => '4.000',
                'secuencia_idempotencia' => 2,
                'usuario_autorizador_id' => (int) $this->actor->getKey(),
                'motivo' => self::REASON,
                'ocurrido_en' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ],
        );

        $this->assertRejectedWithoutChanges(IdempotencyConflictException::class, fn () => $this->initialize());
    }

    public function test_a_prior_operational_movement_blocks_late_bootstrap(): void
    {
        $this->createManualInput('10.000', '0.000', '10.000');

        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->initialize());

        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->assertDatabaseMissing('movimientos_inventario', ['tipo_movimiento' => 'saldo_inicial']);
        $this->assertDatabaseCount('stock_diario', 0);
    }

    public function test_a_zero_no_op_cannot_become_late_bootstrap_after_an_operational_movement(): void
    {
        DB::table('articulos')->where('id', $this->article->getKey())->update(['stock_actual' => '0.000']);
        $this->assertNull($this->initialize());
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->createManualInput('10.000', '0.000', '10.000');
        DB::table('articulos')->where('id', $this->article->getKey())->update(['stock_actual' => '10.000']);

        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->initialize());
        $this->assertDatabaseMissing('movimientos_inventario', ['tipo_movimiento' => 'saldo_inicial']);
    }

    public function test_workflow_owns_one_transaction_and_locks_before_ledger_and_projection(): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });

        $this->initialize();

        $lock = array_keys(array_filter($queries, static fn (array $query): bool =>
            str_contains($query['sql'], '"articulos"') && str_contains($query['sql'], 'for update')
        ));
        $ledger = array_keys(array_filter($queries, static fn (array $query): bool =>
            str_starts_with($query['sql'], 'insert into "movimientos_inventario"')
        ));
        $projection = array_keys(array_filter($queries, static fn (array $query): bool =>
            str_starts_with($query['sql'], 'insert into "stock_diario"')
        ));
        $this->assertCount(1, $lock);
        $this->assertCount(1, $ledger);
        $this->assertCount(1, $projection);
        $this->assertLessThan($ledger[0], $lock[0]);
        $this->assertLessThan($projection[0], $ledger[0]);

        foreach ([$lock[0], $ledger[0], $projection[0]] as $index) {
            $this->assertSame($level + 1, $queries[$index]['level']);
        }

        $this->assertSame($level, $connection->transactionLevel());
        $this->assertNoArticleUpdates(array_column($queries, 'sql'));
    }

    public function test_public_api_has_no_client_timestamp_authorizer_amount_or_movement_type(): void
    {
        $method = new ReflectionMethod(InventoryBootstrapService::class, 'initialize');
        $constructor = new ReflectionMethod(InventoryBootstrapService::class, '__construct');
        $this->assertSame(['actor', 'articleId', 'commandUuid', 'motivo'], array_map(
            static fn ($parameter): string => $parameter->getName(), $method->getParameters(),
        ));
        $this->assertSame(['App\\Models\\User', 'int', 'string', 'string'], array_map(
            static fn ($parameter): string => $parameter->getType()->getName(), $method->getParameters(),
        ));
        $this->assertSame(MovimientoInventario::class, $method->getReturnType()->getName());
        $this->assertTrue($method->getReturnType()->allowsNull());
        $this->assertSame([
            EffectivePermissionResolver::class,
            InventoryLockManager::class,
            InventoryLedgerWriter::class,
            StockDailyRebuilder::class,
        ], array_map(
            static fn ($parameter): string => $parameter->getType()->getName(), $constructor->getParameters(),
        ));
        $publicMethods = (new ReflectionClass(InventoryBootstrapService::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $this->assertSame(['__construct', 'initialize'], array_map(
            static fn (ReflectionMethod $publicMethod): string => $publicMethod->getName(), $publicMethods,
        ));
    }

    private function service(): InventoryBootstrapService
    {
        return $this->app->make(InventoryBootstrapService::class);
    }

    private function initialize(string $command = self::COMMAND, string $motivo = self::REASON): ?MovimientoInventario
    {
        return $this->service()->initialize($this->actor, (int) $this->article->getKey(), $command, $motivo);
    }

    private function inventoryState(): array
    {
        return array_map(
            static fn (string $table): array => DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all(),
            ['articulos', 'movimientos_inventario', 'stock_diario'],
        );
    }

    private function assertRejectedWithoutChanges(string $exceptionClass, callable $operation): void
    {
        $before = $this->inventoryState();
        $caught = null;

        try {
            $operation();
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf($exceptionClass, $caught);
        $this->assertSame($before, $this->inventoryState());
    }

    private function assertNoArticleUpdates(array $queries): void
    {
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => preg_match('/^\s*update\s+"?articulos"?\b/i', $sql) === 1,
        )));
    }

    private function createManualInput(string $delta, string $before, string $after): int
    {
        return $this->createInventoryMovement(
            (int) $this->article->getKey(), (int) $this->actor->getKey(), 'ajuste_manual_entrada',
            self::OTHER_COMMAND,
            [
                'cantidad_delta' => $delta,
                'stock_anterior' => $before,
                'stock_posterior' => $after,
                'usuario_autorizador_id' => (int) $this->actor->getKey(),
                'motivo' => 'Entrada manual autorizada de fixture',
                'articulo_sku_snapshot' => 'bootstrap_articulo',
                'articulo_nombre_snapshot' => 'Articulo de apertura',
                'ocurrido_en' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ],
        );
    }
}
