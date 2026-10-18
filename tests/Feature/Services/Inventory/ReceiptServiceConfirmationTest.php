<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Enums\ReceiptOriginType;
use App\Enums\ReceiptStatus;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\DetalleRecepcionMercaderia;
use App\Models\MovimientoInventario;
use App\Models\RecepcionMercaderia;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryBootstrapService;
use App\Services\Inventory\InventoryLedgerWriter;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\ReceiptService;
use App\Services\Inventory\StockDailyRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;
use Throwable;

final class ReceiptServiceConfirmationTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private User $actor;

    private User $otherActor;

    private User $contributor;

    private Articulo $article;

    private Articulo $otherArticle;

    private int $supplierId;

    private int $roleId;

    /** @var array<string, int> */
    private array $permissionIds = [];

    private int $uuidSequence = 1;

    private ?CarbonImmutable $previousTestNow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTestNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08T15:30:00.123456+00:00'));
        $this->roleId = $this->createRole([
            'codigo' => 'operador_recepcion_fisica',
            'nombre' => 'Operador de recepcion fisica',
        ]);

        foreach ([
            PermissionCode::InventoryReceiptsCreate,
            PermissionCode::InventoryReceiptsEdit,
            PermissionCode::InventoryReceiptsConfirm,
            PermissionCode::InventoryReceiptsAnnul,
            PermissionCode::InventoryReceiptsOtherOrigin,
            PermissionCode::InventoryReceiptsRetroactive,
            PermissionCode::InventoryCostsRegister,
            PermissionCode::InventoryInitialBalanceExecute,
        ] as $permission) {
            $permissionId = $this->createPermission([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
            ]);
            $this->attachPermission($this->roleId, $permissionId);
            $this->permissionIds[$permission->value] = $permissionId;
        }

        $this->actor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'receipt_physical_actor',
        ]));
        $this->otherActor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'receipt_physical_other',
        ]));
        $this->contributor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'receipt_physical_contributor',
        ]));
        $this->supplierId = $this->createSupplier();
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->article = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'recepcion_fisica_pan',
            'nombre' => 'Pan recepcion fisica',
            'stock_actual' => '5.000',
            'precio_venta_actual' => '1500.00',
        ]));
        $this->otherArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'recepcion_fisica_harina',
            'nombre' => 'Harina recepcion fisica',
            'stock_actual' => '1.125',
            'precio_venta_actual' => '900.00',
        ]));

        $bootstrap = $this->app->make(InventoryBootstrapService::class);
        $bootstrap->initialize($this->actor, (int) $this->article->getKey(), $this->uuid(), 'Saldo inicial de prueba');
        $bootstrap->initialize($this->actor, (int) $this->otherArticle->getKey(), $this->uuid(), 'Saldo inicial de prueba');
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow($this->previousTestNow);
        } finally {
            parent::tearDown();
        }
    }

    public function test_confirm_persists_stock_ledger_cost_daily_and_header_without_price_or_cash_effects(): void
    {
        $cashSessionId = $this->createCashSession($this->createCashRegister(), (int) $this->actor->getKey());
        $this->createCashMovement($cashSessionId, (int) $this->actor->getKey());
        $this->createPriceHistory((int) $this->article->getKey(), (int) $this->actor->getKey());
        $previousCostId = $this->createArticleCostHistory(
            (int) $this->article->getKey(),
            (int) $this->actor->getKey(),
            ['costo_nuevo' => '900.00', 'vigente_desde' => CarbonImmutable::now('UTC')->subDay()],
        );
        $priceState = $this->tableState(
            ['articulos', 'historial_precios_venta'],
            ['stock_actual', 'updated_at'],
        );
        $cashState = $this->tableState(['sesiones_caja', 'movimientos_caja']);
        [$receipt, $lines] = $this->completeSupplierReceipt([
            [(int) $this->article->getKey(), '3.250', '1200.50', null],
        ]);
        $confirmed = $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $line = $lines[0]->refresh();
        $movement = $this->receiptMovement((int) $line->getKey(), InventoryMovementType::Receipt);

        $this->assertSame(ReceiptStatus::Confirmed, $confirmed->estado);
        $this->assertSame((int) $this->actor->getKey(), $confirmed->usuario_confirmador_id);
        $this->assertTrue($confirmed->confirmada_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertNull($confirmed->usuario_anulador_id);
        $this->assertNull($confirmed->anulada_en);
        $this->assertNull($confirmed->motivo_anulacion);
        $this->assertSame('8.250', $this->stockOf($this->article));
        $this->assertSame('3.250', $movement->cantidad_delta);
        $this->assertSame('5.000', $movement->stock_anterior);
        $this->assertSame('8.250', $movement->stock_posterior);
        $this->assertSame(1, $movement->secuencia_idempotencia);
        $this->assertSame((int) $this->actor->getKey(), $movement->usuario_id);
        $this->assertNull($movement->usuario_autorizador_id);
        $this->assertTrue($movement->ocurrido_en->equalTo($receipt->ocurrido_en));
        $this->assertNull($movement->movimiento_revertido_id);
        $this->assertOnlyReceiptCause($movement, (int) $line->getKey());

        $cost = DB::table('historial_costos_articulo')
            ->where('detalle_recepcion_mercaderia_id', $line->getKey())
            ->first();
        $this->assertNotNull($cost);
        $this->assertSame((int) $this->article->getKey(), $cost->articulo_id);
        $this->assertSame($this->supplierId, $cost->proveedor_id);
        $this->assertSame('900.00', $cost->costo_anterior);
        $this->assertSame('1200.50', $cost->costo_nuevo);
        $this->assertSame((int) $this->actor->getKey(), $cost->usuario_id);
        $this->assertSame((int) $line->getKey(), $cost->detalle_recepcion_mercaderia_id);
        $this->assertTrue(CarbonImmutable::parse($cost->vigente_desde)->equalTo($receipt->ocurrido_en));
        $this->assertSame('900.00', DB::table('historial_costos_articulo')->where('id', $previousCostId)->value('costo_nuevo'));

        $daily = DB::table('stock_diario')
            ->where('articulo_id', $this->article->getKey())
            ->where('fecha_comercial', '2026-10-08')
            ->first();
        $this->assertNotNull($daily);
        $this->assertSame('0.000', $daily->stock_apertura);
        $this->assertSame('8.250', $daily->total_entradas);
        $this->assertSame('0.000', $daily->total_salidas);
        $this->assertSame('8.250', $daily->stock_cierre);
        $this->assertSame((int) $movement->getKey(), $daily->ultimo_movimiento_id);
        $this->assertSame($priceState, $this->tableState(['articulos', 'historial_precios_venta'], ['stock_actual', 'updated_at']));
        $this->assertSame($cashState, $this->tableState(['sesiones_caja', 'movimientos_caja']));
    }

    public function test_confirm_orders_multiple_articles_and_refreshes_identity_snapshots(): void
    {
        [$receipt, $lines] = $this->completeSupplierReceipt([
            [(int) $this->otherArticle->getKey(), '2.375', '700.00', null],
            [(int) $this->article->getKey(), '1.500', '500.00', null],
        ]);
        DB::table('articulos')->where('id', $this->article->getKey())->update([
            'sku' => 'recepcion_fisica_pan_nuevo',
            'nombre' => 'Pan renombrado antes de confirmar',
        ]);

        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $movements = MovimientoInventario::query()
            ->where('tipo_movimiento', InventoryMovementType::Receipt)
            ->whereIn('detalle_recepcion_mercaderia_id', array_map(
                static fn (DetalleRecepcionMercaderia $line): int => (int) $line->getKey(),
                $lines,
            ))
            ->orderBy('secuencia_idempotencia')
            ->get();

        $this->assertSame('6.500', $this->stockOf($this->article));
        $this->assertSame('3.500', $this->stockOf($this->otherArticle));
        $this->assertSame([1, 2], $movements->pluck('secuencia_idempotencia')->all());
        $this->assertSame(
            [(int) $this->article->getKey(), (int) $this->otherArticle->getKey()],
            $movements->pluck('articulo_id')->all(),
        );
        $this->assertSame(['1.500', '2.375'], $movements->pluck('cantidad_delta')->all());
        $refreshedLine = DetalleRecepcionMercaderia::query()
            ->where('recepcion_mercaderia_id', $receipt->getKey())
            ->where('articulo_id', $this->article->getKey())
            ->firstOrFail();
        $this->assertSame('recepcion_fisica_pan_nuevo', $refreshedLine->articulo_sku_snapshot);
        $this->assertSame('Pan renombrado antes de confirmar', $refreshedLine->articulo_nombre_snapshot);
        $this->assertSame($refreshedLine->articulo_sku_snapshot, $movements[0]->articulo_sku_snapshot);
        $this->assertSame($refreshedLine->articulo_nombre_snapshot, $movements[0]->articulo_nombre_snapshot);
        foreach ($movements as $movement) {
            $this->assertOnlyReceiptCause($movement, $movement->detalle_recepcion_mercaderia_id);
        }
        $this->assertSame(2, DB::table('historial_costos_articulo')
            ->whereIn('detalle_recepcion_mercaderia_id', $movements->pluck('detalle_recepcion_mercaderia_id'))
            ->count());
    }

    #[DataProvider('invalidConfirmationLines')]
    public function test_confirm_rejects_incomplete_or_inactive_aggregate_atomically(string $case): void
    {
        $receipt = $this->supplierDraft();

        match ($case) {
            'no lines' => null,
            'quantity null' => $this->service()->addLine(
                $this->actor, (int) $receipt->getKey(), (int) $this->article->getKey(), null, '100.00',
            ),
            'cost null' => $this->service()->addLine(
                $this->actor, (int) $receipt->getKey(), (int) $this->article->getKey(), '1.000', null,
            ),
            'inactive' => (function () use ($receipt): void {
                $this->service()->addLine(
                    $this->actor, (int) $receipt->getKey(), (int) $this->article->getKey(), '1.000', '100.00',
                );
                DB::table('articulos')->where('id', $this->article->getKey())->update(['activo' => false]);
            })(),
        };

        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid()),
        );
    }

    public static function invalidConfirmationLines(): array
    {
        return [
            'no lines' => ['no lines'],
            'quantity null' => ['quantity null'],
            'cost null' => ['cost null'],
            'inactive at confirmation' => ['inactive'],
        ];
    }

    #[DataProvider('requiredConfirmationPermissions')]
    public function test_confirm_requires_both_exact_permissions(PermissionCode $permission): void
    {
        [$receipt] = $this->completeSupplierReceipt();
        $this->revoke($permission);

        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid()),
        );
    }

    public static function requiredConfirmationPermissions(): array
    {
        return [
            'confirm receipt' => [PermissionCode::InventoryReceiptsConfirm],
            'register cost' => [PermissionCode::InventoryCostsRegister],
        ];
    }

    public function test_confirm_reauthorizes_other_origin_and_retroactivity_then_records_elevated_actor(): void
    {
        $other = $this->service()->createDraft(
            $this->actor,
            $this->uuid(),
            ReceiptOriginType::AuthorizedOther,
            originDetail: 'Aporte documentado',
        );
        $otherLine = $this->service()->addLine(
            $this->actor, (int) $other->getKey(), (int) $this->article->getKey(), '1.000', '0.00', 'Aporte gratuito',
        );
        $this->revoke(PermissionCode::InventoryReceiptsOtherOrigin);
        $otherCommand = $this->uuid();
        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->service()->confirm($this->actor, (int) $other->getKey(), $otherCommand),
        );
        $this->grant(PermissionCode::InventoryReceiptsOtherOrigin);
        $this->service()->confirm($this->actor, (int) $other->getKey(), $otherCommand);
        $otherMovement = $this->receiptMovement((int) $otherLine->getKey(), InventoryMovementType::Receipt);
        $this->assertSame((int) $this->actor->getKey(), $otherMovement->usuario_autorizador_id);
        $this->assertSame('0.00', DB::table('historial_costos_articulo')
            ->where('detalle_recepcion_mercaderia_id', $otherLine->getKey())->value('costo_nuevo'));
        $this->assertSame('Aporte gratuito', DB::table('historial_costos_articulo')
            ->where('detalle_recepcion_mercaderia_id', $otherLine->getKey())->value('motivo'));

        $occurredAt = CarbonImmutable::now('UTC')->subDay();
        $retro = $this->service()->createDraft(
            $this->actor,
            $this->uuid(),
            ReceiptOriginType::Supplier,
            supplierId: $this->supplierId,
            occurredAt: $occurredAt,
            occurrenceReason: 'Entrega informada al dia siguiente',
        );
        $retroLine = $this->service()->addLine(
            $this->actor, (int) $retro->getKey(), (int) $this->otherArticle->getKey(), '2.000', '800.00',
        );
        $this->revoke(PermissionCode::InventoryReceiptsRetroactive);
        $retroCommand = $this->uuid();
        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->service()->confirm($this->actor, (int) $retro->getKey(), $retroCommand),
        );
        $this->grant(PermissionCode::InventoryReceiptsRetroactive);
        $this->service()->confirm($this->actor, (int) $retro->getKey(), $retroCommand);
        $retroMovement = $this->receiptMovement((int) $retroLine->getKey(), InventoryMovementType::Receipt);
        $this->assertTrue($retroMovement->ocurrido_en->equalTo($occurredAt));
        $this->assertSame('Entrega informada al dia siguiente', $retroMovement->motivo);
        $this->assertSame((int) $this->actor->getKey(), $retroMovement->usuario_autorizador_id);
        $historicalDaily = DB::table('stock_diario')
            ->where('articulo_id', $this->otherArticle->getKey())
            ->where('fecha_comercial', '2026-10-07')
            ->first();
        $currentDaily = DB::table('stock_diario')
            ->where('articulo_id', $this->otherArticle->getKey())
            ->where('fecha_comercial', '2026-10-08')
            ->first();
        $this->assertSame('2.000', $historicalDaily->total_entradas);
        $this->assertSame('2.000', $historicalDaily->stock_cierre);
        $this->assertSame('2.000', $currentDaily->stock_apertura);
        $this->assertSame('3.125', $currentDaily->stock_cierre);
    }

    public function test_confirm_rejects_future_occurrence_and_invalid_uuid_without_effects(): void
    {
        $future = CarbonImmutable::now('UTC')->addHour();
        $receiptId = $this->createReceipt((int) $this->actor->getKey(), $this->uuid(), [
            'tipo_origen' => ReceiptOriginType::Supplier->value,
            'proveedor_id' => $this->supplierId,
            'origen_detalle' => null,
            'ocurrido_en' => $future,
            'created_at' => $future,
            'updated_at' => $future,
        ]);
        $this->createReceiptDetail($receiptId, (int) $this->article->getKey(), [
            'articulo_sku_snapshot' => $this->article->sku,
            'articulo_nombre_snapshot' => $this->article->nombre,
            'cantidad' => '1.000',
            'costo_unitario' => '100.00',
        ]);
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->service()->confirm($this->actor, $receiptId, $this->uuid()),
        );

        [$normal] = $this->completeSupplierReceipt();
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->service()->confirm($this->actor, (int) $normal->getKey(), 'invalid-uuid'),
        );
    }

    public function test_confirm_retry_is_serially_idempotent_and_conflicts_are_rejected(): void
    {
        [$receipt] = $this->completeSupplierReceipt();
        $command = $this->uuid();
        $first = $this->service()->confirm($this->actor, (int) $receipt->getKey(), strtoupper($command));
        $afterFirst = $this->operationalState();
        $retry = $this->service()->confirm($this->actor, (int) $receipt->getKey(), $command);

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($afterFirst, $this->operationalState());
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->confirm($this->otherActor, (int) $receipt->getKey(), $command),
        );
        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid()),
        );

        [$otherReceipt] = $this->completeSupplierReceipt([
            [(int) $this->otherArticle->getKey(), '1.000', '200.00', null],
        ]);
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->confirm($this->actor, (int) $otherReceipt->getKey(), $command),
        );
    }

    public function test_cost_history_is_append_only_and_zero_cost_is_preserved_exactly(): void
    {
        $previousId = $this->createArticleCostHistory(
            (int) $this->article->getKey(),
            (int) $this->actor->getKey(),
            [
                'costo_nuevo' => '1100.00',
                'motivo' => 'Costo previo',
                'vigente_desde' => CarbonImmutable::now('UTC')->subDay(),
            ],
        );
        $previous = (array) DB::table('historial_costos_articulo')->where('id', $previousId)->first();
        [$receipt, $lines] = $this->completeSupplierReceipt([
            [(int) $this->article->getKey(), '1.000', '0.00', 'Bonificacion total'],
        ]);
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $current = DB::table('historial_costos_articulo')
            ->where('detalle_recepcion_mercaderia_id', $lines[0]->getKey())
            ->first();

        $this->assertSame($previous, (array) DB::table('historial_costos_articulo')->where('id', $previousId)->first());
        $this->assertSame('1100.00', $current->costo_anterior);
        $this->assertSame('0.00', $current->costo_nuevo);
        $this->assertSame('Bonificacion total', $current->motivo);
        $this->assertDatabaseCount('historial_costos_articulo', 2);
    }

    public function test_annul_reverses_the_full_receipt_and_preserves_cost_and_cash_history(): void
    {
        $cashSessionId = $this->createCashSession($this->createCashRegister(), (int) $this->actor->getKey());
        $this->createCashMovement($cashSessionId, (int) $this->actor->getKey());
        [$receipt, $lines] = $this->completeSupplierReceipt();
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $original = $this->receiptMovement((int) $lines[0]->getKey(), InventoryMovementType::Receipt);
        $costState = $this->tableState(['historial_costos_articulo']);
        $cashState = $this->tableState(['sesiones_caja', 'movimientos_caja']);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());

        $annulled = $this->service()->annul(
            $this->actor, (int) $receipt->getKey(), $this->uuid(), '  Error en la entrega  ',
        );
        $reversal = $this->receiptMovement((int) $lines[0]->getKey(), InventoryMovementType::ReceiptReversal);

        $this->assertSame(ReceiptStatus::Annulled, $annulled->estado);
        $this->assertSame((int) $this->actor->getKey(), $annulled->usuario_anulador_id);
        $this->assertTrue($annulled->anulada_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertSame('Error en la entrega', $annulled->motivo_anulacion);
        $this->assertSame('5.000', $this->stockOf($this->article));
        $this->assertSame('-3.000', $reversal->cantidad_delta);
        $this->assertSame('8.000', $reversal->stock_anterior);
        $this->assertSame('5.000', $reversal->stock_posterior);
        $this->assertSame((int) $original->getKey(), $reversal->movimiento_revertido_id);
        $this->assertSame('Error en la entrega', $reversal->motivo);
        $this->assertTrue($reversal->ocurrido_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertOnlyReceiptCause($reversal, (int) $lines[0]->getKey());
        $this->assertSame($costState, $this->tableState(['historial_costos_articulo']));
        $this->assertSame($cashState, $this->tableState(['sesiones_caja', 'movimientos_caja']));
        $this->assertSame(1, MovimientoInventario::query()
            ->where('movimiento_revertido_id', $original->getKey())->count());
        $daily = DB::table('stock_diario')
            ->where('articulo_id', $this->article->getKey())
            ->where('fecha_comercial', '2026-10-08')
            ->first();
        $this->assertSame('8.000', $daily->total_entradas);
        $this->assertSame('3.000', $daily->total_salidas);
        $this->assertSame('5.000', $daily->stock_cierre);
        $this->assertSame((int) $reversal->getKey(), $daily->ultimo_movimiento_id);
    }

    public function test_annul_uses_current_locked_stock_after_a_later_legal_movement(): void
    {
        [$receipt, $lines] = $this->completeSupplierReceipt();
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $this->applyStockMutation($this->article, InventoryMovementType::ManualInput, '2.000');
        $this->assertSame('10.000', $this->stockOf($this->article));
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());

        $this->service()->annul($this->actor, (int) $receipt->getKey(), $this->uuid(), 'Recepcion duplicada');
        $reversal = $this->receiptMovement((int) $lines[0]->getKey(), InventoryMovementType::ReceiptReversal);

        $this->assertSame('7.000', $this->stockOf($this->article));
        $this->assertSame('10.000', $reversal->stock_anterior);
        $this->assertSame('-3.000', $reversal->cantidad_delta);
        $this->assertSame('7.000', $reversal->stock_posterior);
    }

    public function test_annul_of_retroactive_receipt_uses_now_and_the_current_commercial_day(): void
    {
        $occurredAt = CarbonImmutable::now('UTC')->subDay();
        $retroArticle = Articulo::query()->findOrFail($this->createArticle(
            (int) $this->article->categoria_articulo_id,
            (int) $this->article->unidad_id,
            [
                'sku' => 'recepcion_retroactiva_anulable',
                'nombre' => 'Articulo retroactivo anulable',
                'stock_actual' => '0.000',
            ],
        ));
        $receipt = $this->service()->createDraft(
            $this->actor,
            $this->uuid(),
            ReceiptOriginType::Supplier,
            supplierId: $this->supplierId,
            occurredAt: $occurredAt,
            occurrenceReason: 'Documento recibido con atraso',
        );
        $line = $this->service()->addLine(
            $this->actor, (int) $receipt->getKey(), (int) $retroArticle->getKey(), '3.000', '1000.00',
        );
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $original = $this->receiptMovement((int) $line->getKey(), InventoryMovementType::Receipt);
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());

        $this->service()->annul(
            $this->actor, (int) $receipt->getKey(), $this->uuid(), 'Recepcion retroactiva anulada',
        );
        $reversal = $this->receiptMovement((int) $line->getKey(), InventoryMovementType::ReceiptReversal);
        $historicalDaily = DB::table('stock_diario')
            ->where('articulo_id', $retroArticle->getKey())
            ->where('fecha_comercial', '2026-10-07')
            ->first();
        $currentDaily = DB::table('stock_diario')
            ->where('articulo_id', $retroArticle->getKey())
            ->where('fecha_comercial', '2026-10-08')
            ->first();

        $this->assertTrue($original->ocurrido_en->equalTo($occurredAt));
        $this->assertTrue($reversal->ocurrido_en->equalTo(CarbonImmutable::now('UTC')));
        $this->assertSame('3.000', $historicalDaily->total_entradas);
        $this->assertSame('0.000', $historicalDaily->total_salidas);
        $this->assertSame('3.000', $currentDaily->stock_apertura);
        $this->assertSame('0.000', $currentDaily->total_entradas);
        $this->assertSame('3.000', $currentDaily->total_salidas);
        $this->assertSame('0.000', $currentDaily->stock_cierre);
        $this->assertSame('0.000', $this->stockOf($retroArticle));
    }

    public function test_annul_rejects_insufficient_single_article_without_partial_effects(): void
    {
        [$receipt] = $this->completeSupplierReceipt();
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $this->applyStockMutation($this->article, InventoryMovementType::ManualOutput, '-6.000');
        $this->assertSame('2.000', $this->stockOf($this->article));

        $this->assertRejectedWithoutChanges(
            InsufficientStockException::class,
            fn () => $this->service()->annul(
                $this->actor, (int) $receipt->getKey(), $this->uuid(), 'Stock ya utilizado',
            ),
        );
        $this->assertSame(ReceiptStatus::Confirmed, $receipt->refresh()->estado);
    }

    public function test_annul_simulates_every_article_before_writing_any_reversal(): void
    {
        [$receipt] = $this->completeSupplierReceipt([
            [(int) $this->article->getKey(), '3.000', '100.00', null],
            [(int) $this->otherArticle->getKey(), '4.000', '200.00', null],
        ]);
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $this->applyStockMutation($this->otherArticle, InventoryMovementType::ManualOutput, '-3.000');
        $this->assertSame('8.000', $this->stockOf($this->article));
        $this->assertSame('2.125', $this->stockOf($this->otherArticle));

        $this->assertRejectedWithoutChanges(
            InsufficientStockException::class,
            fn () => $this->service()->annul(
                $this->actor, (int) $receipt->getKey(), $this->uuid(), 'Una linea ya fue consumida',
            ),
        );
        $this->assertSame(0, MovimientoInventario::query()
            ->where('tipo_movimiento', InventoryMovementType::ReceiptReversal)->count());
        $this->assertSame(ReceiptStatus::Confirmed, $receipt->refresh()->estado);
    }

    public function test_annul_requires_permission_reason_confirmed_state_and_valid_uuid(): void
    {
        [$draft] = $this->completeSupplierReceipt();
        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->service()->annul($this->actor, (int) $draft->getKey(), $this->uuid(), 'No corresponde'),
        );
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->service()->confirm($this->actor, (int) $draft->getKey(), 'invalid'),
        );
        $this->service()->confirm($this->actor, (int) $draft->getKey(), $this->uuid());
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->service()->annul($this->actor, (int) $draft->getKey(), 'invalid', 'Motivo'),
        );
        $this->assertRejectedWithoutChanges(
            InvalidArgumentException::class,
            fn () => $this->service()->annul($this->actor, (int) $draft->getKey(), $this->uuid(), '   '),
        );
        $this->revoke(PermissionCode::InventoryReceiptsAnnul);
        $this->assertRejectedWithoutChanges(
            AuthorizationException::class,
            fn () => $this->service()->annul($this->actor, (int) $draft->getKey(), $this->uuid(), 'Motivo valido'),
        );
    }

    public function test_annul_retry_is_idempotent_and_all_incompatible_reuses_conflict(): void
    {
        [$receipt] = $this->completeSupplierReceipt();
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());
        $command = $this->uuid();
        $first = $this->service()->annul(
            $this->actor, (int) $receipt->getKey(), strtoupper($command), '  Recepcion repetida  ',
        );
        $afterFirst = $this->operationalState();
        $retry = $this->service()->annul(
            $this->actor, (int) $receipt->getKey(), $command, 'Recepcion repetida',
        );

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($afterFirst, $this->operationalState());
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->annul(
                $this->actor, (int) $receipt->getKey(), $command, 'Motivo diferente',
            ),
        );
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->annul(
                $this->otherActor, (int) $receipt->getKey(), $command, 'Recepcion repetida',
            ),
        );
        $this->assertRejectedWithoutChanges(
            InventoryDomainException::class,
            fn () => $this->service()->annul(
                $this->actor, (int) $receipt->getKey(), $this->uuid(), 'Recepcion repetida',
            ),
        );

        [$otherReceipt] = $this->completeSupplierReceipt([
            [(int) $this->otherArticle->getKey(), '1.000', '200.00', null],
        ]);
        $this->service()->confirm($this->actor, (int) $otherReceipt->getKey(), $this->uuid());
        $this->assertRejectedWithoutChanges(
            IdempotencyConflictException::class,
            fn () => $this->service()->annul(
                $this->actor, (int) $otherReceipt->getKey(), $command, 'Recepcion repetida',
            ),
        );
    }

    public function test_confirm_and_annul_follow_the_approved_lock_order(): void
    {
        [$receipt] = $this->completeSupplierReceipt([
            [(int) $this->otherArticle->getKey(), '1.000', '200.00', null],
            [(int) $this->article->getKey(), '1.000', '100.00', null],
        ]);
        $confirmQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$confirmQueries): void {
            $confirmQueries[] = strtolower($query->sql);
        });
        $this->service()->confirm($this->actor, (int) $receipt->getKey(), $this->uuid());

        $confirmLocks = array_values(array_filter(
            $confirmQueries,
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));
        $this->assertLockOrder($confirmLocks, [
            'from "recepciones_mercaderia"',
            'from "detalle_recepciones_mercaderia"',
            'from "articulos"',
        ]);
        $this->assertStringContainsString('order by "id" asc', $confirmLocks[1]);
        $this->assertStringContainsString('order by "id" asc', $confirmLocks[2]);

        $annulQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$annulQueries): void {
            $annulQueries[] = strtolower($query->sql);
        });
        $this->service()->annul($this->actor, (int) $receipt->getKey(), $this->uuid(), 'Anulacion de prueba');
        $annulLocks = array_values(array_filter(
            $annulQueries,
            static fn (string $sql): bool => str_contains($sql, 'for update'),
        ));
        $this->assertLockOrder($annulLocks, [
            'from "recepciones_mercaderia"',
            'from "detalle_recepciones_mercaderia"',
            'from "articulos"',
            'from "movimientos_inventario"',
        ]);
        $this->assertStringContainsString('order by "id" asc', $annulLocks[3]);
    }

    public function test_public_api_and_constructor_remain_closed_to_the_approved_surface(): void
    {
        $methods = (new ReflectionClass(ReceiptService::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods);
        sort($methodNames);
        $expected = ['__construct', 'addLine', 'annul', 'confirm', 'createDraft', 'deleteDraft', 'removeLine', 'updateDraft', 'updateLine'];
        sort($expected);
        $this->assertSame($expected, $methodNames);

        $dependencies = array_map(
            static fn ($parameter): string => $parameter->getType()->getName(),
            (new ReflectionMethod(ReceiptService::class, '__construct'))->getParameters(),
        );
        $allowed = [
            EffectivePermissionResolver::class,
            InventoryLockManager::class,
            InventoryStockService::class,
            StockDailyRebuilder::class,
            InventoryLedgerWriter::class,
        ];
        $this->assertContains(EffectivePermissionResolver::class, $dependencies);
        $this->assertContains(InventoryLockManager::class, $dependencies);
        $this->assertContains(InventoryStockService::class, $dependencies);
        $this->assertContains(StockDailyRebuilder::class, $dependencies);
        $this->assertSame([], array_values(array_diff($dependencies, $allowed)));
        $this->assertSame($dependencies, array_values(array_unique($dependencies)));
        foreach (array_filter(
            $methods,
            static fn (ReflectionMethod $method): bool => in_array($method->getName(), ['confirm', 'annul'], true),
        ) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotContains($parameter->getName(), [
                    'authorizerId', 'confirmadorId', 'anuladorId', 'occurredAt', 'stock', 'costOverride',
                    'lineIds', 'force', 'override',
                ]);
            }
        }
    }

    private function service(): ReceiptService
    {
        return $this->app->make(ReceiptService::class);
    }

    private function supplierDraft(): RecepcionMercaderia
    {
        return $this->service()->createDraft(
            $this->actor,
            $this->uuid(),
            ReceiptOriginType::Supplier,
            supplierId: $this->supplierId,
        );
    }

    /**
     * @param array<int, array{int, string, string, ?string}>|null $specifications
     * @return array{RecepcionMercaderia, array<DetalleRecepcionMercaderia>}
     */
    private function completeSupplierReceipt(?array $specifications = null): array
    {
        $receipt = $this->supplierDraft();
        $specifications ??= [[(int) $this->article->getKey(), '3.000', '1000.00', null]];
        $lines = [];

        foreach ($specifications as [$articleId, $quantity, $cost, $zeroCostReason]) {
            $lines[] = $this->service()->addLine(
                $this->actor,
                (int) $receipt->getKey(),
                $articleId,
                $quantity,
                $cost,
                $zeroCostReason,
            );
        }

        return [$receipt, $lines];
    }

    private function revoke(PermissionCode $permission): void
    {
        DB::table('rol_permiso')
            ->where('rol_id', $this->roleId)
            ->where('permiso_id', $this->permissionIds[$permission->value])
            ->delete();
    }

    private function grant(PermissionCode $permission): void
    {
        $this->attachPermission($this->roleId, $this->permissionIds[$permission->value]);
    }

    private function applyStockMutation(
        Articulo $article,
        InventoryMovementType $type,
        string $delta,
    ): void {
        $commandUuid = $this->uuid();

        DB::transaction(function () use ($article, $type, $delta, $commandUuid): void {
            $lockManager = $this->app->make(InventoryLockManager::class);
            $stockService = $this->app->make(InventoryStockService::class);
            $articleId = (int) $article->getKey();
            $locked = $lockManager->lockArticles([$articleId]);
            $unit = DB::table('unidades_medida')
                ->where('id', $locked->get($articleId)->unidad_id)
                ->first(['codigo', 'simbolo']);
            $simulation = $stockService->simulatePlan($locked, $commandUuid, [
                new InventoryStockMutation(
                    articleId: $articleId,
                    type: $type,
                    delta: $delta,
                    position: 1,
                    occurredAt: CarbonImmutable::now('UTC'),
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
            $stockService->applyPlan($simulation);
        });
    }

    private function receiptMovement(int $detailId, InventoryMovementType $type): MovimientoInventario
    {
        return MovimientoInventario::query()
            ->where('detalle_recepcion_mercaderia_id', $detailId)
            ->where('tipo_movimiento', $type)
            ->firstOrFail();
    }

    private function assertOnlyReceiptCause(MovimientoInventario $movement, int $detailId): void
    {
        $this->assertSame($detailId, $movement->detalle_recepcion_mercaderia_id);

        foreach ([
            'detalle_venta_id', 'detalle_produccion_id', 'consumo_produccion_id',
            'merma_id', 'detalle_conteo_inventario_id',
        ] as $column) {
            $this->assertNull($movement->getAttribute($column), $column);
        }
    }

    private function stockOf(Articulo $article): string
    {
        return (string) DB::table('articulos')->where('id', $article->getKey())->value('stock_actual');
    }

    private function operationalState(): array
    {
        return $this->tableState([
            'articulos',
            'recepciones_mercaderia',
            'detalle_recepciones_mercaderia',
            'movimientos_inventario',
            'stock_diario',
            'historial_costos_articulo',
            'historial_precios_venta',
            'sesiones_caja',
            'movimientos_caja',
        ]);
    }

    /** @param array<string> $ignoredColumns */
    private function tableState(array $tables, array $ignoredColumns = []): array
    {
        return array_map(static function (string $table) use ($ignoredColumns): array {
            return DB::table($table)->orderBy('id')->get()->map(
                static function (object $row) use ($ignoredColumns): array {
                    $values = (array) $row;

                    foreach ($ignoredColumns as $column) {
                        unset($values[$column]);
                    }

                    return $values;
                },
            )->all();
        }, $tables);
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

    /** @param array<string> $queries @param array<string> $needles */
    private function assertLockOrder(array $queries, array $needles): void
    {
        $previous = -1;

        foreach ($needles as $needle) {
            $position = null;

            foreach ($queries as $index => $query) {
                if ($index > $previous && str_contains($query, $needle)) {
                    $position = $index;
                    break;
                }
            }

            $this->assertNotNull($position, "No se encontro lock para {$needle} despues de la posicion {$previous}.");
            $previous = $position;
        }
    }

    private function uuid(): string
    {
        return sprintf('b1200000-0000-4000-8000-%012d', $this->uuidSequence++);
    }
}
