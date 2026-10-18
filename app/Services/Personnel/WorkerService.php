<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Data\Personnel\CreateWorkerCommand;
use App\Data\Personnel\UpdateWorkerCommand;
use App\Enums\PermissionCode;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WorkerService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function create(User $actor, CreateWorkerCommand $command): Trabajador
    {
        $this->permissions->authorize($actor, PermissionCode::PersonnelManage);

        return DB::transaction(function () use ($command): Trabajador {
            $this->assertDateRange($command->startDate, $command->endDate);
            $worker = new Trabajador();
            $worker->forceFill([
                'usuario_id' => $command->userId,
                'codigo' => $this->normalizeCode($command->code),
                'nombres' => $command->names,
                'apellidos' => $command->surnames,
                'activo' => true,
                'fecha_ingreso' => $command->startDate->format('Y-m-d'),
                'fecha_termino' => $command->endDate?->format('Y-m-d'),
            ]);
            $worker->save();

            return $worker->fresh();
        });
    }

    public function update(User $actor, UpdateWorkerCommand $command): Trabajador
    {
        $this->permissions->authorize($actor, PermissionCode::PersonnelManage);

        return DB::transaction(function () use ($command): Trabajador {
            $worker = $this->lockWorker($command->workerId);
            $this->assertDateRange($command->startDate, $command->endDate);
            $worker->forceFill([
                'usuario_id' => $command->userId,
                'codigo' => $this->normalizeCode($command->code),
                'nombres' => $command->names,
                'apellidos' => $command->surnames,
                'fecha_ingreso' => $command->startDate->format('Y-m-d'),
                'fecha_termino' => $command->endDate?->format('Y-m-d'),
            ]);
            $worker->save();

            return $worker->fresh();
        });
    }

    public function deactivate(User $actor, int $workerId): Trabajador
    {
        return $this->setActive($actor, $workerId, false);
    }

    public function reactivate(User $actor, int $workerId): Trabajador
    {
        return $this->setActive($actor, $workerId, true);
    }

    public function list(User $actor, bool $activeOnly = false)
    {
        $this->permissions->authorize($actor, PermissionCode::PersonnelView);

        return Trabajador::query()
            ->when($activeOnly, static fn ($query) => $query->where('activo', true))
            ->orderBy('apellidos')->orderBy('nombres')->orderBy('id')->get();
    }

    private function setActive(User $actor, int $workerId, bool $active): Trabajador
    {
        $this->permissions->authorize($actor, PermissionCode::PersonnelManage);

        return DB::transaction(function () use ($workerId, $active): Trabajador {
            $worker = $this->lockWorker($workerId);
            $worker->forceFill(['activo' => $active])->save();

            return $worker->fresh();
        });
    }

    private function lockWorker(int $workerId): Trabajador
    {
        if ($workerId < 1) {
            throw new InvalidArgumentException('El trabajador debe tener un ID positivo.');
        }

        $row = DB::table('trabajadores')->where('id', $workerId)->lockForUpdate()->first();
        if ($row === null) {
            throw (new ModelNotFoundException())->setModel(Trabajador::class, [$workerId]);
        }

        return Trabajador::query()->findOrFail($workerId);
    }

    private function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') {
            throw new InvalidArgumentException('El codigo del trabajador no puede estar vacio.');
        }

        return $normalized;
    }

    private function assertDateRange(\DateTimeInterface $start, ?\DateTimeInterface $end): void
    {
        if ($end !== null && $end->format('Y-m-d') < $start->format('Y-m-d')) {
            throw new InvalidArgumentException('La fecha de termino no puede ser anterior al ingreso.');
        }
    }
}
