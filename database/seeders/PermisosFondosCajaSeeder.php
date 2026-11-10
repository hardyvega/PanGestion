<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PermisosFondosCajaSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $code = PermissionCode::CashFundsAuthorize;
            DB::table('permisos')->insertOrIgnore([
                'codigo' => $code->value, 'nombre' => $code->label(),
                'descripcion' => $code->description(), 'modulo' => $code->module(), 'activo' => true,
            ]);
            $permission = DB::table('permisos')->where('codigo', $code->value)->lockForUpdate()->first();
            if ($permission === null || $permission->modulo !== $code->module()) {
                throw new RuntimeException('El permiso de fondos de caja tiene una configuracion incompatible.');
            }
            // Installation only: existing assignments are deliberately untouched.
        });
    }
}
