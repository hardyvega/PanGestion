<?php

declare(strict_types=1);

namespace Tests\Support\Cash;

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
use Tests\Support\CreatesBlockElevenRecords;

trait DerivedCashPostingFixtures
{
    use CreatesBlockEightRecords;
    use CreatesBlockElevenRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;

    private int $cashPostingFixtureSequence = 0;

    protected function createCashPostingActor(array $permissions, string $suffix = 'actor'): User
    {
        $token = $this->cashPostingToken($suffix);
        $roleId = $this->createRole([
            'codigo' => 'r8_'.$token.'_rol',
            'nombre' => 'Rol caja R8 '.$token,
        ]);
        $userId = $this->createUser($roleId, [
            'nombre_usuario' => 'r8_'.$token.'_usuario',
            'correo' => null,
        ]);
        foreach ($permissions as $permission) {
            $code = $permission instanceof PermissionCode ? $permission->value : $permission;
            $permissionId = $this->createPermission([
                'codigo' => $code,
                'nombre' => 'Permiso caja R8 '.$code,
                'modulo' => explode('.', $code, 2)[0],
            ]);
            $this->attachPermission($roleId, $permissionId);
        }

        return User::query()->findOrFail($userId);
    }

    protected function createPostingContext(User $actor, bool $affectsCash): array
    {
        $token = $this->cashPostingToken($affectsCash ? 'efectivo' : 'no_efectivo');
        $categoryId = $this->createArticleCategory([
            'codigo' => 'r8_'.$token.'_cat',
            'nombre' => 'Categoria caja R8 '.$token,
        ]);
        $unitId = $this->createUnitOfMeasure([
            'codigo' => 'r8u'.$this->cashPostingFixtureSequence,
            'nombre' => 'Unidad caja R8 '.$token,
            'simbolo' => 'u'.$this->cashPostingFixtureSequence,
            'magnitud' => 'unidad',
        ]);
        $articleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'r8_'.$token,
            'nombre' => 'Articulo caja R8 '.$token,
            'tipo_articulo' => 'elaborado',
            'precio_venta_actual' => '4.00',
            'stock_actual' => '10.000',
            'activo' => true,
        ]);
        $paymentMethodId = $this->createPaymentMethod([
            'codigo' => 'r8_'.$token.'_metodo',
            'nombre' => 'Metodo caja R8 '.$token,
            'afecta_efectivo' => $affectsCash,
        ]);
        $cashSessionId = $this->createCashSession(
            $this->createCashRegister([
                'codigo' => 'r8_'.$token.'_caja',
                'nombre' => 'Caja caja R8 '.$token,
            ]),
            (int) $actor->getKey(),
        );
        $clientId = $this->createClient(['nombre' => 'Cliente caja R8 '.$token]);

        $unitSymbol = 'u'.$this->cashPostingFixtureSequence;

        return compact('articleId', 'paymentMethodId', 'cashSessionId', 'clientId', 'unitSymbol');
    }

    protected function createHistoricalCashSale(User $actor, array $context): array
    {
        $saleId = $this->createSale(
            (int) $actor->getKey(),
            $context['cashSessionId'],
            $this->cashPostingUuid(1),
            [
                'fecha_comercial' => '2026-09-24',
                'numero_diario' => 1,
                'cliente_id' => $context['clientId'],
                'total' => '4.00',
                'completada_en' => '2026-09-24 12:00:00-03',
            ],
        );
        $detailId = $this->createSaleDetail($saleId, $context['articleId'], [
            'articulo_sku_snapshot' => 'r8_articulo',
            'articulo_nombre_snapshot' => 'Articulo caja R8',
            'unidad_codigo_snapshot' => 'r8u1',
            'unidad_simbolo_snapshot' => $context['unitSymbol'],
            'cantidad' => '1.000',
            'precio_unitario' => '4.00',
            'subtotal' => '4.00',
        ]);
        $paymentId = $this->createSalePayment(
            $saleId,
            $context['paymentMethodId'],
            $context['cashSessionId'],
            (int) $actor->getKey(),
            $this->cashPostingUuid(2),
            [
                'monto' => '4.00',
                'ocurrido_en' => '2026-09-24 12:00:00-03',
            ],
        );
        $movementId = $this->createInventoryMovement(
            $context['articleId'],
            (int) $actor->getKey(),
            'venta',
            $this->cashPostingUuid(3),
            [
                'cantidad_delta' => '-1.000',
                'stock_anterior' => '10.000',
                'stock_posterior' => '9.000',
                'detalle_venta_id' => $detailId,
                'articulo_sku_snapshot' => 'r8_articulo',
                'articulo_nombre_snapshot' => 'Articulo caja R8',
                'unidad_codigo_snapshot' => 'r8u1',
                'unidad_simbolo_snapshot' => $context['unitSymbol'],
                'ocurrido_en' => '2026-09-24 12:00:00-03',
            ],
        );
        DB::table('articulos')->where('id', $context['articleId'])->update(['stock_actual' => '9.000']);
        $this->createStockDaily($context['articleId'], [
            'fecha_comercial' => '2026-09-24',
            'stock_apertura' => '10.000',
            'total_entradas' => '0.000',
            'total_salidas' => '1.000',
            'stock_cierre' => '9.000',
            'ultimo_movimiento_id' => $movementId,
        ]);

        return compact('saleId', 'paymentId');
    }

    protected function createPendingOrderWithPayment(User $actor, array $context): array
    {
        $orderId = $this->createOrder(
            $context['clientId'],
            (int) $actor->getKey(),
            [
                'estado' => 'pendiente',
                'clave_idempotencia' => $this->cashPostingUuid(4),
            ],
        );
        $paymentId = $this->createOrderPayment(
            $orderId,
            $context['paymentMethodId'],
            $context['cashSessionId'],
            (int) $actor->getKey(),
            $this->cashPostingUuid(5),
            ['monto' => '4.00'],
        );

        return compact('orderId', 'paymentId');
    }

    protected function cashPostingUuid(int $number): string
    {
        return 'd8400000-0000-4000-8000-'.str_pad((string) $number, 12, '0', STR_PAD_LEFT);
    }

    private function cashPostingToken(string $prefix): string
    {
        $this->cashPostingFixtureSequence++;

        return strtolower($prefix.'_'.$this->cashPostingFixtureSequence);
    }
}
