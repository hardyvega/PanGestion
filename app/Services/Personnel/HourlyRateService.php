<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Data\Personnel\SetHourlyRateCommand;
use App\Enums\PermissionCode;
use App\Models\TarifaHoraTrabajador;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class HourlyRateService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function add(User $actor, SetHourlyRateCommand $command): TarifaHoraTrabajador
    {
        $this->permissions->authorize($actor, PermissionCode::HourlyRatesManage);

        return DB::transaction(function () use ($actor, $command): TarifaHoraTrabajador {
            $this->lockWorker($command->workerId);
            $rate = new TarifaHoraTrabajador();
            $rate->forceFill([
                'trabajador_id' => $command->workerId,
                'valor_hora' => $command->hourlyRate,
                'vigente_desde' => CarbonImmutable::instance($command->effectiveFrom)->setTimezone('UTC'),
                'registrado_por_usuario_id' => $actor->getKey(),
                'observacion' => $command->observation,
            ]);
            $rate->save();

            return $rate->fresh();
        });
    }

    public function set(User $actor, SetHourlyRateCommand $command): TarifaHoraTrabajador
    {
        return $this->add($actor, $command);
    }

    public function list(User $actor, int $workerId)
    {
        $this->permissions->authorize($actor, PermissionCode::HourlyRatesView);

        return TarifaHoraTrabajador::query()
            ->where('trabajador_id', $workerId)
            ->orderByDesc('vigente_desde')->orderByDesc('id')->get();
    }

    private function lockWorker(int $workerId): Trabajador
    {
        $row = DB::table('trabajadores')->where('id', $workerId)->lockForUpdate()->first();
        if ($row === null) {
            throw (new ModelNotFoundException())->setModel(Trabajador::class, [$workerId]);
        }

        return Trabajador::query()->findOrFail($workerId);
    }
}
