<?php

declare(strict_types=1);

namespace Tests\Feature\Services\BlockTwelve;

use App\Data\Attendance\AnnulAttendanceCommand;
use App\Data\Attendance\CloseAttendanceCommand;
use App\Data\Attendance\RecordManualAttendanceCommand;
use App\Data\Attendance\StartAttendanceCommand;
use App\Data\Personnel\CreateWorkerCommand;
use App\Data\Personnel\SetHourlyRateCommand;
use App\Data\Reports\HourlyPayReportQuery;
use App\Enums\AttendanceStatus;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Trabajador;
use App\Services\Attendance\AttendanceService;
use App\Services\Personnel\HourlyRateService;
use App\Services\Personnel\WorkerService;
use App\Services\Reports\HourlyPayReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlockTwelveTestSupport;
use Tests\TestCase;

class WorkerAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;
    use BlockTwelveTestSupport;

    public function test_worker_rate_snapshot_manual_amount_and_report_are_operationally_consistent(): void
    {
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            ' panadero-01 ', 'Ana', 'Panadera', new CarbonImmutable('2026-01-01', 'UTC'), null,
        ));
        $this->assertSame('PANADERO-01', $worker->codigo);
        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '10.00', new CarbonImmutable('2026-01-01 00:00:00', 'UTC'), 'Tarifa base',
        ));

        $manual = app(AttendanceService::class)->recordManual($manager, new RecordManualAttendanceCommand(
            $worker->id,
            'b1200000-0000-4000-8000-000000000001',
            'b1200000-0000-4000-8000-000000000002',
            new CarbonImmutable('2026-10-10 10:00:00', 'UTC'),
            new CarbonImmutable('2026-10-10 11:30:00', 'UTC'),
        ));

        $this->assertSame(AttendanceStatus::Closed, $manual->estado);
        $this->assertSame(90, $manual->minutos_trabajados);
        $this->assertSame('15.00', $manual->monto_calculado);

        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '20.00', new CarbonImmutable('2026-10-11 00:00:00', 'UTC'), null,
        ));
        $report = app(HourlyPayReportService::class)->report($manager, new HourlyPayReportQuery(
            new CarbonImmutable('2026-10-10 00:00:00', 'UTC'),
            new CarbonImmutable('2026-10-11 00:00:00', 'UTC'),
        ));

        $this->assertSame(1, $report['jornadas']);
        $this->assertSame(90, $report['total_minutos']);
        $this->assertSame('15.00', $report['total_monto']);
        $this->assertSame('10.00', $report['items'][0]['valor_hora_snapshot']);
    }

    public function test_start_close_idempotency_and_inactive_start_policy(): void
    {
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            'P02', 'Luis', 'Turno', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '12.00', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        $command = new StartAttendanceCommand($worker->id, 'b1200000-0000-4000-8000-000000000003');
        $attendance = app(AttendanceService::class)->start($manager, $command);
        $retry = app(AttendanceService::class)->start($manager, $command);
        $this->assertSame($attendance->id, $retry->id);

        $closed = app(AttendanceService::class)->close($manager, new CloseAttendanceCommand(
            $attendance->id,
            'b1200000-0000-4000-8000-000000000004',
            CarbonImmutable::now('UTC'),
        ));
        $this->assertSame(AttendanceStatus::Closed, $closed->estado);
        $this->assertSame($closed->id, app(AttendanceService::class)->close($manager, new CloseAttendanceCommand(
            $attendance->id,
            'b1200000-0000-4000-8000-000000000004',
            $closed->finalizada_en,
        ))->id);

        app(WorkerService::class)->deactivate($manager, $worker->id);
        $this->expectException(InventoryDomainException::class);
        app(AttendanceService::class)->start($manager, new StartAttendanceCommand(
            $worker->id, 'b1200000-0000-4000-8000-000000000005',
        ));
    }

    public function test_manual_overlap_is_rejected_and_annulment_preserves_history(): void
    {
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            'P03', 'Marta', 'Turno', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '15.00', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        $attendance = app(AttendanceService::class)->recordManual($manager, new RecordManualAttendanceCommand(
            $worker->id,
            'b1200000-0000-4000-8000-000000000006',
            'b1200000-0000-4000-8000-000000000007',
            new CarbonImmutable('2026-10-12 10:00:00', 'UTC'),
            new CarbonImmutable('2026-10-12 12:00:00', 'UTC'),
        ));
        try {
            app(AttendanceService::class)->recordManual($manager, new RecordManualAttendanceCommand(
                $worker->id,
                'b1200000-0000-4000-8000-000000000008',
                'b1200000-0000-4000-8000-000000000009',
                new CarbonImmutable('2026-10-12 11:00:00', 'UTC'),
                new CarbonImmutable('2026-10-12 13:00:00', 'UTC'),
            ));
            $this->fail('Expected overlapping attendance to be rejected.');
        } catch (InventoryDomainException) {
            $this->addToAssertionCount(1);
        }
        $annulled = app(AttendanceService::class)->annul($manager, new AnnulAttendanceCommand(
            $attendance->id,
            'b1200000-0000-4000-8000-000000000010',
            'Correccion autorizada',
        ));
        $this->assertSame(AttendanceStatus::Annulled, $annulled->estado);
        $this->assertSame(120, $annulled->minutos_trabajados);
        $this->assertSame('30.00', $annulled->monto_calculado);
        $this->assertSame($attendance->id, app(AttendanceService::class)->annul($manager, new AnnulAttendanceCommand(
            $attendance->id,
            'b1200000-0000-4000-8000-000000000010',
            'Correccion autorizada',
        ))->id);
    }

    public function test_conflicting_idempotency_is_rejected(): void
    {
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            'P04', 'Rosa', 'Turno', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '10.00', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        $key = 'b1200000-0000-4000-8000-000000000011';
        app(AttendanceService::class)->recordManual($manager, new RecordManualAttendanceCommand(
            $worker->id, $key, 'b1200000-0000-4000-8000-000000000012',
            new CarbonImmutable('2026-10-13 10:00:00', 'UTC'),
            new CarbonImmutable('2026-10-13 11:00:00', 'UTC'),
        ));

        $this->expectException(IdempotencyConflictException::class);
        app(AttendanceService::class)->recordManual($manager, new RecordManualAttendanceCommand(
            $worker->id, $key, 'b1200000-0000-4000-8000-000000000013',
            new CarbonImmutable('2026-10-13 12:00:00', 'UTC'),
            new CarbonImmutable('2026-10-13 13:00:00', 'UTC'),
        ));
    }
}
