<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Data\Attendance\AnnulAttendanceCommand;
use App\Data\Attendance\CloseAttendanceCommand;
use App\Data\Attendance\RecordManualAttendanceCommand;
use App\Data\Attendance\StartAttendanceCommand;
use App\Enums\AttendanceStatus;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\JornadaTrabajo;
use App\Models\TarifaHoraTrabajador;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class AttendanceService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function start(User $actor, StartAttendanceCommand $command): JornadaTrabajo
    {
        return DB::transaction(function () use ($actor, $command): JornadaTrabajo {
            $manager = $this->canManage($actor);
            if (! $manager) {
                $this->permissions->authorize($actor, PermissionCode::AttendanceMark);
            }
            $worker = $this->lockWorker($command->workerId);
            if (! $manager && (int) $worker->usuario_id !== (int) $actor->getKey()) {
                throw new InventoryDomainException('El usuario solo puede marcar su propia asistencia.');
            }
            $startedAt = CarbonImmutable::now('UTC');
            $existing = JornadaTrabajo::query()
                ->where('clave_idempotencia_inicio', $command->idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertCompatibleStart($existing, $worker, $actor, $startedAt);
                return $existing;
            }
            $this->assertCanStart($worker, $startedAt);
            if (DB::table('jornadas_trabajo')->where('trabajador_id', $worker->getKey())
                ->where('estado', AttendanceStatus::Open->value)->exists()) {
                throw new InventoryDomainException('El trabajador ya tiene una jornada abierta.');
            }
            $rate = $this->effectiveRate($worker->getKey(), $startedAt);

            return $this->createAttendance([
                'trabajador_id' => $worker->getKey(),
                'tarifa_hora_trabajador_id' => $rate->getKey(),
                'estado' => AttendanceStatus::Open->value,
                'iniciada_en' => $startedAt,
                'finalizada_en' => null,
                'valor_hora_snapshot' => $rate->valor_hora,
                'minutos_trabajados' => null,
                'monto_calculado' => null,
                'usuario_inicio_id' => $actor->getKey(),
                'usuario_cierre_id' => null,
                'anulada_en' => null,
                'usuario_anulador_id' => null,
                'motivo_anulacion' => null,
                'clave_idempotencia_inicio' => $command->idempotencyKey,
                'clave_idempotencia_cierre' => null,
                'clave_idempotencia_anulacion' => null,
            ]);
        });
    }

    public function close(User $actor, CloseAttendanceCommand $command): JornadaTrabajo
    {
        return DB::transaction(function () use ($actor, $command): JornadaTrabajo {
            $manager = $this->canManage($actor);
            if (! $manager) {
                $this->permissions->authorize($actor, PermissionCode::AttendanceMark);
            }
            $workerId = DB::table('jornadas_trabajo')->where('id', $command->attendanceId)->value('trabajador_id');
            if ($workerId === null) {
                throw (new ModelNotFoundException())->setModel(JornadaTrabajo::class, [$command->attendanceId]);
            }
            $worker = $this->lockWorker((int) $workerId);
            $attendance = JornadaTrabajo::query()->whereKey($command->attendanceId)->lockForUpdate()->firstOrFail();
            if (! $manager && (int) $worker->usuario_id !== (int) $actor->getKey()) {
                throw new InventoryDomainException('El usuario solo puede cerrar su propia asistencia.');
            }
            if (! $manager && $command->finishedAt !== null) {
                throw new InventoryDomainException('La marcacion propia no admite una hora manual.');
            }
            $existing = JornadaTrabajo::query()
                ->where('clave_idempotencia_cierre', $command->idempotencyKey)
                ->lockForUpdate()->first();
            $finishedAt = $command->finishedAt === null
                ? CarbonImmutable::now('UTC')
                : CarbonImmutable::instance($command->finishedAt)->setTimezone('UTC');
            if ($existing !== null) {
                $this->assertCompatibleClose($existing, $attendance, $actor, $finishedAt);
                return $existing;
            }
            if ($attendance->estado !== AttendanceStatus::Open) {
                throw new InventoryDomainException('Solo se puede cerrar una jornada abierta.');
            }
            [$minutes, $amount] = $this->durationAndAmount($attendance, $finishedAt);
            $attendance->forceFill([
                'estado' => AttendanceStatus::Closed->value,
                'finalizada_en' => $finishedAt,
                'minutos_trabajados' => $minutes,
                'monto_calculado' => $amount,
                'usuario_cierre_id' => $actor->getKey(),
                'clave_idempotencia_cierre' => $command->idempotencyKey,
            ])->save();

            return $attendance->fresh();
        });
    }

    public function recordManual(User $actor, RecordManualAttendanceCommand $command): JornadaTrabajo
    {
        $this->permissions->authorize($actor, PermissionCode::AttendanceManage);

        return DB::transaction(function () use ($actor, $command): JornadaTrabajo {
            $worker = $this->lockWorker($command->workerId);
            $startedAt = CarbonImmutable::instance($command->startedAt)->setTimezone('UTC');
            $finishedAt = CarbonImmutable::instance($command->finishedAt)->setTimezone('UTC');
            $existing = JornadaTrabajo::query()
                ->where('clave_idempotencia_inicio', $command->startIdempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertCompatibleManual($existing, $worker, $actor, $command, $startedAt, $finishedAt);
                return $existing;
            }
            if ($startedAt->isFuture() || $finishedAt->isFuture()) {
                throw new InventoryDomainException('La asistencia manual no puede quedar en el futuro.');
            }
            $this->assertValidWorkDate($worker, $startedAt);
            $this->assertNoOverlap($worker->getKey(), $startedAt, $finishedAt);
            $closeConflict = JornadaTrabajo::query()
                ->where('clave_idempotencia_cierre', $command->closeIdempotencyKey)->exists();
            if ($closeConflict) {
                throw new IdempotencyConflictException('La clave de cierre manual ya fue usada.');
            }
            $rate = $this->effectiveRate($worker->getKey(), $startedAt);
            [$minutes, $amount] = $this->durationAndAmountFor($startedAt, $finishedAt, $rate->valor_hora);

            return $this->createAttendance([
                'trabajador_id' => $worker->getKey(),
                'tarifa_hora_trabajador_id' => $rate->getKey(),
                'estado' => AttendanceStatus::Closed->value,
                'iniciada_en' => $startedAt,
                'finalizada_en' => $finishedAt,
                'valor_hora_snapshot' => $rate->valor_hora,
                'minutos_trabajados' => $minutes,
                'monto_calculado' => $amount,
                'usuario_inicio_id' => $actor->getKey(),
                'usuario_cierre_id' => $actor->getKey(),
                'anulada_en' => null,
                'usuario_anulador_id' => null,
                'motivo_anulacion' => null,
                'clave_idempotencia_inicio' => $command->startIdempotencyKey,
                'clave_idempotencia_cierre' => $command->closeIdempotencyKey,
                'clave_idempotencia_anulacion' => null,
            ]);
        });
    }

    public function annul(User $actor, AnnulAttendanceCommand $command): JornadaTrabajo
    {
        $this->permissions->authorize($actor, PermissionCode::AttendanceManage);

        return DB::transaction(function () use ($actor, $command): JornadaTrabajo {
            $workerId = DB::table('jornadas_trabajo')->where('id', $command->attendanceId)->value('trabajador_id');
            if ($workerId === null) {
                throw (new ModelNotFoundException())->setModel(JornadaTrabajo::class, [$command->attendanceId]);
            }
            $this->lockWorker((int) $workerId);
            $attendance = JornadaTrabajo::query()->whereKey($command->attendanceId)->lockForUpdate()->firstOrFail();
            $existing = JornadaTrabajo::query()
                ->where('clave_idempotencia_anulacion', $command->idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->getKey() !== $command->attendanceId
                    || (int) $existing->usuario_anulador_id !== (int) $actor->getKey()
                    || $existing->motivo_anulacion !== $command->reason) {
                    throw new IdempotencyConflictException('La clave de anulacion ya fue usada con otra intencion.');
                }
                return $existing;
            }
            if ($attendance->estado === AttendanceStatus::Annulled) {
                throw new InventoryDomainException('La jornada ya esta anulada.');
            }
            $attendance->forceFill([
                'estado' => AttendanceStatus::Annulled->value,
                'anulada_en' => CarbonImmutable::now('UTC'),
                'usuario_anulador_id' => $actor->getKey(),
                'motivo_anulacion' => $command->reason,
                'clave_idempotencia_anulacion' => $command->idempotencyKey,
            ])->save();

            return $attendance->fresh();
        });
    }

    private function canManage(User $actor): bool
    {
        return $this->permissions->allows($actor, PermissionCode::AttendanceManage);
    }

    private function lockWorker(int $workerId): Trabajador
    {
        $row = DB::table('trabajadores')->where('id', $workerId)->lockForUpdate()->first();
        if ($row === null) {
            throw (new ModelNotFoundException())->setModel(Trabajador::class, [$workerId]);
        }

        return Trabajador::query()->findOrFail($workerId);
    }

    private function createAttendance(array $attributes): JornadaTrabajo
    {
        $attendance = new JornadaTrabajo();
        $attendance->forceFill($attributes);
        $attendance->save();

        return $attendance->fresh();
    }

    private function assertCanStart(Trabajador $worker, CarbonImmutable $startedAt): void
    {
        if (! $worker->activo) {
            throw new InventoryDomainException('Un trabajador inactivo no puede iniciar una jornada.');
        }
        $this->assertValidWorkDate($worker, $startedAt);
    }

    private function assertValidWorkDate(Trabajador $worker, CarbonImmutable $startedAt): void
    {
        $localDate = CommercialDate::fromInstant($startedAt);
        if ($localDate < $worker->fecha_ingreso->format('Y-m-d')
            || ($worker->fecha_termino !== null && $localDate > $worker->fecha_termino->format('Y-m-d'))) {
            throw new InventoryDomainException('La jornada queda fuera del periodo laboral del trabajador.');
        }
    }

    private function effectiveRate(int $workerId, CarbonImmutable $startedAt): TarifaHoraTrabajador
    {
        $rate = TarifaHoraTrabajador::query()
            ->where('trabajador_id', $workerId)
            ->where('vigente_desde', '<=', $startedAt)
            ->orderByDesc('vigente_desde')->orderByDesc('id')->first();
        if ($rate === null) {
            throw new InventoryDomainException('No existe una tarifa vigente para la jornada.');
        }

        return $rate;
    }

    private function assertNoOverlap(int $workerId, CarbonImmutable $startedAt, CarbonImmutable $finishedAt): void
    {
        $overlap = DB::table('jornadas_trabajo')
            ->where('trabajador_id', $workerId)
            ->where('estado', '<>', AttendanceStatus::Annulled->value)
            ->where('iniciada_en', '<', $finishedAt)
            ->where(static function ($query) use ($startedAt): void {
                $query->whereNull('finalizada_en')->orWhere('finalizada_en', '>', $startedAt);
            })->exists();
        if ($overlap) {
            throw new InventoryDomainException('La jornada se superpone con otra no anulada.');
        }
    }

    private function durationAndAmount(JornadaTrabajo $attendance, CarbonImmutable $finishedAt): array
    {
        return $this->durationAndAmountFor(
            CarbonImmutable::instance($attendance->iniciada_en)->setTimezone('UTC'),
            $finishedAt,
            (string) $attendance->valor_hora_snapshot,
        );
    }

    private function durationAndAmountFor(CarbonImmutable $startedAt, CarbonImmutable $finishedAt, string $hourlyRate): array
    {
        $seconds = $finishedAt->getTimestamp() - $startedAt->getTimestamp();
        if ($seconds < 0 || $seconds > 86400) {
            throw new InventoryDomainException('La jornada debe durar entre cero y veinticuatro horas.');
        }
        $minutes = intdiv($seconds, 60);
        $product = Decimal::multiply(Decimal::money($hourlyRate), (string) $minutes, 4);
        $amount = Decimal::round(bcdiv($product, '60', 4), 2);

        return [$minutes, $amount];
    }

    private function assertCompatibleStart(JornadaTrabajo $existing, Trabajador $worker, User $actor, CarbonImmutable $startedAt): void
    {
        if ((int) $existing->trabajador_id !== (int) $worker->getKey()
            || (int) $existing->usuario_inicio_id !== (int) $actor->getKey()
            || $existing->estado !== AttendanceStatus::Open) {
            throw new IdempotencyConflictException('La clave de inicio ya fue usada con otra intencion.');
        }
    }

    private function assertCompatibleClose(JornadaTrabajo $existing, JornadaTrabajo $attendance, User $actor, CarbonImmutable $finishedAt): void
    {
        if ((int) $existing->getKey() !== (int) $attendance->getKey()
            || (int) $existing->usuario_cierre_id !== (int) $actor->getKey()
            || $existing->estado !== AttendanceStatus::Closed
            || ! $this->sameInstant($existing->finalizada_en, $finishedAt)) {
            throw new IdempotencyConflictException('La clave de cierre ya fue usada con otra intencion.');
        }
    }

    private function assertCompatibleManual(JornadaTrabajo $existing, Trabajador $worker, User $actor, RecordManualAttendanceCommand $command, CarbonImmutable $startedAt, CarbonImmutable $finishedAt): void
    {
        if ((int) $existing->trabajador_id !== (int) $worker->getKey()
            || (int) $existing->usuario_inicio_id !== (int) $actor->getKey()
            || $existing->clave_idempotencia_cierre !== $command->closeIdempotencyKey
            || ! $this->sameInstant($existing->iniciada_en, $startedAt)
            || ! $this->sameInstant($existing->finalizada_en, $finishedAt)) {
            throw new IdempotencyConflictException('La clave de inicio manual ya fue usada con otra intencion.');
        }
    }

    private function sameInstant(?DateTimeInterface $left, ?DateTimeInterface $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return CarbonImmutable::instance($left)->setTimezone('UTC')->format('Y-m-d H:i:s.uP')
            === CarbonImmutable::instance($right)->setTimezone('UTC')->format('Y-m-d H:i:s.uP');
    }
}
