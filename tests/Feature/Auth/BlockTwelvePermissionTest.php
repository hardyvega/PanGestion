<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use Database\Seeders\PermisosBloqueDoceSeeder;
use Database\Seeders\PermisosBloqueOnceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BlockTwelvePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_b12_adds_exactly_seven_permissions_without_role_assignment(): void
    {
        $this->seed([PermisosBloqueOnceSeeder::class, PermisosBloqueDoceSeeder::class]);

        $codes = [
            'personal.ver',
            'personal.gestionar',
            'personal.tarifas.ver',
            'personal.tarifas.gestionar',
            'personal.pago_hora.ver',
            'asistencia.marcar',
            'asistencia.gestionar',
        ];

        $this->assertSame(7, DB::table('permisos')->whereIn('codigo', $codes)->count());
        $this->assertSame(49, DB::table('permisos')->count());
        $this->assertSame(7, count(array_filter(
            PermissionCode::cases(),
            static fn (PermissionCode $permission): bool => in_array($permission->value, $codes, true),
        )));
        $this->assertSame(0, DB::table('rol_permiso')
            ->whereIn('permiso_id', DB::table('permisos')->whereIn('codigo', $codes)->pluck('id'))
            ->count());
    }

    public function test_b12_permission_levels_are_two_ordinary_and_five_elevated(): void
    {
        $permissions = [
            PermissionCode::PersonnelView,
            PermissionCode::PersonnelManage,
            PermissionCode::HourlyRatesView,
            PermissionCode::HourlyRatesManage,
            PermissionCode::HourlyPayView,
            PermissionCode::AttendanceMark,
            PermissionCode::AttendanceManage,
        ];

        $this->assertSame(['ordinario', 'elevado', 'elevado', 'elevado', 'elevado', 'ordinario', 'elevado'], array_map(
            static fn (PermissionCode $permission): string => $permission->level(),
            $permissions,
        ));
    }
}
