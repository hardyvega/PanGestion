<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Sales;

use App\Data\Sales\AnnulSaleCommand;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryMovementAlreadyReversedException;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Sales\SaleServiceFixtures;
use Tests\TestCase;

final class SaleServiceAnnulmentTest extends TestCase
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
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-24 15:00:00-03'));

        $this->actor = $this->createSalesActor([
            PermissionCode::SalesAnnul,
        ]);
        $this->categoryId = $this->createArticleCategory([
            'codigo' => $this->fixtureCode('categoria'),
            'nombre' => 'Categoria de anulacion',
        ]);
        $this->unitId = $this->createUnitOfMeasure([
            'codigo' => $this->fixtureCode('unidad'),
            'nombre' => 'Unidad de anulacion',
            'simbolo' => 'un',
            'magnitud' => 'unidad',
        ]);
        $this->cashSessionId = $this->createSalesCashSession($this->actor);
        $this->paymentMethodId = $this->createSalesPaymentMethod();
    }

    public function test_manual_annulment_preserves_sale_details_and_appends_stock_reversal(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);

        $sale = $this->service()->annul($this->actor, new AnnulSaleCommand(
            $fixture['sale_id'],
            'e2000000-0000-4000-8000-000000000001',
            'Anulacion solicitada por el cliente',
        ));

        $this->assertInstanceOf(Venta::class, $sale);
        $this->assertSame($fixture['sale_id'], $sale->getKey());
        $this->assertSame('anulada', $sale->estado->value);
        $this->assertSame((int) $this->actor->getKey(), $sale->usuario_anulador_id);
        $this->assertSame('Anulacion solicitada por el cliente', $sale->motivo_anulacion);
        $this->assertSame('e2000000-0000-4000-8000-000000000001', $sale->clave_idempotencia_anulacion);
        $this->assertSame(1, DB::table('detalle_ventas')->where('venta_id', $fixture['sale_id'])->count());
        $this->assertSame('10.000', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => 'venta',
            'id' => $fixture['movement_id'],
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => 'anulacion_venta',
            'movimiento_revertido_id' => $fixture['movement_id'],
            'detalle_venta_id' => $fixture['sale_detail_id'],
            'cantidad_delta' => '3.000',
            'clave_idempotencia' => 'e2000000-0000-4000-8000-000000000001',
            'secuencia_idempotencia' => 1,
        ]);
        $this->assertDatabaseHas('stock_diario', [
            'articulo_id' => $articleId,
            'fecha_comercial' => '2026-09-24',
            'stock_cierre' => '10.000',
        ]);
    }

    public function test_annulment_is_allowed_after_the_article_becomes_inactive(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);
        DB::table('articulos')->where('id', $articleId)->update(['activo' => false]);

        $sale = $this->service()->annul($this->actor, new AnnulSaleCommand(
            $fixture['sale_id'],
            'e2000000-0000-4000-8000-000000000002',
            'Reversion historica autorizada',
        ));

        $this->assertSame('anulada', $sale->estado->value);
        $this->assertSame('10.000', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
    }

    public function test_second_real_annulment_does_not_restore_stock_or_append_a_second_reversal(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);
        $this->service()->annul($this->actor, new AnnulSaleCommand(
            $fixture['sale_id'],
            'e2000000-0000-4000-8000-000000000003',
            'Primera anulacion',
        ));
        $before = $this->annulmentState();

        $caught = null;

        try {
            $this->service()->annul($this->actor, new AnnulSaleCommand(
                $fixture['sale_id'],
                'e2000000-0000-4000-8000-000000000004',
                'Segunda anulacion',
            ));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(InventoryMovementAlreadyReversedException::class, $caught);
        $this->assertSame($before, $this->annulmentState());
    }

    public function test_annulment_retry_with_same_uuid_is_idempotent(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);
        $command = new AnnulSaleCommand(
            $fixture['sale_id'],
            'e2000000-0000-4000-8000-000000000005',
            'Retry de anulacion',
        );

        $first = $this->service()->annul($this->actor, $command);
        $before = $this->annulmentState();
        $retry = $this->service()->annul($this->actor, $command);

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame($before, $this->annulmentState());
    }

    public function test_annulment_retry_with_same_uuid_and_different_reason_conflicts(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);
        $uuid = 'e2000000-0000-4000-8000-000000000006';

        $this->service()->annul($this->actor, new AnnulSaleCommand($fixture['sale_id'], $uuid, 'Motivo original'));

        $this->expectException(IdempotencyConflictException::class);
        $this->service()->annul($this->actor, new AnnulSaleCommand($fixture['sale_id'], $uuid, 'Motivo distinto'));
    }

    public function test_annulment_requires_exact_sales_annul_permission_and_preserves_audit_state(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);
        $actorWithoutPermission = $this->createSalesActor([], 'sin_permiso_anular');
        $before = $this->annulmentState();

        $caught = null;

        try {
            $this->service()->annul($actorWithoutPermission, new AnnulSaleCommand(
                $fixture['sale_id'],
                'e2000000-0000-4000-8000-000000000007',
                'Anulacion no autorizada',
            ));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $caught);
        $this->assertSame($before, $this->annulmentState());
    }

    public function test_manual_annulment_creates_one_refund_in_sale_payments_without_cash_posting(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale($this->actor, $articleId, $this->cashSessionId, $this->paymentMethodId);

        $this->service()->annul($this->actor, new AnnulSaleCommand(
            $fixture['sale_id'],
            'e2000000-0000-4000-8000-000000000008',
            'Devolucion de cobro',
        ));

        $this->assertDatabaseHas('pagos_venta', [
            'venta_id' => $fixture['sale_id'],
            'tipo' => 'devolucion',
            'monto' => '30.00',
            'metodo_pago_id' => $this->paymentMethodId,
            'sesion_caja_id' => $this->cashSessionId,
            'usuario_id' => $this->actor->getKey(),
        ]);
        $this->assertSame(1, DB::table('pagos_venta')
            ->where('venta_id', $fixture['sale_id'])
            ->where('tipo', 'devolucion')
            ->count());
    }

    public function test_derived_sale_annulment_refunds_order_payment_and_keeps_order_delivered(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '7.000', price: '10.00');
        $fixture = $this->createHistoricalSale(
            $this->actor,
            $articleId,
            $this->cashSessionId,
            $this->paymentMethodId,
            ['derived_from_order' => true, 'with_sale_payment' => false],
        );

        $this->service()->annul($this->actor, new AnnulSaleCommand(
            $fixture['sale_id'],
            'e2000000-0000-4000-8000-000000000009',
            'Devolucion de pedido entregado',
        ));

        $this->assertDatabaseHas('pedidos', [
            'id' => $fixture['order_id'],
            'estado' => 'entregado',
        ]);
        $this->assertSame(1, DB::table('pagos_pedido')->where('pedido_id', $fixture['order_id'])->where('tipo', 'devolucion')->count());
        $this->assertSame(0, DB::table('pagos_venta')->where('venta_id', $fixture['sale_id'])->where('tipo', 'devolucion')->count());
    }

    private function service(): object
    {
        return $this->app->make(\App\Services\Sales\SaleService::class);
    }

    private function annulmentState(): array
    {
        return array_map(
            static fn (string $table): array => DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            ['ventas', 'detalle_ventas', 'pagos_venta', 'pagos_pedido', 'articulos', 'movimientos_inventario', 'stock_diario'],
        );
    }
}
