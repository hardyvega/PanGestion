<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Cash\CashFundService;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\TestCase;

final class CashWebTest extends TestCase
{
    use DailyCashFixtures;
    use RefreshDatabase;

    public function test_cash_workflow_is_available_with_effective_permissions(): void
    {
        $user = $this->userWithPermissions([
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashFundsAuthorize,
            PermissionCode::CashMovementsView,
            PermissionCode::CashManualMovementsManage,
        ]);
        $registerId = $this->register();
        $fund = app(CashFundService::class)->authorizeInitial($user, $registerId, CommercialDate::today(), '10000', 'Configuración inicial', (string) Str::uuid());

        $this->actingAs($user)->get(route('cash.index'))->assertOk()->assertSee('Abrir sesión de caja');
        $this->actingAs($user)->post(route('cash.sessions.store'), [
            'caja_id' => $registerId,
            'fondo_autorizado_id' => $fund->id,
            'fecha_operativa' => CommercialDate::today(),
            'clave_idempotencia' => (string) Str::uuid(),
        ])->assertRedirect();

        $sessionId = (int) DB::table('sesiones_caja')->value('id');
        $this->actingAs($user)->post(route('cash.movements.store', $sessionId), [
            'tipo_movimiento' => 'ingreso',
            'monto' => '2000.00',
            'concepto' => 'Ingreso web',
        ])->assertRedirect();
        $this->assertDatabaseHas('movimientos_caja', [
            'sesion_caja_id' => $sessionId,
            'pago_venta_id' => null,
            'pago_pedido_id' => null,
            'monto' => '2000.00',
        ]);

        $this->actingAs($user)->post(route('cash.sessions.close', $sessionId), [
            'monto_declarado' => '12000.00',
            'observacion' => 'Cierre correcto',
            'fondo_siguiente' => '12000.00',
            'fecha_siguiente' => CarbonImmutable::parse(CommercialDate::today())->addDay()->format('Y-m-d'),
            'clave_idempotencia' => (string) Str::uuid(),
            'confirmacion' => '1',
            'esperado_revisado' => '12000.00',
        ])->assertRedirect();

        $this->assertDatabaseHas('sesiones_caja', [
            'id' => $sessionId,
            'monto_teorico_cierre' => '12000.00',
            'monto_cierre_declarado' => '12000.00',
        ]);
        $this->actingAs($user)->get(route('cash.movements'))->assertOk()->assertSee('Ingreso web');
    }

    public function test_cash_endpoints_require_their_effective_permission(): void
    {
        $user = $this->userWithPermissions([PermissionCode::CashSessionsView]);

        $this->actingAs($user)->get(route('cash.index'))->assertOk();
        $this->actingAs($user)->get(route('cash.movements'))->assertForbidden();
        $this->actingAs($user)->post(route('cash.sessions.store'), [
            'caja_id' => $this->register(),
            'monto_inicial' => '1.00',
        ])->assertForbidden();
    }

    public function test_cash_movement_filter_keeps_the_last_second_of_the_santiago_day(): void
    {
        $user = $this->dailyActor([
            PermissionCode::CashSessionsOpen, PermissionCode::CashMovementsView, PermissionCode::CashManualMovementsManage,
        ]);
        $this->actingAs($user);
        foreach ([
            ['2026-07-15 03:59:59 UTC', 'Último movimiento del martes'],
            ['2026-07-15 04:00:00 UTC', 'Primer movimiento del miércoles'],
        ] as [$instant, $concept]) {
            $this->travelTo(CarbonImmutable::parse($instant));
            $session = $this->dailyOpen($user, $this->dailyRegister());
            $this->post(route('cash.movements.store', $session), [
                'tipo_movimiento' => 'ingreso', 'monto' => '1000.00', 'concepto' => $concept,
            ])->assertSessionHasNoErrors();
        }

        $this->get(route('cash.movements', ['fecha_desde' => '2026-07-14', 'fecha_hasta' => '2026-07-14']))
            ->assertOk()->assertSee('Último movimiento del martes')->assertDontSee('Primer movimiento del miércoles');
        $this->assertDatabaseCount('movimientos_caja', 2);
    }

    /** @param list<PermissionCode> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'web_caja_'.uniqid(),
            'nombre' => 'Web caja',
            'descripcion' => null,
            'activo' => true,
        ]);
        $userId = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Web',
            'apellido' => 'Caja',
            'nombre_usuario' => 'web_'.uniqid(),
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

    private function register(): int
    {
        return (int) DB::table('cajas')->insertGetId([
            'codigo' => 'web_caja_'.uniqid(),
            'nombre' => 'Caja web '.uniqid(),
            'descripcion' => null,
            'activo' => true,
        ]);
    }
}
