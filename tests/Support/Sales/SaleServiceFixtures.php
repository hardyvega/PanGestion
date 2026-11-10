<?php

declare(strict_types=1);

namespace Tests\Support\Sales;

use App\Enums\PermissionCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;

trait SaleServiceFixtures
{
    use CreatesBlockEightRecords;
    use CreatesBlockElevenRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;

    private static int $fixtureSequence = 0;

    protected function fixtureCode(string $prefix): string
    {
        self::$fixtureSequence++;

        return 'r4_'.$prefix.'_'.self::$fixtureSequence;
    }

    protected function createSalesActor(array $permissions = [], string $suffix = 'actor'): User
    {
        $roleId = $this->createRole([
            'codigo' => $this->fixtureCode($suffix.'_rol'),
            'nombre' => 'Rol de ventas',
        ]);
        $userId = $this->createUser($roleId, [
            'nombre_usuario' => $this->fixtureCode($suffix.'_usuario'),
            'correo' => null,
        ]);

        foreach ($permissions as $permission) {
            $permissionCode = $permission instanceof PermissionCode
                ? $permission->value
                : $permission;
            $permissionId = $this->createPermission([
                'codigo' => $permissionCode,
                'nombre' => 'Permiso '.$permissionCode,
                'modulo' => explode('.', $permissionCode, 2)[0],
            ]);
            $this->attachPermission($roleId, $permissionId);
        }

        return User::query()->findOrFail($userId);
    }

    protected function createSalesArticle(
        int $categoryId,
        int $unitId,
        string $type = 'elaborado',
        bool $active = true,
        string $stock = '10.000',
        string $price = '4.00',
    ): int {
        return $this->createArticle($categoryId, $unitId, [
            'sku' => $this->fixtureCode('articulo'),
            'nombre' => 'Articulo de venta',
            'tipo_articulo' => $type,
            'precio_venta_actual' => $price,
            'stock_actual' => $stock,
            'activo' => $active,
        ]);
    }

    protected function createSalesPaymentMethod(string $suffix = 'pago'): int
    {
        return $this->createPaymentMethod([
            'codigo' => $this->fixtureCode($suffix),
            'nombre' => 'Metodo '.$suffix,
        ]);
    }

    protected function createSalesCashSession(User $actor, string $suffix = 'caja'): int
    {
        return $this->createOperationalCashSession(
            $this->createCashRegister([
                'codigo' => $this->fixtureCode($suffix),
                'nombre' => 'Caja '.$suffix,
            ]),
            (int) $actor->getKey(),
        );
    }

    protected function createHistoricalSale(
        User $actor,
        int $articleId,
        int $cashSessionId,
        int $paymentMethodId,
        array $overrides = [],
    ): array {
        $options = array_merge([
            'sale_uuid' => $this->fixtureUuid(),
            'payment_uuid' => $this->fixtureUuid(),
            'movement_uuid' => $this->fixtureUuid(),
            'date' => '2026-09-24',
            'completed_at' => '2026-09-24 12:00:00-03',
            'number' => 1,
            'client_id' => null,
            'derived_from_order' => false,
            'quantity' => '3.000',
            'stock_before' => '10.000',
            'stock_after' => '7.000',
            'total' => '30.00',
            'with_sale_payment' => true,
        ], $overrides);

        $clientId = $options['client_id'] ?? $this->createClient([
            'nombre' => 'Cliente historico',
        ]);
        $orderId = null;

        if ($options['derived_from_order']) {
            $orderId = $this->createOrder($clientId, (int) $actor->getKey(), [
                'estado' => 'entregado',
                'entregado_en' => $options['completed_at'],
            ]);
            $this->createOrderPayment(
                $orderId,
                $paymentMethodId,
                $cashSessionId,
                (int) $actor->getKey(),
                $this->fixtureUuid(),
                ['tipo' => 'cobro', 'monto' => $options['total']],
            );
        }

        $saleId = $this->createSale(
            (int) $actor->getKey(),
            $cashSessionId,
            $options['sale_uuid'],
            [
                'fecha_comercial' => $options['date'],
                'numero_diario' => $options['number'],
                'cliente_id' => $clientId,
                'pedido_id' => $orderId,
                'total' => $options['total'],
                'completada_en' => $options['completed_at'],
            ],
        );
        $saleDetailId = $this->createSaleDetail($saleId, $articleId, [
            'cantidad' => $options['quantity'],
            'precio_unitario' => $options['total'] === '30.00' ? '10.00' : '4.00',
            'subtotal' => $options['total'],
        ]);

        $salePaymentId = null;
        if ($options['with_sale_payment']) {
            $salePaymentId = $this->createSalePayment(
                $saleId,
                $paymentMethodId,
                $cashSessionId,
                (int) $actor->getKey(),
                $options['payment_uuid'],
                ['tipo' => 'cobro', 'monto' => $options['total']],
            );
        }

        $movementId = $this->createInventoryMovement(
            $articleId,
            (int) $actor->getKey(),
            'venta',
            $options['movement_uuid'],
            [
                'cantidad_delta' => '-'.$options['quantity'],
                'stock_anterior' => $options['stock_before'],
                'stock_posterior' => $options['stock_after'],
                'detalle_venta_id' => $saleDetailId,
                'ocurrido_en' => $options['completed_at'],
            ],
        );
        DB::table('articulos')->where('id', $articleId)->update([
            'stock_actual' => $options['stock_after'],
        ]);
        $this->createStockDaily($articleId, [
            'fecha_comercial' => $options['date'],
            'stock_apertura' => $options['stock_before'],
            'total_entradas' => '0.000',
            'total_salidas' => $options['quantity'],
            'stock_cierre' => $options['stock_after'],
            'ultimo_movimiento_id' => $movementId,
        ]);

        return [
            'sale_id' => $saleId,
            'sale_detail_id' => $saleDetailId,
            'sale_payment_id' => $salePaymentId,
            'movement_id' => $movementId,
            'client_id' => $clientId,
            'order_id' => $orderId,
        ];
    }

    protected function fixtureUuid(): string
    {
        self::$fixtureSequence++;
        $suffix = str_pad((string) self::$fixtureSequence, 12, '0', STR_PAD_LEFT);

        return 'e4000000-0000-4000-8000-'.$suffix;
    }

    protected function salesState(): array
    {
        return array_map(
            static function (string $table): array {
                $query = DB::table($table);
                $query->orderBy(
                    $table === 'correlativos_venta_diarios'
                        ? 'fecha_comercial'
                        : 'id',
                );

                return $query
                    ->get()
                    ->map(static fn (object $row): array => (array) $row)
                    ->all();
            },
            [
                'ventas',
                'detalle_ventas',
                'pagos_venta',
                'articulos',
                'movimientos_inventario',
                'stock_diario',
                'correlativos_venta_diarios',
            ],
        );
    }
}
