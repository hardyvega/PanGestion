<?php

declare(strict_types=1);

namespace Tests\Support\Cash;

use App\Enums\PermissionCode;
use App\Models\FondoCajaAutorizado;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Cash\CashFundService;
use App\Services\Cash\CashService;
use App\Support\CommercialDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait DailyCashFixtures
{
    protected function dailyActor(array $permissions): User
    {
        $token = strtolower(Str::random(12));
        $role = DB::table('roles')->insertGetId(['codigo' => 'daily_'.$token, 'nombre' => 'Operador '.$token, 'activo' => true]);
        foreach ($permissions as $code) {
            $id = DB::table('permisos')->where('codigo', $code->value)->value('id');
            if ($id === null) {
                $id = DB::table('permisos')->insertGetId(['codigo' => $code->value, 'nombre' => $code->label(), 'modulo' => $code->module(), 'activo' => true]);
            }
            DB::table('rol_permiso')->insert(['rol_id' => $role, 'permiso_id' => $id]);
        }
        $id = DB::table('usuarios')->insertGetId(['rol_id' => $role, 'nombre' => 'Persona de prueba', 'apellido' => $token, 'nombre_usuario' => 'daily_'.$token, 'password' => 'unused-test-placeholder', 'activo' => true]);

        return User::query()->findOrFail($id);
    }

    protected function dailyRegister(): int
    {
        $token = strtolower(Str::random(12));

        return (int) DB::table('cajas')->insertGetId(['codigo' => 'daily_'.$token, 'nombre' => 'Caja '.$token, 'activo' => true]);
    }

    protected function dailyFund(int $register, string $amount = '140000', ?User $owner = null, ?string $date = null): FondoCajaAutorizado
    {
        $owner ??= $this->dailyActor([PermissionCode::CashFundsAuthorize]);

        return app(CashFundService::class)->authorizeInitial($owner, $register, $date ?? CommercialDate::today(), $amount, 'Configuración inicial', (string) Str::uuid());
    }

    protected function dailyOpen(User $actor, int $register, string $amount = '140000'): SesionCaja
    {
        $fund = $this->dailyFund($register, $amount);

        return app(CashService::class)->open($actor, $register, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
    }

    protected function dailyClose(User $owner, SesionCaja $session, string $counted = '140000', string $fund = '50000', ?string $observation = null, ?string $uuid = null): SesionCaja
    {
        return app(CashService::class)->close($owner, (int) $session->id, $counted, $fund, $session->fecha_operativa->addDay()->format('Y-m-d'), $uuid ?? (string) Str::uuid(), true, $observation);
    }
}
