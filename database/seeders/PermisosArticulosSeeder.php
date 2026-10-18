<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PermisosArticulosSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $catalog = [];

            foreach ([PermissionCode::ArticlesView, PermissionCode::ArticlesManage] as $permission) {
                $catalog[$permission->value] = [
                    'module' => $permission->module(),
                    'label' => $permission->label(),
                    'description' => $permission->description(),
                ];
            }

            $codes = array_keys($catalog);
            $existing = DB::table('permisos')
                ->whereIn('codigo', $codes)
                ->lockForUpdate()
                ->get(['id', 'codigo', 'modulo', 'activo'])
                ->keyBy('codigo');

            foreach ($existing as $code => $permission) {
                if ($permission->modulo !== $catalog[$code]['module'] || ! $permission->activo) {
                    throw new RuntimeException("El permiso [$code] tiene una configuracion incompatible.");
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

            $permissions = DB::table('permisos')
                ->whereIn('codigo', $codes)
                ->where('activo', true)
                ->pluck('id', 'codigo');

            if ($permissions->count() !== count($catalog)) {
                throw new RuntimeException('No fue posible completar el catalogo de permisos de articulos.');
            }

            $administrator = DB::table('roles')
                ->whereRaw('lower(codigo) = ?', ['administrador'])
                ->lockForUpdate()
                ->first(['id']);

            if ($administrator !== null) {
                $assignments = [];
                foreach ($permissions as $permissionId) {
                    $assignments[] = [
                        'rol_id' => (int) $administrator->id,
                        'permiso_id' => (int) $permissionId,
                    ];
                }
                DB::table('rol_permiso')->insertOrIgnore($assignments);
            }
        });
    }
}
