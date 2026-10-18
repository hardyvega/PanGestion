<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PermisosBloqueDoceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = [
                PermissionCode::PersonnelView,
                PermissionCode::PersonnelManage,
                PermissionCode::HourlyRatesView,
                PermissionCode::HourlyRatesManage,
                PermissionCode::HourlyPayView,
                PermissionCode::AttendanceMark,
                PermissionCode::AttendanceManage,
            ];
            $catalog = [];
            foreach ($permissions as $permission) {
                $catalog[$permission->value] = [
                    'module' => $permission->module(),
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                ];
            }

            $codes = array_keys($catalog);
            $existing = DB::table('permisos')->whereIn('codigo', $codes)->lockForUpdate()->get()->keyBy('codigo');
            foreach ($existing as $code => $permission) {
                if ($permission->modulo !== $catalog[$code]['module']) {
                    throw new RuntimeException("El permiso [{$code}] tiene un modulo incompatible.");
                }
            }

            $missing = [];
            foreach ($catalog as $code => $metadata) {
                if (! $existing->has($code)) {
                    $missing[] = [
                        'codigo' => $code,
                        'nombre' => $metadata['label'],
                        'modulo' => $metadata['module'],
                        'descripcion' => $metadata['description'],
                        'activo' => true,
                    ];
                }
            }
            if ($missing !== []) {
                DB::table('permisos')->insertOrIgnore($missing);
            }
            if (DB::table('permisos')->whereIn('codigo', $codes)->count() !== count($codes)) {
                throw new RuntimeException('No fue posible completar el catalogo de permisos del Bloque 12.');
            }
        });
    }
}
