<?php

declare(strict_types=1);

namespace Tests\Support\Orders;

use App\Enums\OrderStatus;
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

trait OrderDeliveryFixtures
{
    use CreatesBlockEightRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;

    private int $deliveryFixtureSequence = 0;

    protected function createDeliveryActor(array $permissions, string $suffix = 'entrega'): User
    {
        $token = $this->deliveryToken($suffix);
        $roleId = $this->createRole([
            'codigo' => 'r8_'.$token.'_rol',
            'nombre' => 'Rol R8 '.$token,
        ]);
        $userId = $this->createUser($roleId, [
            'nombre_usuario' => 'r8_'.$token.'_usuario',
            'correo' => null,
        ]);

        foreach ($permissions as $permission) {
            $code = $permission instanceof PermissionCode ? $permission->value : $permission;
            $permissionId = $this->createPermission([
                'codigo' => $code,
                'nombre' => 'Permiso R8 '.$code,
                'modulo' => explode('.', $code, 2)[0],
            ]);
            $this->attachPermission($roleId, $permissionId);
        }

        return User::query()->findOrFail($userId);
    }

    protected function createReadyOrderFixture(
        User $actor,
        bool $prepaid = true,
        bool $withSnapshots = true,
        bool $cashPayment = false,
        string $total = '10.00',
        string $priorPayment = '10.00',
    ): array {
        $token = $this->deliveryToken('pedido');
        $clientId = $this->createClient(['nombre' => 'Cliente R8 '.$token]);
        $categoryId = $this->createArticleCategory([
            'codigo' => 'r8_'.$token.'_cat',
            'nombre' => 'Categoria R8 '.$token,
        ]);
        $unitId = $this->createUnitOfMeasure([
            'codigo' => 'r8u'.$this->deliveryFixtureSequence,
            'nombre' => 'Unidad R8 '.$token,
            'simbolo' => 'u'.$this->deliveryFixtureSequence,
            'magnitud' => 'unidad',
        ]);
        $articleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'r8_'.$token,
            'nombre' => 'Articulo R8 '.$token,
            'tipo_articulo' => 'elaborado',
            'precio_venta_actual' => $total,
            'stock_actual' => '10.000',
            'activo' => true,
        ]);
        $register = DB::table('cajas')->where('codigo', 'principal')->value('id')
            ?? $this->createCashRegister(['codigo' => 'principal', 'nombre' => 'Caja principal']);
        $cashSessionId = DB::table('sesiones_caja')->where('caja_id', $register)->whereNull('cerrada_en')->value('id')
            ?? $this->createOperationalCashSession($register, (int) $actor->getKey());
        $paymentMethodId = $this->createPaymentMethod([
            'codigo' => 'r8_'.$token.'_metodo',
            'nombre' => 'Metodo R8 '.$token,
            'afecta_efectivo' => $cashPayment,
        ]);
        $occurredAt = '2026-09-24 12:00:00-03';
        $orderUuid = 'd8000000-0000-4000-8000-'.str_pad((string) $this->deliveryFixtureSequence, 12, '0', STR_PAD_LEFT);
        $orderId = (int) DB::table('pedidos')->insertGetId([
            'cliente_id' => $clientId,
            'usuario_creador_id' => (int) $actor->getKey(),
            'estado' => OrderStatus::Ready->value,
            'entregado_en' => null,
            'cancelado_en' => null,
            'observacion' => null,
            'clave_idempotencia' => $orderUuid,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

        DB::table('detalle_pedidos')->insert([
            'pedido_id' => $orderId,
            'articulo_id' => $articleId,
            'cantidad' => '1.000',
            'precio_unitario' => $total,
            'observacion' => null,
            'articulo_sku_snapshot' => $withSnapshots ? 'r8_'.$token : null,
            'articulo_nombre_snapshot' => $withSnapshots ? 'Articulo R8 '.$token : null,
            'unidad_codigo_snapshot' => $withSnapshots ? 'r8u'.$this->deliveryFixtureSequence : null,
            'unidad_simbolo_snapshot' => $withSnapshots ? 'u'.$this->deliveryFixtureSequence : null,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);

        $historyBase = 100 + ($this->deliveryFixtureSequence * 10);
        $history = [
            [null, 'pendiente', $this->deliveryUuid($historyBase + 1)],
            ['pendiente', 'confirmado', $this->deliveryUuid($historyBase + 2)],
            ['confirmado', 'en_preparacion', $this->deliveryUuid($historyBase + 3)],
            ['en_preparacion', 'listo', $this->deliveryUuid($historyBase + 4)],
        ];
        foreach ($history as [$previous, $next, $uuid]) {
            DB::table('transiciones_pedido')->insert([
                'pedido_id' => $orderId,
                'estado_anterior' => $previous,
                'estado_nuevo' => $next,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $uuid,
                'motivo' => null,
                'ocurrida_en' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
        }

        $paymentId = null;
        if ($prepaid) {
            $paymentId = $this->createOrderPayment(
                $orderId,
                $paymentMethodId,
                $cashSessionId,
                (int) $actor->getKey(),
                $this->deliveryUuid(200 + $this->deliveryFixtureSequence),
                [
                    'monto' => $priorPayment,
                    'ocurrido_en' => $occurredAt,
                    'created_at' => $occurredAt,
                ],
            );
        }

        return compact('orderId', 'articleId', 'clientId', 'cashSessionId', 'paymentMethodId', 'paymentId', 'orderUuid');
    }

    protected function deliveryState(int $orderId): array
    {
        return [
            'pedido' => (array) DB::table('pedidos')->where('id', $orderId)->first(),
            'detalle' => DB::table('detalle_pedidos')->where('pedido_id', $orderId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'pagos_pedido' => DB::table('pagos_pedido')->where('pedido_id', $orderId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'transiciones' => DB::table('transiciones_pedido')->where('pedido_id', $orderId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'ventas' => DB::table('ventas')->where('pedido_id', $orderId)->get()->map(fn (object $row): array => (array) $row)->all(),
            'detalle_ventas' => DB::table('detalle_ventas')->whereIn('venta_id', DB::table('ventas')->where('pedido_id', $orderId)->pluck('id'))->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'pagos_venta' => DB::table('pagos_venta')->whereIn('venta_id', DB::table('ventas')->where('pedido_id', $orderId)->pluck('id'))->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'articulos' => DB::table('articulos')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'movimientos_inventario' => DB::table('movimientos_inventario')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'stock_diario' => DB::table('stock_diario')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'correlativos_venta_diarios' => DB::table('correlativos_venta_diarios')->orderBy('fecha_comercial')->get()->map(fn (object $row): array => (array) $row)->all(),
            'movimientos_caja' => DB::table('movimientos_caja')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    protected function deliveryUuid(int $number): string
    {
        return 'd8300000-0000-4000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
    }

    private function deliveryToken(string $prefix): string
    {
        $this->deliveryFixtureSequence++;

        return strtolower($prefix.'_'.$this->deliveryFixtureSequence);
    }
}
