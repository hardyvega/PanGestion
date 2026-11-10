<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PermisosBloqueOnceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = $this->catalog();
            $codes = array_keys($catalog);
            $existing = DB::table('permisos')
                ->whereIn('codigo', $codes)
                ->orderBy('codigo')
                ->lockForUpdate()
                ->get(['id', 'codigo', 'modulo'])
                ->keyBy('codigo');

            $this->assertCompatibleModules($existing, $catalog);

            $missingRows = [];

            foreach ($catalog as $code => $metadata) {
                if ($existing->has($code)) {
                    continue;
                }

                $missingRows[] = [
                    'codigo' => $code,
                    'nombre' => $metadata['label'],
                    'modulo' => $metadata['module'],
                    'descripcion' => $metadata['description'],
                    'activo' => true,
                ];
            }

            if ($missingRows !== []) {
                DB::table('permisos')->insertOrIgnore($missingRows);
            }

            $final = DB::table('permisos')
                ->whereIn('codigo', $codes)
                ->orderBy('codigo')
                ->lockForUpdate()
                ->get(['id', 'codigo', 'modulo'])
                ->keyBy('codigo');

            if ($final->count() !== count($catalog)) {
                throw new RuntimeException('No fue posible completar el catalogo de permisos del Bloque 11.');
            }

            $this->assertCompatibleModules($final, $catalog);
        });
    }

    private function catalog(): array
    {
        $catalog = [];

        foreach ($this->historicalPermissions() as $permission) {
            $catalog[$permission->value] = [
                'module' => $permission->module(),
                'label' => $permission->label(),
                'description' => $permission->description(),
            ];
        }

        return $catalog;
    }

    /** @return list<PermissionCode> */
    private function historicalPermissions(): array
    {
        return array_values(array_filter(
            PermissionCode::cases(),
            static fn (PermissionCode $permission): bool => ! in_array($permission, [
                PermissionCode::CashFundsAuthorize,
                PermissionCode::PersonnelView,
                PermissionCode::PersonnelManage,
                PermissionCode::HourlyRatesView,
                PermissionCode::HourlyRatesManage,
                PermissionCode::HourlyPayView,
                PermissionCode::AttendanceMark,
                PermissionCode::AttendanceManage,
                PermissionCode::UsersView,
                PermissionCode::UsersManage,
                PermissionCode::RolesView,
                PermissionCode::RolesManage,
                PermissionCode::PermissionsView,
                PermissionCode::RolePermissionsManage,
            ], true),
        ));
    }

    private function assertCompatibleModules(iterable $permissions, array $catalog): void
    {
        foreach ($permissions as $code => $permission) {
            if ($permission->modulo !== $catalog[$code]['module']) {
                throw new RuntimeException(
                    "El permiso [{$code}] tiene un modulo incompatible con el catalogo del Bloque 11."
                );
            }
        }
    }
}
