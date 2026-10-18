<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CashWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_workflow_is_available_with_effective_permissions(): void
    {
        $user = $this->userWithPermissions([
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashMovementsView,
            PermissionCode::CashManualMovementsManage,
        ]);
        $registerId = $this->register();

        $this->actingAs($user)->get(route('cash.index'))->assertOk()->assertSee('Abrir sesión de caja');
        $this->actingAs($user)->post(route('cash.sessions.store'), [
            'caja_id' => $registerId,
            'monto_inicial' => '10000.00',
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
