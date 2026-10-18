<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryBootstrapService;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\StockDailyRebuilder;
use App\Services\Inventory\WasteService;
use App\Support\CommercialDate;
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
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;
use Throwable;

final class WasteServiceTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const RECORD_COMMAND = 'b1400000-0000-4000-8000-000000000001';

    private const LATER_COMMAND = 'b1400000-0000-4000-8000-000000000002';

    private const ANNUL_COMMAND = 'b1400000-0000-4000-8000-000000000003';

    private const RECORD_REASON = 'Producto danado durante la manipulacion';

    private const ANNUL_REASON = 'Registro duplicado comprobado';

    private const BOOTSTRAP_COMMAND = 'b1490000-0000-4000-8000-000000000001';

    private User $actor;

    private User $otherActor;

    private Articulo $article;

    private Articulo $fractionalArticle;

    private Articulo $inactiveArticle;

    private int $roleId;

    /** @var array<string, int> */
    private array $permissionIds = [];

    private int $uuidSequence = 100;

    private ?CarbonImmutable $previousTestNow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTestNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09T15:30:00.123456+00:00'));
        $this->roleId = $this->createRole([
            'codigo' => 'operador_mermas_inventario',
            'nombre' => 'Operador de mermas de inventario',
            'activo' => true,
        ]);

        foreach ([
            PermissionCode::InventoryInitialBalanceExecute,
            PermissionCode::InventoryWasteRegister,
            PermissionCode::InventoryWasteAnnul,
            PermissionCode::InventoryWasteRetroactive,
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
            'nombre_usuario' => 'waste_actor',
            'activo' => true,
        ]));
        $this->otherActor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'waste_other_actor',
            'activo' => true,
        ]));
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->article = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'merma_principal',
            'nombre' => 'Articulo principal para merma',
            'stock_actual' => '10.000',
            'precio_venta_actual' => '1500.00',
            'activo' => true,
        ]));
        $this->fractionalArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'merma_fraccional',
            'nombre' => 'Articulo fraccional para merma',
            'stock_actual' => '4.125',
            'precio_venta_actual' => '900.00',
            'activo' => true,
        ]));
        $this->inactiveArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'merma_inactiva',
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
            'Saldo inicial para mermas',
        );
        $bootstrap->initialize(
            $this->actor,
            (int) $this->fractionalArticle->getKey(),
            'b1490000-0000-4000-8000-000000000002',
            'Saldo inicial para mermas',
        );
        $bootstrap->initialize(
            $this->actor,
            (int) $this->inactiveArticle->getKey(),
            'b1490000-0000-4000-8000-000000000003',
            'Saldo inicial para mermas',
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
        $methods = (new ReflectionClass(WasteService::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
        sort($methodNames);
        $this->assertSame(['__construct', 'annul', 'record'], $methodNames);

        $constructor = new ReflectionMethod(WasteService::class, '__construct');
        $this->assertSame([
            EffectivePermissionResolver::class,
            InventoryLockManager::class,
            InventoryStockService::class,
            StockDailyRebuilder::class,
        ], array_map(
            static fn ($parameter): string => $parameter->getType()->getName(),
            $constructor->getParameters(),
        ));

        $record = new ReflectionMethod(WasteService::class, 'record');
        $this->assertSame(
            ['actor', 'articleId', 'quantity', 'commandUuid', 'motivo', 'occurredAt'],
            array_map(static fn ($parameter): string => $parameter->getName(), $record->getParameters()),
        );
        $this->assertSame(
            [User::class, 'int', 'string', 'string', 'string', CarbonImmutable::class],
            array_map(static fn ($parameter): string => $parameter->getType()->getName(), $record->getParameters()),
        );
        $this->assertTrue($record->getParameters()[5]->allowsNull());
        $this->assertTrue($record->getParameters()[5]->isDefaultValueAvailable());
        $this->assertNull($record->getParameters()[5]->getDefaultValue());
        $this->assertSame(MovimientoInventario::class, $record->getReturnType()->getName());

        $annul = new ReflectionMethod(WasteService::class, 'annul');
        $this->assertSame(
            ['actor', 'wasteId', 'commandUuid', 'motivo'],
            array_map(static fn ($parameter): string => $parameter->getName(), $annul->getParameters()),
        );
        $this->assertSame(
            [User::class, 'int', 'string', 'string'],
            array_map(static fn ($parameter): string => $parameter->getType()->getName(), $annul->getParameters()),
        );
        $this->assertSame(MovimientoInventario::class, $annul->getReturnType()->getName());
    }

    public function test_record_persists_waste_stock_ledger_and_daily_projection_exactly(): void
    {
        $movement = $this->record('1.250');
        $waste = $this->wasteForMovement($movement);

        $this->assertSame('8.750', $this->stockOf($this->article));
        $this->assertWasteAggregate(
            $waste,
            $this->article,
            $this->actor,
            self::RECORD_COMMAND,
            '1.250',
            self::RECORD_REASON,
            null,
        );
        $this->assertWasteMovement(
            $movement,
            $this->article,
            InventoryMovementType::Waste,
            '-1.250',
            '10.000',
            '8.750',
            self::RECORD_COMMAND,
            self::RECORD_REASON,
            (int) $waste->id,
            null,
            null,
        );
        $this->assertDaily($this->article, '2026-10-11', '10.000', '0.000', '1.250', '8.750', $movement);
        $this->assertDatabaseCount('mermas', 1);
        $this->assertSame(1, DB::table('movimientos_inventario')->where('merma_id', $waste->id)->count());
    }

    public function test_fractional_waste_preserves_exact_thousandths(): void
    {
        $movement = $this->service()->record(
            $this->actor,
            (int) $this->fractionalArticle->getKey(),
            '2.375',
            self::RECORD_COMMAND,
            self::RECORD_REASON,
        );

        $this->assertSame('1.750', $this->stockOf($this->fractionalArticle));
        $this->assertSame('4.125', $movement->stock_anterior);
        $this->assertSame('-2.375', $movement->cantidad_delta);
        $this->assertSame('1.750', $movement->stock_posterior);
        $this->assertSame('2.375', DB::table('mermas')->where('id', $movement->merma_id)->value('cantidad'));
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_is_rejected_atomically(string $quantity, string $exceptionClass): void
    {
        $this->assertRejectedWithoutChanges(
            $exceptionClass,
            fn () => $this->record($quantity),
        );
    }

    public static function invalidQuantities(): array
    {
        return [
            'zero' => ['0.000', InvalidArgumentException::class],
            'negative' => ['-1.000', InvalidArgumentException::class],
            'excess scale' => ['1.0001', InvalidArgumentException::class],
            'scientific notation' => ['1e3', InvalidArgumentException::class],
            'conceptual NaN' => ['NaN', InvalidArgumentException::class],
            'overflow' => ['100000000000.000', OutOfRangeException::class],
        ];
    }

    public function test_blank_record_reason_is_rejected_atomically(): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->record(motivo: " \t\n "),
        );
    }

    public function test_record_reason_is_trimmed_and_retry_treats_outer_whitespace_as_equivalent(): void
    {
        $original = $this->record(motivo: "  \t".self::RECORD_REASON." \n");
        $before = $this->operationalState();
        $retry = $this->record();

        $this->assertSame(self::RECORD_REASON, $original->motivo);
        $this->assertSame(self::RECORD_REASON, DB::table('mermas')->where('id', $original->merma_id)->value('motivo_detalle'));
        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame($before, $this->operationalState());
    }

    public function test_missing_article_is_rejected_without_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            ModelNotFoundException::class,
            fn () => $this->service()->record(
                $this->actor,
                PHP_INT_MAX,
                '1.000',
                self::RECORD_COMMAND,
                self::RECORD_REASON,
            ),
        );
    }

    public function test_record_allows_an_inactive_article_with_physical_stock(): void
    {
        $movement = $this->service()->record(
            $this->actor,
            (int) $this->inactiveArticle->getKey(),
            '1.250',
            self::RECORD_COMMAND,
            self::RECORD_REASON,
        );

        $this->assertFalse($this->inactiveArticle->refresh()->activo);
        $this->assertSame('1.750', $this->stockOf($this->inactiveArticle));
        $this->assertSame(InventoryMovementType::Waste, $movement->tipo_movimiento);
        $this->assertSame('-1.250', $movement->cantidad_delta);
    }

    public function test_record_rejects_insufficient_stock_without_partial_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            InsufficientStockException::class,
            fn () => $this->record('10.001'),
        );
        $this->assertSame('10.000', $this->stockOf($this->article));
        $this->assertDatabaseCount('mermas', 0);
    }

    public function test_record_can_reduce_stock_exactly_to_zero(): void
    {
        $movement = $this->record('10.000');

        $this->assertSame('0.000', $this->stockOf($this->article));
        $this->assertSame('0.000', $movement->stock_posterior);
    }

    public function test_record_requires_the_exact_permission(): void
    {
        $this->revoke(PermissionCode::InventoryWasteRegister);

        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->record(),
        );
    }

    public function test_normal_record_uses_server_now_without_elevated_authorizer(): void
    {
        $movement = $this->record();
        $waste = $this->wasteForMovement($movement);

        $this->assertTrue($movement->ocurrido_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertNull($movement->usuario_autorizador_id);
        $this->assertTrue(CarbonImmutable::parse($waste->ocurrido_en)->equalTo(CarbonImmutable::now('UTC')));
        $this->assertTrue(CarbonImmutable::parse($waste->created_at)->equalTo(CarbonImmutable::now('UTC')));
        $this->assertNull($waste->motivo_ajuste_ocurrido_en);
    }

    public function test_future_occurrence_is_always_rejected(): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->record(occurredAt: CarbonImmutable::now('UTC')->addMicrosecond()),
        );
    }

    public function test_retroactive_record_requires_the_exact_elevated_permission(): void
    {
        $this->revoke(PermissionCode::InventoryWasteRetroactive);

        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->record(occurredAt: CarbonImmutable::now('UTC')->subDay()),
        );
    }

    public function test_retroactive_record_preserves_instant_reason_and_self_authorization(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T12:15:30.123456+00:00');
        $movement = $this->record('2.000', occurredAt: $occurredAt);
        $waste = $this->wasteForMovement($movement);

        $this->assertTrue($movement->ocurrido_en->equalTo($occurredAt));
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_autorizador_id);
        $this->assertTrue(CarbonImmutable::parse($waste->ocurrido_en)->equalTo($occurredAt));
        $this->assertSame(self::RECORD_REASON, $waste->motivo_ajuste_ocurrido_en);
        $this->assertSame('8.000', $this->stockOf($this->article));
        $this->assertDaily($this->article, '2026-10-10', '10.000', '0.000', '2.000', '8.000', $movement);
        $this->assertDaily($this->article, '2026-10-11', '8.000', '0.000', '0.000', '8.000', $movement);
    }

    public function test_invalid_record_uuid_is_rejected_without_effects(): void
    {
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->record(command: 'not-a-uuid'),
        );
    }

    public function test_normal_record_retry_returns_original_without_a_second_effect_even_when_now_changes(): void
    {
        $original = $this->record('2.000');
        $before = $this->operationalState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));

        $retry = $this->record('2.000');

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame('8.000', $this->stockOf($this->article));
        $this->assertSame($before, $this->operationalState());
    }

    public function test_retroactive_record_retry_requires_the_same_explicit_instant(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T11:00:00.123456+00:00');
        $original = $this->record('1.000', occurredAt: $occurredAt);
        $before = $this->operationalState();
        $retry = $this->record('1.000', occurredAt: $occurredAt);

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame($before, $this->operationalState());
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->record('1.000', occurredAt: $occurredAt->addSecond()),
        );
    }

    public function test_record_retry_after_later_movement_preserves_current_stock_and_original_evidence(): void
    {
        $original = $this->record('2.000');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $later = $this->applyStockMutation(InventoryMovementType::ManualOutput, '-1.000', self::LATER_COMMAND);
        $before = $this->operationalState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());

        $retry = $this->record('2.000');

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertNotSame($original->getKey(), $later->getKey());
        $this->assertSame('7.000', $this->stockOf($this->article));
        $this->assertSame($before, $this->operationalState());
    }

    public function test_record_uuid_reuse_for_another_article_conflicts(): void
    {
        $this->record();

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->record(
                $this->actor,
                (int) $this->fractionalArticle->getKey(),
                '1.000',
                self::RECORD_COMMAND,
                self::RECORD_REASON,
            ),
        );
    }

    public function test_record_uuid_reuse_by_another_actor_conflicts(): void
    {
        $this->record();

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->record(
                $this->otherActor,
                (int) $this->article->getKey(),
                '1.000',
                self::RECORD_COMMAND,
                self::RECORD_REASON,
            ),
        );
    }

    public function test_record_uuid_reuse_with_different_quantity_conflicts(): void
    {
        $this->record();

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->record('1.001'),
        );
    }

    public function test_record_uuid_reuse_with_different_reason_conflicts(): void
    {
        $this->record();

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->record(motivo: 'Motivo contractual distinto'),
        );
    }

    public function test_record_does_not_claim_a_uuid_owned_by_another_movement_type(): void
    {
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->record(command: self::BOOTSTRAP_COMMAND),
        );
    }

    public function test_annul_reverses_the_complete_waste_and_persists_audit_fields(): void
    {
        $original = $this->record('3.000');
        $wasteId = (int) $original->merma_id;
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());

        $reversal = $this->annul($wasteId);
        $waste = DB::table('mermas')->where('id', $wasteId)->first();

        $this->assertNotNull($waste);
        $this->assertSame('10.000', $this->stockOf($this->article));
        $this->assertSame((int) $this->actor->getKey(), $waste->usuario_anulador_id);
        $this->assertSame(self::ANNUL_REASON, $waste->motivo_anulacion);
        $this->assertTrue(CarbonImmutable::parse($waste->anulada_en)->equalTo(CarbonImmutable::now('UTC')));
        $this->assertWasteMovement(
            $reversal,
            $this->article,
            InventoryMovementType::WasteReversal,
            '3.000',
            '7.000',
            '10.000',
            self::ANNUL_COMMAND,
            self::ANNUL_REASON,
            $wasteId,
            (int) $original->getKey(),
            null,
        );
        $this->assertSame(2, DB::table('movimientos_inventario')->where('merma_id', $wasteId)->count());
        $this->assertSame(1, DB::table('movimientos_inventario')
            ->where('movimiento_revertido_id', $original->getKey())->count());
        $this->assertDaily($this->article, '2026-10-11', '10.000', '3.000', '3.000', '10.000', $reversal);
    }

    public function test_annul_uses_current_locked_stock_after_a_later_legal_movement(): void
    {
        $original = $this->record('3.000');
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->applyStockMutation(InventoryMovementType::ManualOutput, '-2.000', self::LATER_COMMAND);
        $this->assertSame('5.000', $this->stockOf($this->article));
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());

        $reversal = $this->annul((int) $original->merma_id);

        $this->assertSame('8.000', $this->stockOf($this->article));
        $this->assertSame('5.000', $reversal->stock_anterior);
        $this->assertSame('3.000', $reversal->cantidad_delta);
        $this->assertSame('8.000', $reversal->stock_posterior);
        $this->assertNotSame($original->stock_posterior, $reversal->stock_anterior);
        $this->assertNotSame($original->stock_anterior, $reversal->stock_posterior);
    }

    public function test_annul_allows_an_inactive_article(): void
    {
        $original = $this->service()->record(
            $this->actor,
            (int) $this->inactiveArticle->getKey(),
            '1.250',
            self::RECORD_COMMAND,
            self::RECORD_REASON,
        );

        $reversal = $this->annul((int) $original->merma_id);

        $this->assertFalse($this->inactiveArticle->refresh()->activo);
        $this->assertSame('3.000', $this->stockOf($this->inactiveArticle));
        $this->assertSame(InventoryMovementType::WasteReversal, $reversal->tipo_movimiento);
    }

    public function test_annul_requires_the_exact_permission(): void
    {
        $original = $this->record();
        $this->revoke(PermissionCode::InventoryWasteAnnul);

        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->annul((int) $original->merma_id),
        );
    }

    public function test_annul_requires_a_non_blank_trimmed_reason_and_normalizes_retry(): void
    {
        $original = $this->record();
        $wasteId = (int) $original->merma_id;
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->annul($wasteId, motivo: " \t\n "),
        );

        $reversal = $this->annul($wasteId, motivo: "  \t".self::ANNUL_REASON." \n");
        $before = $this->operationalState();
        $retry = $this->annul($wasteId);

        $this->assertSame(self::ANNUL_REASON, $reversal->motivo);
        $this->assertSame(self::ANNUL_REASON, DB::table('mermas')->where('id', $wasteId)->value('motivo_anulacion'));
        $this->assertSame($reversal->getKey(), $retry->getKey());
        $this->assertSame($before, $this->operationalState());
    }

    public function test_annul_of_retroactive_waste_uses_now_and_current_commercial_day(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-10-10T12:00:00.123456+00:00');
        $original = $this->record('3.000', occurredAt: $occurredAt);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());

        $reversal = $this->annul((int) $original->merma_id);

        $this->assertTrue($original->ocurrido_en->equalTo($occurredAt));
        $this->assertTrue($reversal->ocurrido_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertDaily($this->article, '2026-10-10', '10.000', '0.000', '3.000', '7.000', $original);
        $this->assertDaily($this->article, '2026-10-11', '7.000', '3.000', '0.000', '10.000', $reversal);
    }

    public function test_second_annul_with_a_different_uuid_is_rejected_without_second_increment(): void
    {
        $original = $this->record('2.000');
        $wasteId = (int) $original->merma_id;
        $this->annul($wasteId);

        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->annul($wasteId, $this->uuid()),
        );
        $this->assertSame('10.000', $this->stockOf($this->article));
        $this->assertSame(1, DB::table('movimientos_inventario')
            ->where('movimiento_revertido_id', $original->getKey())->count());
    }

    public function test_annul_retry_returns_the_same_reversal_without_duplicate_effects(): void
    {
        $original = $this->record('2.000');
        $wasteId = (int) $original->merma_id;
        $first = $this->annul($wasteId);
        $before = $this->operationalState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));

        $retry = $this->annul($wasteId);

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($before, $this->operationalState());
        $this->assertSame('10.000', $this->stockOf($this->article));
    }

    public function test_annul_uuid_reuse_with_different_reason_conflicts(): void
    {
        $original = $this->record();
        $wasteId = (int) $original->merma_id;
        $this->annul($wasteId);

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->annul($wasteId, motivo: 'Motivo de anulacion diferente'),
        );
    }

    public function test_annul_uuid_reuse_by_another_actor_conflicts(): void
    {
        $original = $this->record();
        $wasteId = (int) $original->merma_id;
        $this->annul($wasteId);

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->annul(
                $this->otherActor,
                $wasteId,
                self::ANNUL_COMMAND,
                self::ANNUL_REASON,
            ),
        );
    }

    public function test_annul_uuid_reuse_for_another_waste_conflicts(): void
    {
        $first = $this->record('1.000', $this->uuid());
        $second = $this->record('2.000', $this->uuid());
        $annulCommand = $this->uuid();
        $this->annul((int) $first->merma_id, $annulCommand);

        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->annul((int) $second->merma_id, $annulCommand),
        );
    }

    public function test_annul_does_not_mutate_the_original_ledger_row(): void
    {
        $original = $this->record('3.000');
        $evidence = (array) DB::table('movimientos_inventario')->where('id', $original->getKey())->first();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
        $this->applyStockMutation(InventoryMovementType::ManualOutput, '-1.000', self::LATER_COMMAND);

        $this->annul((int) $original->merma_id);

        $this->assertSame(
            $evidence,
            (array) DB::table('movimientos_inventario')->where('id', $original->getKey())->first(),
        );
    }

    public function test_waste_workflow_does_not_touch_cost_price_cash_or_other_aggregates(): void
    {
        $cashSessionId = $this->createCashSession($this->createCashRegister(), (int) $this->actor->getKey());
        $this->createCashMovement($cashSessionId, (int) $this->actor->getKey());
        $this->createPriceHistory((int) $this->article->getKey(), (int) $this->actor->getKey());
        $this->createArticleCostHistory((int) $this->article->getKey(), (int) $this->actor->getKey());
        $before = $this->unrelatedDomainState();
        $prices = DB::table('articulos')->orderBy('id')->pluck('precio_venta_actual', 'id')->all();

        $original = $this->record('1.000');
        $this->annul((int) $original->merma_id);

        $this->assertSame($before, $this->unrelatedDomainState());
        $this->assertSame($prices, DB::table('articulos')->orderBy('id')->pluck('precio_venta_actual', 'id')->all());
    }

    public function test_later_waste_is_append_only_and_annul_only_adds_a_reversal(): void
    {
        $first = $this->record('1.250', $this->uuid());
        $firstWasteBefore = (array) DB::table('mermas')->where('id', $first->merma_id)->first();
        $firstMovementBefore = (array) DB::table('movimientos_inventario')->where('id', $first->getKey())->first();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());

        $second = $this->record('0.500', $this->uuid(), 'Segunda merma documentada');

        $this->assertSame($firstWasteBefore, (array) DB::table('mermas')->where('id', $first->merma_id)->first());
        $this->assertSame($firstMovementBefore, (array) DB::table('movimientos_inventario')->where('id', $first->getKey())->first());
        $this->assertNotSame($first->merma_id, $second->merma_id);
        $beforeCount = DB::table('movimientos_inventario')->count();
        $this->annul((int) $first->merma_id, $this->uuid());
        $this->assertSame($beforeCount + 1, DB::table('movimientos_inventario')->count());
        $this->assertSame($firstMovementBefore, (array) DB::table('movimientos_inventario')->where('id', $first->getKey())->first());
    }

    public function test_record_and_annul_own_one_transaction_and_follow_the_approved_lock_order(): void
    {
        $connection = DB::connection();
        $baseLevel = $connection->transactionLevel();
        $recordQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$recordQueries): void {
            $recordQueries[] = [
                'sql' => strtolower($query->sql),
                'level' => $query->connection->transactionLevel(),
            ];
        });

        $original = $this->record('1.000');

        $wasteRetryLock = $this->queryIndex($recordQueries, -1, 'from "mermas"', 'clave_idempotencia', 'for update');
        $commandLock = $this->queryIndex($recordQueries, $wasteRetryLock, 'from "movimientos_inventario"', 'clave_idempotencia', 'for update');
        $articleLock = $this->queryIndex($recordQueries, $commandLock, 'from "articulos"', 'for update');
        $wasteInsert = $this->queryIndex($recordQueries, $articleLock, 'insert into "mermas"');
        $stockUpdate = $this->queryIndex($recordQueries, $wasteInsert, 'update "articulos"');
        $ledgerInsert = $this->queryIndex($recordQueries, $stockUpdate, 'insert into "movimientos_inventario"');
        $dailyWrite = $this->queryIndex($recordQueries, $ledgerInsert, 'insert into "stock_diario"');
        foreach ([$wasteRetryLock, $commandLock, $articleLock, $wasteInsert, $stockUpdate, $ledgerInsert, $dailyWrite] as $index) {
            $this->assertSame($baseLevel + 1, $recordQueries[$index]['level']);
        }
        $this->assertSame($baseLevel, $connection->transactionLevel());

        $annulQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$annulQueries): void {
            $annulQueries[] = [
                'sql' => strtolower($query->sql),
                'level' => $query->connection->transactionLevel(),
            ];
        });
        $this->annul((int) $original->merma_id);

        $rootLock = $this->queryIndex($annulQueries, -1, 'from "mermas"', 'where "id"', 'for update');
        $annulArticleLock = $this->queryIndex($annulQueries, $rootLock, 'from "articulos"', 'for update');
        $originalLock = $this->queryIndex($annulQueries, $annulArticleLock, 'from "movimientos_inventario"', 'merma_id', 'tipo_movimiento', 'for update');
        $annulCommandLock = $this->queryIndex($annulQueries, $originalLock, 'from "movimientos_inventario"', 'clave_idempotencia', 'for update');
        $reversalLock = $this->queryIndex($annulQueries, $annulCommandLock, 'from "movimientos_inventario"', 'movimiento_revertido_id', 'for update');
        $annulStockUpdate = $this->queryIndex($annulQueries, $reversalLock, 'update "articulos"');
        $reversalInsert = $this->queryIndex($annulQueries, $annulStockUpdate, 'insert into "movimientos_inventario"');
        $annulDailyWrite = $this->queryIndex($annulQueries, $reversalInsert, 'insert into "stock_diario"');
        $auditUpdate = $this->queryIndex($annulQueries, $annulDailyWrite, 'update "mermas"');
        foreach ([$rootLock, $annulArticleLock, $originalLock, $annulCommandLock, $reversalLock, $annulStockUpdate, $reversalInsert, $annulDailyWrite, $auditUpdate] as $index) {
            $this->assertSame($baseLevel + 1, $annulQueries[$index]['level']);
        }
        $this->assertSame($baseLevel, $connection->transactionLevel());
    }

    private function service(): WasteService
    {
        return $this->app->make(WasteService::class);
    }

    private function record(
        string $quantity = '1.000',
        string $command = self::RECORD_COMMAND,
        string $motivo = self::RECORD_REASON,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        return $this->service()->record(
            $this->actor,
            (int) $this->article->getKey(),
            $quantity,
            $command,
            $motivo,
            $occurredAt,
        );
    }

    private function annul(
        int $wasteId,
        string $command = self::ANNUL_COMMAND,
        string $motivo = self::ANNUL_REASON,
    ): MovimientoInventario {
        return $this->service()->annul($this->actor, $wasteId, $command, $motivo);
    }

    private function assertWasteAggregate(
        object $waste,
        Articulo $article,
        User $actor,
        string $command,
        string $quantity,
        string $reason,
        ?string $occurrenceAdjustmentReason,
    ): void {
        $this->assertSame((int) $article->getKey(), $waste->articulo_id);
        $this->assertSame((int) $actor->getKey(), $waste->usuario_registrador_id);
        $this->assertNull($waste->usuario_anulador_id);
        $this->assertNull($waste->merma_reemplazada_id);
        $this->assertSame($command, $waste->clave_idempotencia);
        $this->assertSame($article->sku, $waste->articulo_sku_snapshot);
        $this->assertSame($article->nombre, $waste->articulo_nombre_snapshot);
        $this->assertSame('otro', $waste->tipo_merma);
        $this->assertSame($reason, $waste->motivo_detalle);
        $this->assertSame($quantity, $waste->cantidad);
        $this->assertSame($occurrenceAdjustmentReason, $waste->motivo_ajuste_ocurrido_en);
        $this->assertNull($waste->anulada_en);
        $this->assertNull($waste->motivo_anulacion);
    }

    private function assertWasteMovement(
        MovimientoInventario $movement,
        Articulo $article,
        InventoryMovementType $type,
        string $delta,
        string $before,
        string $after,
        string $command,
        string $reason,
        int $wasteId,
        ?int $reversedMovementId,
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
        $this->assertSame($wasteId, $movement->merma_id);
        $this->assertSame($reversedMovementId, $movement->movimiento_revertido_id);
        foreach ([
            'detalle_venta_id',
            'detalle_recepcion_mercaderia_id',
            'detalle_produccion_id',
            'consumo_produccion_id',
            'detalle_conteo_inventario_id',
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

    private function wasteForMovement(MovimientoInventario $movement): object
    {
        return DB::table('mermas')->where('id', $movement->merma_id)->firstOrFail();
    }

    private function applyStockMutation(
        InventoryMovementType $type,
        string $delta,
        string $command,
    ): MovimientoInventario {
        return DB::transaction(function () use ($type, $delta, $command): MovimientoInventario {
            $articleId = (int) $this->article->getKey();
            $locked = $this->app->make(InventoryLockManager::class)->lockArticles([$articleId]);
            $article = $locked->get($articleId);
            $unit = DB::table('unidades_medida')
                ->where('id', $article->unidad_id)
                ->first(['codigo', 'simbolo']);
            $occurredAt = CarbonImmutable::now('UTC');
            $simulation = $this->app->make(InventoryStockService::class)->simulatePlan($locked, $command, [
                new InventoryStockMutation(
                    articleId: $articleId,
                    type: $type,
                    delta: $delta,
                    position: 1,
                    occurredAt: $occurredAt,
                    actorId: (int) $this->actor->getKey(),
                    authorizerId: null,
                    reason: 'Movimiento posterior autorizado',
                    unitCode: $unit->codigo,
                    unitSymbol: $unit->simbolo,
                    saleDetailId: null,
                    receiptDetailId: null,
                    productionDetailId: null,
                    productionConsumptionId: null,
                    wasteId: null,
                    inventoryCountDetailId: null,
                    reversedMovementId: null,
                ),
            ]);
            $movements = $this->app->make(InventoryStockService::class)->applyPlan($simulation);
            $this->app->make(StockDailyRebuilder::class)->rebuild(
                [$articleId],
                CommercialDate::fromInstant($occurredAt),
            );

            return $movements[0];
        });
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
            'mermas',
            'movimientos_inventario',
            'stock_diario',
            'historial_costos_articulo',
            'historial_precios_venta',
            'sesiones_caja',
            'movimientos_caja',
            'pedidos',
            'ventas',
            'producciones',
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
    private function queryIndex(array $queries, int $after, string ...$needles): int
    {
        foreach ($queries as $index => $query) {
            if ($index > $after
                && array_all($needles, static fn (string $needle): bool => str_contains($query['sql'], $needle))) {
                return $index;
            }
        }

        $this->fail('No se encontro la consulta esperada: '.implode(' + ', $needles));
    }

    private function uuid(): string
    {
        return sprintf('b1400000-0000-4000-8000-%012d', $this->uuidSequence++);
    }
}
