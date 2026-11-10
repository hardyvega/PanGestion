<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sales;

use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Enums\SaleStatus;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\User;
use App\Models\Venta;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Sales\SaleServiceFixtures;
use Tests\TestCase;

final class SaleServiceCompletionTest extends TestCase
{
    use RefreshDatabase;
    use SaleServiceFixtures;

    private User $actor;

    private int $categoryId;

    private int $unitId;

    private int $cashSessionId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 15:00:00-03'));

        $this->actor = $this->createSalesActor([
            PermissionCode::SalesCreate,
            PermissionCode::SalesAnnul,
        ]);
        $this->categoryId = $this->createArticleCategory([
            'codigo' => $this->fixtureCode('categoria'),
            'nombre' => 'Categoria de venta',
        ]);
        $this->unitId = $this->createUnitOfMeasure([
            'codigo' => $this->fixtureCode('unidad'),
            'nombre' => 'Unidad de venta',
            'simbolo' => 'un',
            'magnitud' => 'unidad',
        ]);
        $this->cashSessionId = $this->createSalesCashSession($this->actor);
        $this->paymentMethodId = $this->createSalesPaymentMethod();
    }

    public function test_manual_sale_contract_covers_persistence_stock_ledger_projection_and_snapshots(): void
    {
        $articleId = $this->createSalesArticle(
            $this->categoryId,
            $this->unitId,
            stock: '10.000',
            price: '4.00',
        );
        $command = new CompleteSaleCommand(
            'e1000000-0000-4000-8000-000000000001',
            $this->cashSessionId,
            [new SaleLineData($articleId, '1.250', '4.00')],
            [new SalePaymentData(
                $this->paymentMethodId,
                PaymentType::Charge,
                '5.00',
                $this->cashSessionId,
                'e1000000-0000-4000-8000-000000000002',
                '6.00',
                '1.00',
                'Pago exacto con vuelto',
            )],
            completedAt: CarbonImmutable::parse('2026-09-24 15:00:00-03'),
        );

        $sale = $this->service()->complete($this->actor, $command);

        $this->assertInstanceOf(Venta::class, $sale);
        $this->assertSame(SaleStatus::Completed, $sale->estado);
        $this->assertNull($sale->pedido_id);
        $this->assertSame((int) $this->actor->getKey(), $sale->usuario_id);
        $this->assertNull($sale->cliente_id);
        $this->assertSame($this->cashSessionId, $sale->sesion_caja_id);
        $this->assertSame($command->commandUuid, $sale->clave_idempotencia);
        $this->assertSame('0.00', $sale->monto_anticipo_aplicado);
        $this->assertSame('2026-09-24', $sale->fecha_comercial->format('Y-m-d'));
        $this->assertSame('5.00', $sale->total);
        $this->assertNotNull($sale->completada_en);
        $this->assertDatabaseHas('detalle_ventas', [
            'venta_id' => $sale->getKey(),
            'cantidad' => '1.250',
            'precio_unitario' => '4.00',
            'subtotal' => '5.00',
        ]);
        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $sale->getKey(),
            'metodo_pago_id' => $this->paymentMethodId,
            'sesion_caja_id' => $this->cashSessionId,
            'usuario_id' => $this->actor->getKey(),
            'tipo' => 'cobro',
            'monto' => '5.00',
            'monto_recibido' => '6.00',
            'vuelto' => '1.00',
        ]);
        $this->assertSame('8.750', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => 'venta',
            'detalle_venta_id' => DB::table('detalle_ventas')->where('venta_id', $sale->getKey())->value('id'),
            'cantidad_delta' => '-1.250',
            'clave_idempotencia' => $command->commandUuid,
            'secuencia_idempotencia' => 1,
        ]);
        $this->assertDatabaseHas('stock_diario', [
            'articulo_id' => $articleId,
            'fecha_comercial' => '2026-09-24',
            'stock_cierre' => '8.750',
        ]);
    }

    public function test_manual_sale_accepts_a_valid_client_without_creating_one(): void
    {
        $clientId = $this->createClient(['nombre' => 'Cliente seleccionado']);
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId);
        $command = $this->commandFor($articleId, 'e1000000-0000-4000-8000-000000000003', '4.00', clientId: $clientId);

        $sale = $this->service()->complete($this->actor, $command);

        $this->assertSame($clientId, $sale->cliente_id);
        $this->assertSame(1, DB::table('clientes')->where('id', $clientId)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sellableArticleTypes')]
    public function test_active_elaborated_and_resale_articles_are_allowed(string $type): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, $type);

        $sale = $this->service()->complete(
            $this->actor,
            $this->commandFor($articleId, $this->fixtureUuid(), '4.00'),
        );

        $this->assertInstanceOf(Venta::class, $sale);
    }

    public static function sellableArticleTypes(): array
    {
        return [
            'elaborated' => ['elaborado'],
            'resale' => ['reventa'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonSellableArticleTypes')]
    public function test_active_raw_material_and_operational_supply_are_rejected(string $type): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, $type);

        $this->expectException(InventoryDomainException::class);
        $this->service()->complete(
            $this->actor,
            $this->commandFor($articleId, $this->fixtureUuid(), '4.00'),
        );
    }

    public static function nonSellableArticleTypes(): array
    {
        return [
            'raw material' => ['materia_prima'],
            'operational supply' => ['insumo_operacional'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inactiveArticleTypes')]
    public function test_inactive_sellable_articles_are_rejected(string $type): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, $type, active: false);

        $this->expectException(InventoryDomainException::class);
        $this->service()->complete(
            $this->actor,
            $this->commandFor($articleId, $this->fixtureUuid(), '4.00'),
        );
    }

    public static function inactiveArticleTypes(): array
    {
        return [
            'inactive elaborated' => ['elaborado'],
            'inactive resale' => ['reventa'],
        ];
    }

    public function test_single_line_stock_shortage_is_rejected_before_any_mutation(): void
    {
        $articleId = $this->createSalesArticle(
            $this->categoryId,
            $this->unitId,
            stock: '5.000',
            price: '1.00',
        );
        $before = $this->salesState();
        $caught = null;

        try {
            $this->service()->complete(
                $this->actor,
                new CompleteSaleCommand(
                    'e1000000-0000-4000-8000-000000000015',
                    $this->cashSessionId,
                    [new SaleLineData($articleId, '6.000', '1.00')],
                    [new SalePaymentData(
                        $this->paymentMethodId,
                        PaymentType::Charge,
                        '6.00',
                        $this->cashSessionId,
                        'e1000000-0000-4000-8000-000000000016',
                    )],
                ),
            );
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(InsufficientStockException::class, $caught);
        $this->assertSame($before, $this->salesState());
    }

    public function test_insufficient_aggregate_stock_is_rejected_before_any_mutation(): void
    {
        $articleId = $this->createSalesArticle(
            $this->categoryId,
            $this->unitId,
            stock: '5.000',
            price: '1.00',
        );
        $before = $this->salesState();

        $caught = null;

        try {
            $this->service()->complete($this->actor, new CompleteSaleCommand(
                'e1000000-0000-4000-8000-000000000004',
                $this->cashSessionId,
                [
                    new SaleLineData($articleId, '3.000', '1.00'),
                    new SaleLineData($articleId, '3.000', '1.00'),
                ],
                [new SalePaymentData(
                    $this->paymentMethodId,
                    PaymentType::Charge,
                    '6.00',
                    $this->cashSessionId,
                    'e1000000-0000-4000-8000-000000000005',
                )],
            ));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(InsufficientStockException::class, $caught);
        $this->assertSame($before, $this->salesState());
    }

    public function test_repeated_lines_remain_separate_and_multiple_articles_keep_exact_scales(): void
    {
        $firstArticleId = $this->createSalesArticle(
            $this->categoryId,
            $this->unitId,
            stock: '10.000',
            price: '8.88',
        );
        $secondArticleId = $this->createSalesArticle(
            $this->categoryId,
            $this->unitId,
            stock: '10.000',
            price: '2.00',
        );
        $command = new CompleteSaleCommand(
            'e1000000-0000-4000-8000-000000000006',
            $this->cashSessionId,
            [
                new SaleLineData($firstArticleId, '1.000', '8.88'),
                new SaleLineData($firstArticleId, '0.125', '8.88'),
                new SaleLineData($secondArticleId, '3.000', '2.00'),
            ],
            [new SalePaymentData(
                $this->paymentMethodId,
                PaymentType::Charge,
                '15.99',
                $this->cashSessionId,
                'e1000000-0000-4000-8000-000000000007',
            )],
        );

        $sale = $this->service()->complete($this->actor, $command);

        $details = DB::table('detalle_ventas')->where('venta_id', $sale->getKey())->orderBy('id')->get();
        $this->assertCount(3, $details);
        $this->assertSame(['8.88', '1.11', '6.00'], $details->pluck('subtotal')->all());
        $this->assertSame('8.875', DB::table('articulos')->where('id', $firstArticleId)->value('stock_actual'));
        $this->assertSame('7.000', DB::table('articulos')->where('id', $secondArticleId)->value('stock_actual'));
        $this->assertSame(3, DB::table('movimientos_inventario')->where('clave_idempotencia', $command->commandUuid)->count());
        $this->assertSame([1, 2, 3], DB::table('movimientos_inventario')->where('clave_idempotencia', $command->commandUuid)->orderBy('secuencia_idempotencia')->pluck('secuencia_idempotencia')->all());
        $this->assertSame(
            $details->pluck('id')->all(),
            DB::table('movimientos_inventario')->where('clave_idempotencia', $command->commandUuid)->orderBy('secuencia_idempotencia')->pluck('detalle_venta_id')->all(),
        );
    }

    public function test_creation_requires_exact_sales_create_permission_and_preserves_state_when_denied(): void
    {
        $actor = $this->createSalesActor([], 'sin_permiso');
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId);
        $before = $this->salesState();

        $caught = null;

        try {
            $this->service()->complete(
                $actor,
                $this->commandFor($articleId, $this->fixtureUuid(), '4.00'),
            );
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $caught);
        $this->assertSame($before, $this->salesState());
    }

    public function test_creation_retry_with_same_uuid_is_idempotent(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId);
        $command = $this->commandFor($articleId, 'e1000000-0000-4000-8000-000000000008', '4.00');

        $first = $this->service()->complete($this->actor, $command);
        $before = $this->salesState();
        $retry = $this->service()->complete($this->actor, $command);

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($before, $this->salesState());
    }

    public function test_creation_retry_with_same_uuid_and_different_intention_conflicts(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId);
        $uuid = 'e1000000-0000-4000-8000-000000000009';

        $this->service()->complete($this->actor, $this->commandFor($articleId, $uuid, '4.00'));

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->complete($this->actor, $this->commandFor($articleId, $uuid, '8.00'));
    }

    public function test_creation_consumes_daily_correlative_by_chilean_commercial_date(): void
    {
        $articleId = $this->createSalesArticle(
            $this->categoryId,
            $this->unitId,
            stock: '100.000',
            price: '1.00',
        );

        $this->travelTo(CarbonImmutable::parse('2026-09-24 23:30:00-03'));
        $first = $this->service()->complete($this->actor, $this->commandFor(
            $articleId,
            'e1000000-0000-4000-8000-000000000010',
            '1.00',
            completedAt: CarbonImmutable::parse('2026-09-24 23:30:00-03'),
        ));
        $this->travelTo(CarbonImmutable::parse('2026-09-25 00:30:00-03'));
        $this->cashSessionId = $this->createSalesCashSession($this->actor, 'segunda_jornada');
        $second = $this->service()->complete($this->actor, $this->commandFor(
            $articleId,
            'e1000000-0000-4000-8000-000000000011',
            '1.00',
            completedAt: CarbonImmutable::parse('2026-09-25 00:30:00-03'),
        ));

        $this->assertSame(1, $first->numero_diario);
        $this->assertSame(1, $second->numero_diario);
        $this->assertSame(['2026-09-24', '2026-09-25'], DB::table('ventas')->orderBy('id')->pluck('fecha_comercial')->all());
    }

    public function test_mixed_payments_are_persisted_when_the_dto_supports_them(): void
    {
        $secondPaymentMethodId = $this->createSalesPaymentMethod('pago_mixto');
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '11.00');
        $command = new CompleteSaleCommand(
            'e1000000-0000-4000-8000-000000000012',
            $this->cashSessionId,
            [new SaleLineData($articleId, '1.000', '11.00')],
            [
                new SalePaymentData($this->paymentMethodId, PaymentType::Charge, '5.00', $this->cashSessionId, 'e1000000-0000-4000-8000-000000000013'),
                new SalePaymentData($secondPaymentMethodId, PaymentType::Charge, '6.00', $this->cashSessionId, 'e1000000-0000-4000-8000-000000000014'),
            ],
        );

        $sale = $this->service()->complete($this->actor, $command);

        $this->assertSame(2, DB::table('pagos_venta')->where('venta_id', $sale->getKey())->count());
        $this->assertSame(['5.00', '6.00'], DB::table('pagos_venta')->where('venta_id', $sale->getKey())->orderBy('id')->pluck('monto')->all());
    }

    private function service(): object
    {
        return $this->app->make(\App\Services\Sales\SaleService::class);
    }

    private function commandFor(
        int $articleId,
        string $uuid,
        string $total,
        ?int $clientId = null,
        ?CarbonImmutable $completedAt = null,
    ): CompleteSaleCommand {
        return new CompleteSaleCommand(
            $uuid,
            $this->cashSessionId,
            [new SaleLineData($articleId, '1.000', $total)],
            [new SalePaymentData(
                $this->paymentMethodId,
                PaymentType::Charge,
                $total,
                $this->cashSessionId,
                $this->fixtureUuid(),
            )],
            clientId: $clientId,
            completedAt: $completedAt,
        );
    }
}
