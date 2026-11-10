<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Cash;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Cash\CashService;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class CashServiceTest extends TestCase
{
    use RefreshDatabase;

    private function openSession(User $actor, int $registerId, string $amount): SesionCaja
    {
        $fundId = DB::table('fondos_caja_autorizados')->where('caja_id', $registerId)->value('id');
        if ($fundId === null) {
            $fundId = DB::table('fondos_caja_autorizados')->insertGetId(['caja_id' => $registerId, 'fecha_operativa' => CommercialDate::today(), 'monto' => $amount, 'usuario_autorizante_id' => $actor->id, 'origen' => 'inicial', 'motivo' => 'Fixture autorizada', 'clave_idempotencia' => (string) Str::uuid(), 'created_at' => now('UTC')]);
        }

        return app(CashService::class)->open($actor, $registerId, (int) $fundId, CommercialDate::today(), (string) Str::uuid());
    }

    private function closeSession(User $actor, int $sessionId, string $amount, ?string $reason = null): SesionCaja
    {
        return app(CashService::class)->close($actor, $sessionId, $amount, $amount, CarbonImmutable::parse(CommercialDate::today())->addDay()->format('Y-m-d'), (string) Str::uuid(), true, $reason);
    }

    public function test_open_registers_exact_initial_amount_and_user(): void
    {
        $actor = $this->actor([
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsView,
        ]);
        $registerId = $this->register();

        $session = $this->openSession($actor, $registerId, '10000');

        $this->assertSame($registerId, $session->caja_id);
        $this->assertSame((int) $actor->getKey(), $session->usuario_apertura_id);
        $this->assertSame('10000.00', (string) DB::table('sesiones_caja')->where('id', $session->getKey())->value('monto_inicial'));
    }

    public function test_inactive_register_is_rejected(): void
    {
        $actor = $this->actor([PermissionCode::CashSessionsOpen]);
        $inactiveId = $this->register(false);

        $this->expectException(InventoryDomainException::class);
        $this->openSession($actor, $inactiveId, '1.00');
    }

    public function test_second_open_for_the_same_register_is_rejected(): void
    {
        $actor = $this->actor([PermissionCode::CashSessionsOpen]);
        $activeId = $this->register();
        $this->openSession($actor, $activeId, '1.00');

        $this->expectException(InventoryDomainException::class);
        $this->openSession($actor, $activeId, '2.00');
    }

    public function test_manual_movements_update_theoretical_amount_without_payment_causality(): void
    {
        $actor = $this->actor([
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsView,
            PermissionCode::CashManualMovementsManage,
        ]);
        $session = $this->openSession($actor, $this->register(), '10000');
        $cash = app(CashService::class);

        $cash->registerManualMovement($actor, (int) $session->getKey(), 'ingreso', '2000', 'Ingreso manual');
        $cash->registerManualMovement($actor, (int) $session->getKey(), 'egreso', '1000', 'Egreso manual');
        $cash->registerManualMovement($actor, (int) $session->getKey(), 'retiro', '3000', 'Retiro manual');

        $this->assertSame('8000.00', $cash->overview($actor, (int) $session->getKey())['theoretical']);
        $this->assertSame(3, DB::table('movimientos_caja')->count());
        $this->assertSame(3, DB::table('movimientos_caja')->whereNull('pago_venta_id')->whereNull('pago_pedido_id')->count());
    }

    public function test_closed_session_rejects_manual_movements(): void
    {
        $actor = $this->actor([
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashFundsAuthorize,
            PermissionCode::CashManualMovementsManage,
        ]);
        $session = $this->openSession($actor, $this->register(), '10000');
        $this->closeSession($actor, (int) $session->getKey(), '10000');

        $this->expectException(InventoryDomainException::class);
        app(CashService::class)->registerManualMovement($actor, (int) $session->getKey(), 'ingreso', '1.00', 'Tarde');
    }

    public function test_close_calculates_theoretical_amount_and_difference_on_server(): void
    {
        $actor = $this->actor([
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashFundsAuthorize,
            PermissionCode::CashManualMovementsManage,
        ]);
        $cash = app(CashService::class);
        $session = $this->openSession($actor, $this->register(), '10000');
        $cash->registerManualMovement($actor, (int) $session->getKey(), 'ingreso', '2000', 'Ingreso');
        $cash->registerManualMovement($actor, (int) $session->getKey(), 'egreso', '500', 'Egreso');

        $closed = $this->closeSession($actor, (int) $session->getKey(), '11200', 'Diferencia observada');
        $this->assertNotNull($closed->cerrada_en);
        $this->assertSame('11500.00', (string) DB::table('sesiones_caja')->where('id', $session->getKey())->value('monto_teorico_cierre'));
        $this->assertSame('11200.00', (string) DB::table('sesiones_caja')->where('id', $session->getKey())->value('monto_cierre_declarado'));
        $this->assertSame('-300.00', Decimal::subtract('11200.00', '11500.00', 2));

        $this->expectException(InventoryDomainException::class);
        $this->closeSession($actor, (int) $session->getKey(), '11500');
    }

    public function test_invalid_money_and_type_are_rejected_before_persistence(): void
    {
        $actor = $this->actor([
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashManualMovementsManage,
        ]);
        $session = $this->openSession($actor, $this->register(), '0');

        $this->expectException(InvalidArgumentException::class);
        app(CashService::class)->registerManualMovement($actor, (int) $session->getKey(), 'cobro', '0.00', 'No válido');
    }

    /** @param list<PermissionCode> $permissions */
    private function actor(array $permissions): User
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'caja_actor_'.uniqid(),
            'nombre' => 'Actor caja',
            'descripcion' => null,
            'activo' => true,
        ]);
        $userId = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Actor',
            'apellido' => 'Caja',
            'nombre_usuario' => 'actor_'.uniqid(),
            'correo' => null,
            'password' => Hash::make('Clave1234'),
            'activo' => true,
        ]);

        foreach ($permissions as $permission) {
            $permissionId = (int) DB::table('permisos')->insertGetId([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'descripcion' => $permission->description(),
                'activo' => true,
            ]);
            DB::table('rol_permiso')->insert(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        }

        return User::query()->findOrFail($userId);
    }

    private function register(bool $active = true): int
    {
        return (int) DB::table('cajas')->insertGetId([
            'codigo' => 'caja_'.uniqid(),
            'nombre' => 'Caja '.uniqid(),
            'descripcion' => null,
            'activo' => $active,
        ]);
    }
}
