<?php

declare(strict_types=1);

namespace Tests\Support\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;

trait OrderServiceFixtures
{
    use CreatesBlockEightRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;

    private int $orderFixtureSequence = 0;

    protected function createOrderActor(
        array $permissions = [],
        string $suffix = 'actor',
    ): User {
        $roleId = $this->createRole([
            'codigo' => $this->orderFixtureToken($suffix.'_rol'),
            'nombre' => 'Rol de pedidos',
        ]);
        $userId = $this->createUser($roleId, [
            'nombre_usuario' => $this->orderFixtureToken($suffix.'_usuario'),
            'correo' => null,
        ]);

        foreach ($permissions as $permission) {
            $code = $permission instanceof PermissionCode
                ? $permission->value
                : $permission;
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

    protected function createOrderClient(string $suffix = 'cliente'): int
    {
        return $this->createClient([
            'nombre' => 'Cliente '.$this->orderFixtureToken($suffix),
        ]);
    }

    protected function createOrderArticle(
        string $suffix = 'articulo',
        string $stock = '10.000',
        string $price = '12.50',
    ): int {
        $token = $this->orderFixtureToken($suffix);
        $unitSequence = $this->orderFixtureSequence;
        $categoryId = $this->createArticleCategory([
            'codigo' => 'cat_'.$token,
            'nombre' => 'Categoria '.$token,
        ]);
        $unitId = $this->createUnitOfMeasure([
            'codigo' => 'un'.$unitSequence,
            'nombre' => 'Unidad '.$unitSequence,
            'simbolo' => 'u'.$unitSequence,
            'magnitud' => 'unidad',
        ]);

        return $this->createArticle($categoryId, $unitId, [
            'sku' => 'sku_'.$token,
            'nombre' => 'Articulo '.$token,
            'tipo_articulo' => 'elaborado',
            'precio_venta_actual' => $price,
            'stock_actual' => $stock,
            'activo' => true,
        ]);
    }

    protected function createOrderCashSession(User $actor, string $suffix = 'caja', bool $principal = true): int
    {
        $token = $this->orderFixtureToken($suffix);

        return $this->createOperationalCashSession(
            $this->createCashRegister([
                'codigo' => $principal ? 'principal' : 'caja_'.$token,
                'nombre' => $principal ? 'Caja principal' : 'Caja '.$token,
            ]),
            (int) $actor->getKey(),
        );
    }

    protected function createOrderPaymentMethod(string $suffix = 'pago'): int
    {
        $token = $this->orderFixtureToken($suffix);

        return $this->createPaymentMethod([
            'codigo' => 'pago_'.$token,
            'nombre' => 'Pago '.$token,
        ]);
    }

    protected function orderFixtureUuid(string $suffix): string
    {
        return 'a1000000-0000-4000-8000-'.str_pad($suffix, 12, '0', STR_PAD_LEFT);
    }

    protected function orderFixtureToken(string $prefix): string
    {
        $this->orderFixtureSequence++;

        return strtolower($prefix.'_'.$this->orderFixtureSequence);
    }

    protected function orderStateSnapshot(): array
    {
        return array_map(
            static function (string $table): array {
                return DB::table($table)
                    ->orderBy('id')
                    ->get()
                    ->map(static fn (object $row): array => (array) $row)
                    ->all();
            },
            [
                'pedidos' => 'pedidos',
                'detalle_pedidos' => 'detalle_pedidos',
                'pagos_pedido' => 'pagos_pedido',
                'transiciones_pedido' => 'transiciones_pedido',
                'articulos' => 'articulos',
                'movimientos_inventario' => 'movimientos_inventario',
                'stock_diario' => 'stock_diario',
                'ventas' => 'ventas',
                'detalle_ventas' => 'detalle_ventas',
                'pagos_venta' => 'pagos_venta',
            ],
        );
    }

    protected function createHistoricalOrder(
        User $actor,
        int $clientId,
        OrderStatus $status,
        string $orderUuid,
        ?string $reason = null,
    ): int {
        $occurredAt = '2026-09-24 12:00:00-03';
        $orderId = (int) DB::table('pedidos')->insertGetId([
            'cliente_id' => $clientId,
            'usuario_creador_id' => (int) $actor->getKey(),
            'estado' => $status->value,
            'entregado_en' => $status === OrderStatus::Delivered ? $occurredAt : null,
            'cancelado_en' => $status === OrderStatus::Cancelled ? $occurredAt : null,
            'observacion' => null,
            'clave_idempotencia' => $orderUuid,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

        $history = [
            [null, OrderStatus::Pending, $orderUuid, null],
        ];

        if ($status === OrderStatus::Confirmed || $status === OrderStatus::InPreparation
            || $status === OrderStatus::Ready || $status === OrderStatus::Delivered) {
            $history[] = [OrderStatus::Pending, OrderStatus::Confirmed, 'a2000000-0000-4000-8000-000000000002', null];
        }
        if ($status === OrderStatus::InPreparation || $status === OrderStatus::Ready
            || $status === OrderStatus::Delivered) {
            $history[] = [OrderStatus::Confirmed, OrderStatus::InPreparation, 'a2000000-0000-4000-8000-000000000003', null];
        }
        if ($status === OrderStatus::Ready || $status === OrderStatus::Delivered) {
            $history[] = [OrderStatus::InPreparation, OrderStatus::Ready, 'a2000000-0000-4000-8000-000000000004', null];
        }
        if ($status === OrderStatus::Delivered) {
            $history[] = [OrderStatus::Ready, OrderStatus::Delivered, 'a2000000-0000-4000-8000-000000000005', null];
        }
        if ($status === OrderStatus::Cancelled) {
            $history[] = [OrderStatus::Pending, OrderStatus::Cancelled, 'a2000000-0000-4000-8000-000000000006', $reason ?? 'Cancelacion historica'];
        }

        foreach ($history as [$previous, $next, $transitionUuid, $transitionReason]) {
            DB::table('transiciones_pedido')->insert([
                'pedido_id' => $orderId,
                'estado_anterior' => $previous?->value,
                'estado_nuevo' => $next->value,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $transitionUuid,
                'motivo' => $transitionReason,
                'ocurrida_en' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
        }

        return $orderId;
    }

    protected function createOrderPaymentRecord(
        int $orderId,
        User $actor,
        int $paymentMethodId,
        int $cashSessionId,
        string $uuid,
        string $amount = '10.00',
        PaymentType $type = PaymentType::Charge,
    ): int {
        return (int) DB::table('pagos_pedido')->insertGetId([
            'pedido_id' => $orderId,
            'metodo_pago_id' => $paymentMethodId,
            'sesion_caja_id' => $cashSessionId,
            'usuario_id' => (int) $actor->getKey(),
            'clave_idempotencia' => $uuid,
            'tipo' => $type->value,
            'monto' => $amount,
            'monto_recibido' => null,
            'vuelto' => null,
            'ocurrido_en' => '2026-09-24 12:00:00-03',
            'observacion' => null,
            'created_at' => '2026-09-24 12:00:00-03',
        ]);
    }

    protected function createLinkedHistoricalSale(
        User $actor,
        int $orderId,
        int $cashSessionId,
        string $uuid,
    ): int {
        $clientId = (int) DB::table('pedidos')->where('id', $orderId)->value('cliente_id');

        return $this->createSale(
            (int) $actor->getKey(),
            $cashSessionId,
            $uuid,
            [
                'cliente_id' => $clientId,
                'pedido_id' => $orderId,
                'total' => '10.00',
            ],
        );
    }
}
