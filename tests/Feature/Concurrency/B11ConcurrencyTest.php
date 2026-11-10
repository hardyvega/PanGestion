<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\FreeOrderData;
use App\Data\Orders\OrderContact;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Data\Production\AnnulProductionCommand;
use App\Data\Production\ConfirmProductionCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Services\Production\ProductionService;
use App\Services\Sales\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ProcessRace;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\Orders\OrderDeliveryFixtures;
use Tests\Support\Sales\SaleServiceFixtures;
use Tests\TestCase;

final class B11ConcurrencyTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use OrderDeliveryFixtures;
    use SaleServiceFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deliveryFixtureSequence = random_int(1000, 9999);
    }

    protected function tearDown(): void
    {
        try {
            $tables = DB::select(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'"
            );

            if ($tables !== []) {
                $quotedTables = array_map(
                    static fn (object $table): string => '"'.str_replace('"', '""', $table->tablename).'"',
                    $tables,
                );

                DB::statement(
                    'TRUNCATE TABLE '.implode(', ', $quotedTables).' RESTART IDENTITY CASCADE'
                );
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_same_sale_uuid_has_one_effect_and_one_cash_posting_under_real_race(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::SalesCreate], 'venta_reintento');
        $fixture = $this->saleFixture($actor, cash: true, stock: '10.000');
        $command = $this->salePayload($actor, $fixture, $this->concurrencyUuid(), '7.000', '7.00', $this->concurrencyUuid());

        $results = ProcessRace::run('sale', [$command, $command]);

        $this->assertCount(2, $results);
        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $saleId = DB::table('ventas')->where('clave_idempotencia', $command['command_uuid'])->value('id');
        $paymentId = DB::table('pagos_venta')->where('clave_idempotencia', $command['payments'][0]['command_uuid'])->value('id');
        $detailId = DB::table('detalle_ventas')->where('venta_id', $saleId)->value('id');

        $this->assertSame(1, DB::table('ventas')->where('clave_idempotencia', $command['command_uuid'])->count());
        $this->assertSame(1, DB::table('detalle_ventas')->where('venta_id', $saleId)->count());
        $this->assertSame(1, DB::table('pagos_venta')->where('clave_idempotencia', $command['payments'][0]['command_uuid'])->count());
        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_venta_id', $paymentId)->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->where('detalle_venta_id', $detailId)->count());
        $this->assertSame('3.000', (string) DB::table('articulos')->where('id', $fixture['articleId'])->value('stock_actual'));
    }

    public function test_different_sales_competing_for_stock_have_one_commit_and_one_total_rollback(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::SalesCreate], 'venta_stock');
        $fixture = $this->saleFixture($actor, cash: false, stock: '10.000');
        $first = $this->salePayload($actor, $fixture, $this->concurrencyUuid(), '7.000', '7.00', $this->concurrencyUuid());
        $second = $this->salePayload($actor, $fixture, $this->concurrencyUuid(), '7.000', '7.00', $this->concurrencyUuid());

        $results = ProcessRace::run('sale', [$first, $second]);

        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)));
        $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
        $this->assertCount(1, $failures);
        $this->assertSame(InsufficientStockException::class, $failures[0]['exception']);
        $this->assertSame('3.000', (string) DB::table('articulos')->where('id', $fixture['articleId'])->value('stock_actual'));
        $this->assertSame(1, DB::table('ventas')->whereIn('clave_idempotencia', [$first['command_uuid'], $second['command_uuid']])->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->whereIn('clave_idempotencia', [$first['command_uuid'], $second['command_uuid']])->count());
    }

    public function test_inverse_multiline_sales_complete_without_deadlock_or_lost_stock(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::SalesCreate], 'venta_orden');
        $first = $this->saleFixture($actor, cash: false, stock: '10.000');
        $second = $this->saleFixture($actor, cash: false, stock: '10.000');
        $first['shared_articles'] = [$first['articleId'], $second['articleId']];
        $second['shared_articles'] = [$first['articleId'], $second['articleId']];
        $firstPayload = $this->multiArticleSalePayload($actor, $first, $this->concurrencyUuid(), [$first['articleId'], $second['articleId']]);
        $secondPayload = $this->multiArticleSalePayload($actor, $second, $this->concurrencyUuid(), [$second['articleId'], $first['articleId']]);

        $results = ProcessRace::run('sale', [$firstPayload, $secondPayload]);

        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $this->assertSame('8.000', (string) DB::table('articulos')->where('id', $first['articleId'])->value('stock_actual'));
        $this->assertSame('8.000', (string) DB::table('articulos')->where('id', $second['articleId'])->value('stock_actual'));
        $sales = DB::table('ventas')->whereIn('clave_idempotencia', [$firstPayload['command_uuid'], $secondPayload['command_uuid']])->get();
        $this->assertCount(2, $sales);
        $this->assertCount(2, $sales->pluck('numero_diario')->unique());
    }

    public function test_same_delivery_uuid_creates_one_sale_stock_and_cash_effect(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::OrdersDeliver, PermissionCode::OrdersCollect], 'entrega');
        $fixture = $this->createReadyOrderFixture($actor, prepaid: true, cashPayment: true, total: '10.00', priorPayment: '4.00');
        $payload = [
            'actor_id' => (int) $actor->getKey(),
            'order_id' => $fixture['orderId'],
            'command_uuid' => $this->concurrencyUuid(),
            'final_payment' => [
                'payment_method_id' => $fixture['paymentMethodId'],
                'type' => PaymentType::Charge->value,
                'amount' => '6.00',
                'cash_session_id' => $fixture['cashSessionId'],
                'command_uuid' => $this->concurrencyUuid(),
                'received_amount' => '6.00',
                'change' => '0.00',
            ],
        ];

        $results = ProcessRace::run('delivery', [$payload, $payload]);

        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $saleId = DB::table('ventas')->where('pedido_id', $fixture['orderId'])->value('id');
        $paymentId = DB::table('pagos_pedido')->where('clave_idempotencia', $payload['final_payment']['command_uuid'])->value('id');
        $this->assertSame('entregado', DB::table('pedidos')->where('id', $fixture['orderId'])->value('estado'));
        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $fixture['orderId'])->count());
        $this->assertSame(1, DB::table('pagos_pedido')->where('clave_idempotencia', $payload['final_payment']['command_uuid'])->count());
        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_pedido_id', $paymentId)->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->where('clave_idempotencia', $payload['command_uuid'])->count());
        $this->assertSame('9.000', (string) DB::table('articulos')->where('id', $fixture['articleId'])->value('stock_actual'));
    }

    public function test_different_delivery_uuid_cannot_deliver_the_same_order_twice(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::OrdersDeliver], 'entrega_distinta');
        $fixture = $this->createReadyOrderFixture($actor, prepaid: true, cashPayment: false, total: '10.00', priorPayment: '10.00');
        $first = [
            'actor_id' => (int) $actor->getKey(),
            'order_id' => $fixture['orderId'],
            'command_uuid' => $this->concurrencyUuid(),
        ];
        $second = $first;
        $second['command_uuid'] = $this->concurrencyUuid();

        $results = ProcessRace::run('delivery', [$first, $second]);

        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)));
        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $fixture['orderId'])->count());
        $this->assertSame(1, DB::table('transiciones_pedido')->where('pedido_id', $fixture['orderId'])->where('estado_nuevo', 'entregado')->count());
    }

    public function test_concurrent_free_order_delivery_records_one_sale_and_one_final_payment(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::OrdersCreate, PermissionCode::OrdersDeliver, PermissionCode::OrdersCollect], 'encargo');
        $register = $this->createCashRegister(['codigo' => 'principal', 'nombre' => 'Caja principal']);
        $session = $this->createOperationalCashSession($register, (int) $actor->id);
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $order = app(OrderService::class)->create($actor, new CreateOrderCommand(
            $this->concurrencyUuid(), null, [], scheduledDeliveryAt: CarbonImmutable::now()->addDay(),
            contact: new OrderContact('Cliente de prueba', '9 1234-5678'),
            freeOrder: new FreeOrderData('Encargo de prueba', null, '10.000'),
        ));
        DB::table('pedidos')->where('id', $order->id)->update(['estado' => 'listo']);
        $payload = [
            'actor_id' => (int) $actor->id,
            'order_id' => (int) $order->id,
            'command_uuid' => $this->concurrencyUuid(),
            'final_payment' => [
                'payment_method_id' => $cash,
                'type' => PaymentType::Charge->value,
                'amount' => '10000.00',
                'cash_session_id' => $session,
                'command_uuid' => $this->concurrencyUuid(),
                'received_amount' => '10000.00',
                'change' => '0.00',
            ],
        ];
        $results = ProcessRace::run('delivery', [$payload, $payload]);

        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $this->assertDatabaseHas('ventas', ['pedido_id' => $order->id, 'total' => '10000.00', 'sesion_caja_id' => $session]);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('pagos_pedido', 1);
        $this->assertDatabaseCount('movimientos_caja', 1);
        $this->assertDatabaseCount('detalle_ventas', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertSame(1, DB::table('transiciones_pedido')->where('pedido_id', $order->id)->where('estado_nuevo', 'entregado')->count());
    }

    public function test_same_sale_annulment_uuid_reverses_sale_and_cash_once_under_real_race(): void
    {
        $actor = $this->concurrencyActor([PermissionCode::SalesCreate, PermissionCode::SalesAnnul], 'anulacion_venta');
        $fixture = $this->saleFixture($actor, cash: true, stock: '10.000');
        $salePayload = $this->salePayload($actor, $fixture, $this->concurrencyUuid(), '7.000', '7.00', $this->concurrencyUuid());
        $sale = app(SaleService::class)->complete($actor, new CompleteSaleCommand(
            $salePayload['command_uuid'],
            $fixture['cashSessionId'],
            [new SaleLineData($fixture['articleId'], '7.000', '1.00')],
            [new SalePaymentData(
                $fixture['paymentMethodId'],
                PaymentType::Charge,
                '7.00',
                $fixture['cashSessionId'],
                $salePayload['payments'][0]['command_uuid'],
                '7.00',
                '0.00',
                null,
            )],
        ));
        $annulment = [
            'actor_id' => (int) $actor->getKey(),
            'sale_id' => (int) $sale->getKey(),
            'command_uuid' => $this->concurrencyUuid(),
            'reason' => 'Anulacion concurrente',
        ];

        $results = ProcessRace::run('sale_annul', [$annulment, $annulment]);

        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $refundId = DB::table('pagos_venta')->where('venta_id', $sale->getKey())->where('tipo', 'devolucion')->value('id');
        $this->assertSame('anulada', DB::table('ventas')->where('id', $sale->getKey())->value('estado'));
        $this->assertSame(1, DB::table('pagos_venta')->where('venta_id', $sale->getKey())->where('tipo', 'devolucion')->count());
        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_venta_id', $refundId)->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->where('clave_idempotencia', $annulment['command_uuid'])->count());
        $this->assertSame('10.000', (string) DB::table('articulos')->where('id', $fixture['articleId'])->value('stock_actual'));
    }

    public function test_same_production_uuid_confirms_once_under_real_race(): void
    {
        $fixture = $this->productionFixture('same', '10.000');
        $payload = [
            'actor_id' => $fixture['actor_id'],
            'production_id' => $fixture['production_id'],
            'command_uuid' => $this->concurrencyUuid(),
        ];

        $results = ProcessRace::run('production_confirm', [$payload, $payload]);

        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $this->assertSame('confirmada', DB::table('producciones')->where('id', $fixture['production_id'])->value('estado'));
        $this->assertSame(2, DB::table('movimientos_inventario')->where('clave_idempotencia', $payload['command_uuid'])->count());
        $this->assertSame('8.000', (string) DB::table('articulos')->where('id', $fixture['component_id'])->value('stock_actual'));
        $this->assertSame('2.000', (string) DB::table('articulos')->where('id', $fixture['product_id'])->value('stock_actual'));
    }

    public function test_same_production_uuid_on_distinct_productions_resolves_as_conflict_after_23505(): void
    {
        $first = $this->productionFixture('first', '20.000');
        $second = $this->productionFixture('second', '20.000', $first['component_id']);
        $uuid = $this->concurrencyUuid();

        $results = ProcessRace::run('production_confirm', [
            ['actor_id' => $first['actor_id'], 'production_id' => $first['production_id'], 'command_uuid' => $uuid],
            ['actor_id' => $second['actor_id'], 'production_id' => $second['production_id'], 'command_uuid' => $uuid],
        ]);

        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)));
        $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
        $this->assertCount(1, $failures);
        $this->assertSame(IdempotencyConflictException::class, $failures[0]['exception']);
        $this->assertSame(2, DB::table('movimientos_inventario')->where('clave_idempotencia', $uuid)->count());
        $this->assertSame(1, DB::table('producciones')->where('estado', 'confirmada')->whereIn('id', [$first['production_id'], $second['production_id']])->count());
    }

    public function test_distinct_productions_competing_for_one_component_roll_back_the_loser(): void
    {
        $first = $this->productionFixture('stock_first', '2.000');
        $second = $this->productionFixture('stock_second', '2.000', $first['component_id']);
        $payloads = [
            ['actor_id' => $first['actor_id'], 'production_id' => $first['production_id'], 'command_uuid' => $this->concurrencyUuid()],
            ['actor_id' => $second['actor_id'], 'production_id' => $second['production_id'], 'command_uuid' => $this->concurrencyUuid()],
        ];

        $results = ProcessRace::run('production_confirm', $payloads);

        $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['ok'] === true)));
        $failures = array_values(array_filter($results, static fn (array $result): bool => $result['ok'] === false));
        $this->assertSame(InsufficientStockException::class, $failures[0]['exception']);
        $this->assertSame('0.000', (string) DB::table('articulos')->where('id', $first['component_id'])->value('stock_actual'));
        $this->assertSame(1, DB::table('producciones')->where('estado', 'confirmada')->whereIn('id', [$first['production_id'], $second['production_id']])->count());
    }

    public function test_same_production_annulment_uuid_reverts_once_under_real_race(): void
    {
        $fixture = $this->productionFixture('annul_race', '10.000');
        $actor = User::query()->findOrFail($fixture['actor_id']);
        app(ProductionService::class)->confirm($actor, new ConfirmProductionCommand($fixture['production_id'], $this->concurrencyUuid()));
        $annulment = [
            'actor_id' => $fixture['actor_id'],
            'production_id' => $fixture['production_id'],
            'command_uuid' => $this->concurrencyUuid(),
            'reason' => 'Anulacion de produccion concurrente',
        ];

        $results = ProcessRace::run('production_annul', [$annulment, $annulment]);

        $this->assertCount(2, array_filter($results, static fn (array $result): bool => $result['ok'] === true));
        $this->assertSame('anulada', DB::table('producciones')->where('id', $fixture['production_id'])->value('estado'));
        $this->assertSame(2, DB::table('movimientos_inventario')
            ->where('clave_idempotencia', $annulment['command_uuid'])
            ->where('movimiento_revertido_id', '>', 0)
            ->count());
        $this->assertSame('10.000', (string) DB::table('articulos')->where('id', $fixture['component_id'])->value('stock_actual'));
        $this->assertSame('0.000', (string) DB::table('articulos')->where('id', $fixture['product_id'])->value('stock_actual'));
    }

    /** @return array<string, mixed> */
    private function saleFixture(User $actor, bool $cash, string $stock): array
    {
        $token = 'ab_'.bin2hex(random_bytes(5));
        $categoryId = $this->createArticleCategory(['codigo' => 'ab_'.$token.'_cat', 'nombre' => 'AB '.$token]);
        $unitId = $this->createUnitOfMeasure(['codigo' => 'ab'.substr($token, 3, 8), 'nombre' => 'Unidad AB '.$token, 'simbolo' => 'u'.substr($token, 3, 6), 'magnitud' => 'unidad']);
        $articleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'ab_'.$token,
            'nombre' => 'Articulo AB '.$token,
            'tipo_articulo' => 'elaborado',
            'precio_venta_actual' => '1.00',
            'stock_actual' => $stock,
            'activo' => true,
        ]);
        $cashSessionId = $this->createOperationalCashSession(
            $this->createCashRegister(['codigo' => 'ab_'.$token.'_caja', 'nombre' => 'Caja AB '.$token]),
            (int) $actor->getKey(),
        );
        $paymentMethodId = $this->createPaymentMethod([
            'codigo' => 'ab_'.$token.'_pago',
            'nombre' => 'Pago AB '.$token,
            'afecta_efectivo' => $cash,
        ]);

        return compact('articleId', 'cashSessionId', 'paymentMethodId');
    }

    /** @return array<string, mixed> */
    private function salePayload(User $actor, array $fixture, string $uuid, string $quantity, string $amount, string $paymentUuid): array
    {
        return [
            'actor_id' => (int) $actor->getKey(),
            'command_uuid' => $uuid,
            'cash_session_id' => $fixture['cashSessionId'],
            'lines' => [['article_id' => $fixture['articleId'], 'quantity' => $quantity, 'unit_price' => '1.00']],
            'payments' => [[
                'payment_method_id' => $fixture['paymentMethodId'],
                'type' => PaymentType::Charge->value,
                'amount' => $amount,
                'cash_session_id' => $fixture['cashSessionId'],
                'command_uuid' => $paymentUuid,
                'received_amount' => $amount,
                'change' => '0.00',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function multiArticleSalePayload(User $actor, array $fixture, string $uuid, array $articleIds): array
    {
        return [
            'actor_id' => (int) $actor->getKey(),
            'command_uuid' => $uuid,
            'cash_session_id' => $fixture['cashSessionId'],
            'lines' => array_map(static fn (int $articleId): array => [
                'article_id' => $articleId,
                'quantity' => '1.000',
                'unit_price' => '1.00',
            ], $articleIds),
            'payments' => [[
                'payment_method_id' => $fixture['paymentMethodId'],
                'type' => PaymentType::Charge->value,
                'amount' => '2.00',
                'cash_session_id' => $fixture['cashSessionId'],
                'command_uuid' => $uuid,
            ]],
        ];
    }

    /** @return array<string, int> */
    private function productionFixture(string $suffix, string $componentStock, ?int $sharedComponentId = null): array
    {
        $token = 'ab_prod_'.$suffix.'_'.bin2hex(random_bytes(3));
        $actor = $this->concurrencyActor([PermissionCode::ProductionConfirm, PermissionCode::ProductionAnnul], 'produccion_'.$suffix);
        $categoryId = $this->createArticleCategory(['codigo' => $token, 'nombre' => 'Produccion AB '.$token]);
        $unitId = $this->createUnitOfMeasure(['codigo' => 'abp'.bin2hex(random_bytes(3)), 'nombre' => 'Unidad produccion AB '.$token, 'simbolo' => 'p'.bin2hex(random_bytes(2)), 'magnitud' => 'unidad']);
        $productId = $this->createArticle($categoryId, $unitId, [
            'sku' => $token.'_producto',
            'nombre' => 'Producto AB '.$token,
            'tipo_articulo' => 'elaborado',
            'stock_actual' => '0.000',
            'activo' => true,
        ]);
        $componentId = $sharedComponentId ?? $this->createArticle($categoryId, $unitId, [
            'sku' => 'ab_comp_'.bin2hex(random_bytes(3)),
            'nombre' => 'Componente AB '.$token,
            'tipo_articulo' => 'materia_prima',
            'stock_actual' => $componentStock,
            'activo' => true,
        ]);

        if ($sharedComponentId !== null) {
            DB::table('articulos')->where('id', $componentId)->update(['stock_actual' => $componentStock]);
        } else {
            $this->createInventoryMovement($componentId, (int) $actor->getKey(), InventoryMovementType::InitialBalance->value, (string) Str::uuid(), [
                'usuario_autorizador_id' => (int) $actor->getKey(),
                'cantidad_delta' => $componentStock,
                'stock_anterior' => '0.000',
                'stock_posterior' => $componentStock,
                'motivo' => 'Saldo inicial AB',
                'ocurrido_en' => '2026-10-14 12:00:00-03:00',
            ]);
        }

        $recipeId = $this->createRecipe($productId, ['activa' => true]);
        $recipeDetailId = $this->createRecipeDetail($recipeId, $componentId, ['cantidad' => '1.000']);
        $productionId = $this->createProduction($recipeId, (int) $actor->getKey(), (int) $actor->getKey());
        $this->createProductionDetail($productionId, $productId, ['cantidad_real' => '2.000']);
        $this->createProductionConsumption($productionId, $componentId, $recipeDetailId, '1.000', ['cantidad_real' => '2.000']);

        return [
            'actor_id' => (int) $actor->getKey(),
            'production_id' => $productionId,
            'product_id' => $productId,
            'component_id' => $componentId,
        ];
    }

    private function concurrencyActor(array $permissions, string $suffix): User
    {
        $token = 'ab_'.$suffix.'_'.bin2hex(random_bytes(4));
        $roleId = $this->createRole(['codigo' => $token.'_rol', 'nombre' => 'Rol '.$token]);
        $userId = $this->createUser($roleId, ['nombre_usuario' => $token.'_usuario', 'correo' => null]);

        foreach ($permissions as $permission) {
            $code = $permission instanceof PermissionCode ? $permission->value : $permission;
            $permissionId = DB::table('permisos')->where('codigo', $code)->value('id');
            if ($permissionId === null) {
                $permissionId = $this->createPermission([
                    'codigo' => $code,
                    'nombre' => 'Permiso '.$code,
                    'modulo' => explode('.', $code, 2)[0],
                ]);
            }
            $this->attachPermission($roleId, (int) $permissionId);
        }

        return User::query()->findOrFail($userId);
    }

    private function concurrencyUuid(): string
    {
        return (string) Str::uuid();
    }
}
