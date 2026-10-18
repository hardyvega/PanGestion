<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\CountService;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryBootstrapService;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\StockDailyRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OutOfRangeException;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class CountServiceTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const CREATE_COMMAND = 'b2200000-0000-4000-8000-000000000001';

    private const CONFIRM_COMMAND = 'b2200000-0000-4000-8000-000000000002';

    private const LATER_COMMAND = 'b2200000-0000-4000-8000-000000000003';

    private User $actor;

    private User $otherActor;

    private User $unauthorizedActor;

    private Articulo $article;

    private Articulo $secondArticle;

    private Articulo $thirdArticle;

    private int $roleId;

    private ?CarbonImmutable $previousTestNow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTestNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13T15:30:00.123456+00:00'));

        $this->roleId = $this->createRole([
            'codigo' => 'operador_conteos_inventario',
            'nombre' => 'Operador de conteos de inventario',
            'activo' => true,
        ]);

        foreach ([
            PermissionCode::InventoryCountsManage,
            PermissionCode::InventoryCountsConfirm,
            PermissionCode::InventoryInitialBalanceExecute,
            PermissionCode::InventoryAdjustmentsInput,
            PermissionCode::InventoryAdjustmentsOutput,
        ] as $permission) {
            $permissionId = $this->createPermission([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'activo' => true,
            ]);
            $this->attachPermission($this->roleId, $permissionId);
        }

        $unauthorizedRoleId = $this->createRole([
            'codigo' => 'lector_conteos_inventario',
            'nombre' => 'Lector de conteos de inventario',
            'activo' => true,
        ]);

        $this->actor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'count_actor',
            'activo' => true,
        ]));
        $this->otherActor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'count_other_actor',
            'activo' => true,
        ]));
        $this->unauthorizedActor = User::query()->findOrFail($this->createUser($unauthorizedRoleId, [
            'nombre_usuario' => 'count_unauthorized_actor',
            'activo' => true,
        ]));

        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->article = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_principal',
            'nombre' => 'Articulo principal de conteo',
            'stock_actual' => '5.000',
        ]);
        $this->secondArticle = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_secundario',
            'nombre' => 'Articulo secundario de conteo',
            'stock_actual' => '4.000',
        ]);
        $this->thirdArticle = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_terciario',
            'nombre' => 'Articulo terciario de conteo',
            'stock_actual' => '8.000',
        ]);
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
        $methods = (new ReflectionClass(CountService::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
        sort($methodNames);

        $this->assertSame(['__construct', 'confirm', 'create'], $methodNames);

        $constructor = new ReflectionMethod(CountService::class, '__construct');
        $this->assertSame([
            EffectivePermissionResolver::class,
            InventoryLockManager::class,
            InventoryStockService::class,
            StockDailyRebuilder::class,
        ], array_map(
            static fn ($parameter): string => $parameter->getType()->getName(),
            $constructor->getParameters(),
        ));

        $create = new ReflectionMethod(CountService::class, 'create');
        $this->assertSame(
            ['actor', 'commandUuid', 'lines', 'observacion'],
            array_map(static fn ($parameter): string => $parameter->getName(), $create->getParameters()),
        );
        $this->assertSame(
            [User::class, 'string', 'array', 'string'],
            array_map(static fn ($parameter): string => $parameter->getType()->getName(), $create->getParameters()),
        );
        $this->assertTrue($create->getParameters()[3]->allowsNull());
        $this->assertTrue($create->getParameters()[3]->isDefaultValueAvailable());

        $confirm = new ReflectionMethod(CountService::class, 'confirm');
        $this->assertSame(
            ['actor', 'countId', 'commandUuid'],
            array_map(static fn ($parameter): string => $parameter->getName(), $confirm->getParameters()),
        );
        $this->assertSame(
            [User::class, 'int', 'string'],
            array_map(static fn ($parameter): string => $parameter->getType()->getName(), $confirm->getParameters()),
        );
    }

    public function test_create_persists_snapshots_observed_watermark_and_has_no_physical_effect(): void
    {
        $this->bootstrapArticle($this->article, 'b2200000-0000-4000-8000-000000000010');
        $observedMovement = $this->app->make(InventoryAdjustmentService::class)->manualEntry(
            $this->actor,
            (int) $this->article->getKey(),
            '1.000',
            'b2200000-0000-4000-8000-000000000011',
            'Movimiento observado del fixture',
        );
        $observedMovementId = (int) $observedMovement->getKey();
        $stockBefore = $this->stockOf($this->article);
        $movementCountBefore = DB::table('movimientos_inventario')->count();
        $dailyCountBefore = DB::table('stock_diario')->count();

        $count = $this->service()->create(
            $this->actor,
            self::CREATE_COMMAND,
            [
                ['articleId' => (int) $this->secondArticle->getKey(), 'countedQuantity' => '3.125'],
                ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '0.000'],
            ],
            '  Conteo de apertura  ',
        );

        $this->assertSame('borrador', $count->estado);
        $this->assertSame(self::CREATE_COMMAND, $count->clave_idempotencia_creacion);
        $this->assertSame((int) $this->actor->getKey(), $count->usuario_creador_id);
        $this->assertSame((int) $this->actor->getKey(), $count->usuario_responsable_id);
        $this->assertSame('Conteo de apertura', $count->observacion);
        $this->assertNull($count->usuario_confirmador_id);
        $this->assertNull($count->confirmada_en);

        $details = DB::table('detalle_conteos_inventario')
            ->where('conteo_inventario_id', $count->id)
            ->orderBy('articulo_id')
            ->get();

        $this->assertCount(2, $details);
        $this->assertSame((int) $this->article->getKey(), $details[0]->articulo_id);
        $this->assertSame('0.000', $details[0]->cantidad_contada);
        $this->assertSame($observedMovementId, $details[0]->movimiento_inventario_observado_id);
        $this->assertSame('conteo_principal', $details[0]->articulo_sku_snapshot);
        $this->assertSame('Articulo principal de conteo', $details[0]->articulo_nombre_snapshot);
        $this->assertSame('kg', $details[0]->unidad_codigo_snapshot);
        $this->assertSame('kg', $details[0]->unidad_simbolo_snapshot);
        $this->assertSame('3.125', $details[1]->cantidad_contada);
        $this->assertNull($details[1]->movimiento_inventario_observado_id);
        $this->assertNotNull($details[0]->contada_en);
        $this->assertNull($details[0]->stock_teorico_snapshot);
        $this->assertSame($stockBefore, $this->stockOf($this->article));
        $this->assertSame($movementCountBefore, DB::table('movimientos_inventario')->count());
        $this->assertSame($dailyCountBefore, DB::table('stock_diario')->count());
    }

    public function test_create_allows_inactive_articles_and_null_observed_watermarks(): void
    {
        $categoryId = (int) DB::table('articulos')
            ->where('id', $this->article->getKey())
            ->value('categoria_articulo_id');
        $unitId = (int) DB::table('articulos')
            ->where('id', $this->article->getKey())
            ->value('unidad_id');
        $inactiveZeroArticle = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_inactivo_cero',
            'nombre' => 'Articulo inactivo sin historial',
            'stock_actual' => '0.000',
            'activo' => false,
        ]);

        $count = $this->service()->create(
            $this->actor,
            self::CREATE_COMMAND,
            [['articleId' => (int) $inactiveZeroArticle->getKey(), 'countedQuantity' => '0.000']],
        );

        $detail = DB::table('detalle_conteos_inventario')->where('conteo_inventario_id', $count->id)->first();
        $this->assertSame('0.000', $detail->cantidad_contada);
        $this->assertNull($detail->movimiento_inventario_observado_id);
    }

    public function test_create_rejects_invalid_input_duplicate_and_missing_articles_atomically(): void
    {
        $cases = [
            [['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '-0.001']],
            [['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '1.0001']],
            [['articleId' => (int) $this->article->getKey(), 'countedQuantity' => 1.25]],
            [['articleId' => (int) $this->article->getKey(), 'countedQuantity' => 'not-a-number']],
            [
                ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '1.000'],
                ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '2.000'],
            ],
            [['articleId' => 999999, 'countedQuantity' => '1.000']],
        ];

        foreach ($cases as $index => $lines) {
            try {
                $this->service()->create(
                    $this->actor,
                    sprintf('b2200000-0000-4000-8000-%012d', $index + 20),
                    $lines,
                );
                $this->fail('La entrada invalida fue aceptada.');
            } catch (InvalidArgumentException|OutOfRangeException|ModelNotFoundException) {
                $this->assertSame(0, DB::table('conteos_inventario')->count());
                $this->assertSame(0, DB::table('detalle_conteos_inventario')->count());
            }
        }
    }

    public function test_create_requires_manage_permission_and_single_draft_guard(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service()->create(
            $this->unauthorizedActor,
            self::CREATE_COMMAND,
            [['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '1.000']],
        );
    }

    public function test_create_is_idempotent_order_independent_and_conflicts_on_intention_changes(): void
    {
        $service = $this->service();
        $lines = [
            ['articleId' => (int) $this->secondArticle->getKey(), 'countedQuantity' => '3.125'],
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '4.000'],
        ];
        $first = $service->create($this->actor, self::CREATE_COMMAND, $lines, 'Observacion');
        $retry = $service->create($this->actor, strtoupper(self::CREATE_COMMAND), array_reverse($lines), '  Observacion ');

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, DB::table('conteos_inventario')->count());
        $this->assertSame(2, DB::table('detalle_conteos_inventario')->count());

        foreach ([
            fn () => $service->create($this->otherActor, self::CREATE_COMMAND, $lines, 'Observacion'),
            fn () => $service->create($this->actor, self::CREATE_COMMAND, $lines, 'Otra observacion'),
            fn () => $service->create($this->actor, self::CREATE_COMMAND, [
                ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '4.000'],
            ], 'Observacion'),
            fn () => $service->create($this->actor, self::CREATE_COMMAND, [
                ['articleId' => (int) $this->secondArticle->getKey(), 'countedQuantity' => '3.124'],
                ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '4.000'],
            ], 'Observacion'),
        ] as $conflict) {
            $this->expectException(IdempotencyConflictException::class);
            $conflict();
        }
    }

    public function test_confirm_positive_negative_zero_and_mixed_plans_are_exact_and_causal(): void
    {
        $categoryId = (int) DB::table('articulos')
            ->where('id', $this->article->getKey())
            ->value('categoria_articulo_id');
        $unitId = (int) DB::table('articulos')
            ->where('id', $this->article->getKey())
            ->value('unidad_id');
        $mixedPositiveArticle = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_mixto_positivo',
            'nombre' => 'Articulo mixto positivo',
            'stock_actual' => '5.000',
        ]);
        $mixedZeroArticle = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_mixto_cero',
            'nombre' => 'Articulo mixto cero',
            'stock_actual' => '4.000',
        ]);
        $mixedNegativeArticle = $this->createArticleModel($categoryId, $unitId, [
            'sku' => 'conteo_mixto_negativo',
            'nombre' => 'Articulo mixto negativo',
            'stock_actual' => '8.000',
        ]);
        $this->bootstrapArticle($this->article, 'b2200000-0000-4000-8000-000000000007');
        $this->bootstrapArticle($this->secondArticle, 'b2200000-0000-4000-8000-000000000008');
        $this->bootstrapArticle($this->thirdArticle, 'b2200000-0000-4000-8000-000000000009');
        $this->bootstrapArticle($mixedPositiveArticle, 'b2200000-0000-4000-8000-000000000010');
        $this->bootstrapArticle($mixedZeroArticle, 'b2200000-0000-4000-8000-000000000011');
        $this->bootstrapArticle($mixedNegativeArticle, 'b2200000-0000-4000-8000-000000000012');

        $positive = $this->createDraft([
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '7.250'],
        ]);
        $positiveDetail = $this->detailFor($positive, $this->article);
        $confirmedPositive = $this->service()->confirm($this->actor, $positive, self::CONFIRM_COMMAND);
        $positiveMovement = DB::table('movimientos_inventario')->where('detalle_conteo_inventario_id', $positiveDetail)->first();

        $this->assertSame('confirmado', $confirmedPositive->estado);
        $this->assertSame('7.250', $this->stockOf($this->article));
        $this->assertSame('ajuste_conteo_positivo', $positiveMovement->tipo_movimiento);
        $this->assertSame('2.250', $positiveMovement->cantidad_delta);
        $this->assertSame('5.000', $positiveMovement->stock_anterior);
        $this->assertSame('7.250', $positiveMovement->stock_posterior);
        $this->assertSame($positiveDetail, $positiveMovement->detalle_conteo_inventario_id);
        $this->assertNull($positiveMovement->usuario_autorizador_id);
        $this->assertNull($positiveMovement->movimiento_revertido_id);
        $this->assertSame(self::CONFIRM_COMMAND, $positiveMovement->clave_idempotencia);
        $this->assertSame((int) $this->actor->getKey(), $positiveMovement->usuario_id);

        $negative = $this->createDraft([
            ['articleId' => (int) $this->secondArticle->getKey(), 'countedQuantity' => '3.750'],
        ]);
        $negativeDetail = $this->detailFor($negative, $this->secondArticle);
        $this->service()->confirm($this->actor, $negative, 'b2200000-0000-4000-8000-000000000004');
        $negativeMovement = DB::table('movimientos_inventario')->where('detalle_conteo_inventario_id', $negativeDetail)->first();
        $this->assertSame('ajuste_conteo_negativo', $negativeMovement->tipo_movimiento);
        $this->assertSame('-0.250', $negativeMovement->cantidad_delta);
        $this->assertSame('3.750', $this->stockOf($this->secondArticle));
        $this->assertSame(0, DB::table('mermas')->count());

        $zero = $this->createDraft([
            ['articleId' => (int) $this->thirdArticle->getKey(), 'countedQuantity' => '8.000'],
        ]);
        $this->service()->confirm($this->actor, $zero, 'b2200000-0000-4000-8000-000000000005');
        $zeroDetail = $this->detailFor($zero, $this->thirdArticle);
        $this->assertNull(DB::table('movimientos_inventario')->where('detalle_conteo_inventario_id', $zeroDetail)->first());
        $this->assertSame('8.000', DB::table('detalle_conteos_inventario')->where('id', $zeroDetail)->value('stock_teorico_snapshot'));

        $mixed = $this->createDraft([
            ['articleId' => (int) $mixedNegativeArticle->getKey(), 'countedQuantity' => '5.000'],
            ['articleId' => (int) $mixedZeroArticle->getKey(), 'countedQuantity' => '4.000'],
            ['articleId' => (int) $mixedPositiveArticle->getKey(), 'countedQuantity' => '7.000'],
        ]);
        $this->service()->confirm($this->actor, $mixed, 'b2200000-0000-4000-8000-000000000006');
        $mixedMovements = DB::table('movimientos_inventario')
            ->where('clave_idempotencia', 'b2200000-0000-4000-8000-000000000006')
            ->orderBy('articulo_id')
            ->get();
        $this->assertCount(2, $mixedMovements);
        $this->assertSame(1, $mixedMovements[0]->secuencia_idempotencia);
        $this->assertSame(2, $mixedMovements[1]->secuencia_idempotencia);
        $this->assertSame('7.000', $this->stockOf($mixedPositiveArticle));
        $this->assertSame('4.000', $this->stockOf($mixedZeroArticle));
        $this->assertSame('5.000', $this->stockOf($mixedNegativeArticle));
    }

    public function test_confirm_rejects_stale_watermarks_atomically_even_when_stock_returns_to_same_value(): void
    {
        $bootstrap = $this->bootstrapArticle($this->article, 'b2200000-0000-4000-8000-000000000030');
        $count = $this->createDraft([
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '5.000'],
        ]);
        $this->assertSame($bootstrap->getKey(), DB::table('detalle_conteos_inventario')->where('conteo_inventario_id', $count)->value('movimiento_inventario_observado_id'));

        $this->app->make(InventoryAdjustmentService::class)->manualExit(
            $this->actor,
            (int) $this->article->getKey(),
            '1.000',
            'b2200000-0000-4000-8000-000000000031',
            'Movimiento stale de salida',
        );
        $this->app->make(InventoryAdjustmentService::class)->manualEntry(
            $this->actor,
            (int) $this->article->getKey(),
            '1.000',
            'b2200000-0000-4000-8000-000000000032',
            'Movimiento stale de entrada',
        );
        $stockBefore = $this->stockOf($this->article);
        $movementCountBefore = DB::table('movimientos_inventario')->count();

        $this->expectException(InventoryDomainException::class);
        try {
            $this->service()->confirm($this->actor, $count, self::CONFIRM_COMMAND);
        } finally {
            $this->assertSame('borrador', DB::table('conteos_inventario')->where('id', $count)->value('estado'));
            $this->assertSame($stockBefore, $this->stockOf($this->article));
            $this->assertSame($movementCountBefore, DB::table('movimientos_inventario')->count());
            $this->assertNull(DB::table('detalle_conteos_inventario')->where('conteo_inventario_id', $count)->value('stock_teorico_snapshot'));
        }
    }

    public function test_confirm_rejects_null_to_nonnull_stale_transition_and_multiarticle_is_all_or_nothing(): void
    {
        $this->bootstrapArticle($this->article, 'b2200000-0000-4000-8000-000000000040');
        $this->bootstrapArticle($this->secondArticle, 'b2200000-0000-4000-8000-000000000041');
        $this->bootstrapArticle($this->thirdArticle, 'b2200000-0000-4000-8000-000000000042');
        $count = $this->createDraft([
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '7.000'],
            ['articleId' => (int) $this->secondArticle->getKey(), 'countedQuantity' => '6.000'],
            ['articleId' => (int) $this->thirdArticle->getKey(), 'countedQuantity' => '9.000'],
        ]);
        $this->app->make(InventoryAdjustmentService::class)->manualEntry(
            $this->actor,
            (int) $this->secondArticle->getKey(),
            '1.000',
            'b2200000-0000-4000-8000-000000000043',
            'Movimiento stale multiarticulo',
        );
        $movementCountBefore = DB::table('movimientos_inventario')->count();
        $stocksBefore = [
            $this->stockOf($this->article),
            $this->stockOf($this->secondArticle),
            $this->stockOf($this->thirdArticle),
        ];

        $this->expectException(InventoryDomainException::class);
        try {
            $this->service()->confirm($this->actor, $count, self::CONFIRM_COMMAND);
        } finally {
            $this->assertSame($movementCountBefore, DB::table('movimientos_inventario')->count());
            $this->assertSame($stocksBefore[0], $this->stockOf($this->article));
            $this->assertSame($stocksBefore[1], $this->stockOf($this->secondArticle));
            $this->assertSame($stocksBefore[2], $this->stockOf($this->thirdArticle));
            $this->assertSame('borrador', DB::table('conteos_inventario')->where('id', $count)->value('estado'));
        }
    }

    public function test_confirm_permission_is_checked_before_any_effect(): void
    {
        $count = $this->createDraft([
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '7.000'],
        ]);
        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->confirm($this->unauthorizedActor, $count, self::CONFIRM_COMMAND);
        } finally {
            $this->assertSame('borrador', DB::table('conteos_inventario')->where('id', $count)->value('estado'));
            $this->assertSame('5.000', $this->stockOf($this->article));
            $this->assertSame(0, DB::table('movimientos_inventario')->count());
        }
    }

    public function test_confirm_retry_is_idempotent_and_survives_a_later_legal_movement(): void
    {
        $this->bootstrapArticle($this->article, 'b2200000-0000-4000-8000-000000000050');
        $count = $this->createDraft([
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '7.000'],
        ]);
        $first = $this->service()->confirm($this->actor, $count, self::CONFIRM_COMMAND);
        $detailId = $this->detailFor($count, $this->article);
        $firstMovementId = (int) DB::table('movimientos_inventario')->where('detalle_conteo_inventario_id', $detailId)->value('id');
        $firstSnapshot = DB::table('detalle_conteos_inventario')->where('id', $detailId)->first();
        $movementCount = DB::table('movimientos_inventario')->count();

        $this->app->make(InventoryAdjustmentService::class)->manualEntry(
            $this->actor,
            (int) $this->article->getKey(),
            '1.000',
            self::LATER_COMMAND,
            'Movimiento posterior legal',
        );
        $stockAfterLaterMovement = $this->stockOf($this->article);
        $retry = $this->service()->confirm($this->actor, $count, strtoupper(self::CONFIRM_COMMAND));

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($stockAfterLaterMovement, $this->stockOf($this->article));
        $this->assertSame($movementCount + 1, DB::table('movimientos_inventario')->count());
        $this->assertSame($firstMovementId, DB::table('movimientos_inventario')->where('detalle_conteo_inventario_id', $detailId)->value('id'));
        $this->assertEquals($firstSnapshot, DB::table('detalle_conteos_inventario')->where('id', $detailId)->first());

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->confirm($this->actor, $count, 'b2200000-0000-4000-8000-000000000099');
    }

    public function test_confirm_rejects_confirmation_uuid_collision_and_different_actor_retry(): void
    {
        $this->bootstrapArticle($this->article, 'b2200000-0000-4000-8000-000000000060');
        $this->bootstrapArticle($this->secondArticle, 'b2200000-0000-4000-8000-000000000061');
        $firstCount = $this->createDraft([
            ['articleId' => (int) $this->article->getKey(), 'countedQuantity' => '5.000'],
        ]);
        $this->service()->confirm($this->actor, $firstCount, self::CONFIRM_COMMAND);

        $secondCount = $this->createDraft([
            ['articleId' => (int) $this->secondArticle->getKey(), 'countedQuantity' => '4.000'],
        ]);
        $this->expectException(IdempotencyConflictException::class);
        $this->service()->confirm($this->otherActor, $secondCount, self::CONFIRM_COMMAND);
    }

    private function service(): CountService
    {
        return $this->app->make(CountService::class);
    }

    /** @param array<int, array{articleId:int, countedQuantity:mixed}> $lines */
    private function createDraft(array $lines): int
    {
        static $sequence = 100;
        $count = $this->service()->create(
            $this->actor,
            sprintf('b2200000-0000-4000-8000-%012d', $sequence++),
            $lines,
            'Conteo de prueba',
        );

        return (int) $count->id;
    }

    private function detailFor(int $countId, Articulo $article): int
    {
        return (int) DB::table('detalle_conteos_inventario')
            ->where('conteo_inventario_id', $countId)
            ->where('articulo_id', $article->getKey())
            ->value('id');
    }

    private function createArticleModel(int $categoryId, int $unitId, array $overrides): Articulo
    {
        return Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, array_merge([
            'precio_venta_actual' => '1500.00',
            'activo' => true,
        ], $overrides)));
    }

    private function bootstrapArticle(Articulo $article, string $commandUuid): MovimientoInventario
    {
        $movement = $this->app->make(InventoryBootstrapService::class)->initialize(
            $this->actor,
            (int) $article->getKey(),
            $commandUuid,
            'Saldo inicial del fixture de conteo',
        );

        $this->assertInstanceOf(MovimientoInventario::class, $movement);

        return $movement;
    }

    private function stockOf(Articulo $article): string
    {
        return (string) DB::table('articulos')->where('id', $article->getKey())->value('stock_actual');
    }
}
