<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Enums\AttendanceStatus;
use App\Enums\PermissionCode;
use App\Models\JornadaTrabajo;
use App\Models\Trabajador;
use App\Services\Personnel\HourlyRateService;
use App\Services\Personnel\WorkerService;
use App\Data\Personnel\CreateWorkerCommand;
use App\Data\Personnel\SetHourlyRateCommand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BlockTwelveTestSupport;
use Tests\TestCase;

final class PersonnelWebTest extends TestCase
{
    use RefreshDatabase;
    use BlockTwelveTestSupport;

    public function test_manager_can_manage_workers_rates_attendance_and_report_from_web(): void
    {
        $manager = $this->b12Manager();

        $this->actingAs($manager)->get(route('personnel.index'))->assertOk()->assertSee('Personal');
        $this->actingAs($manager)->get(route('personnel.create'))->assertOk()->assertSee('Nuevo trabajador');
        $this->actingAs($manager)->post(route('personnel.store'), [
            'codigo' => ' pan-web-01 ',
            'nombres' => 'Ana',
            'apellidos' => 'Panadera',
            'fecha_ingreso' => '2026-01-01',
        ])->assertRedirect();

        $worker = Trabajador::query()->firstOrFail();
        $this->actingAs($manager)->get(route('personnel.show', $worker))->assertOk()->assertSee('Ana Panadera');
        $this->actingAs($manager)->get(route('personnel.edit', $worker))->assertOk()->assertSee('Editar trabajador');
        $this->actingAs($manager)->post(route('personnel.rates.store', $worker), [
            'valor_hora' => '10.00',
            'vigente_desde' => '2026-01-01T00:00',
            'observacion' => 'Tarifa base',
        ])->assertRedirect();

        $this->actingAs($manager)->post(route('attendance.manual.store'), [
            'trabajador_id' => $worker->id,
            'iniciada_en' => '2026-10-10T10:00',
            'finalizada_en' => '2026-10-10T11:30',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->actingAs($manager)->get(route('attendance.index'))->assertOk()->assertSee('Jornadas');

        $this->assertDatabaseHas('jornadas_trabajo', [
            'trabajador_id' => $worker->id,
            'estado' => AttendanceStatus::Closed->value,
            'monto_calculado' => '15.00',
        ]);

        $reportResponse = $this->actingAs($manager)->get(route('personnel.pay-report', ['desde' => '2026-10-10', 'hasta' => '2026-10-10']))
            ->assertOk();
        $this->assertStringContainsString('15,00', $reportResponse->getContent(), 'Report response did not contain expected amount.');

    }

    public function test_personnel_view_permission_does_not_authorize_mutations(): void
    {
        $viewer = $this->b12User([PermissionCode::PersonnelView], 'personnel_viewer');

        $this->actingAs($viewer)->get(route('personnel.index'))->assertOk();
        $this->actingAs($viewer)->get(route('personnel.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('personnel.store'), [
            'codigo' => 'P-READ',
            'nombres' => 'Solo',
            'apellidos' => 'Lectura',
            'fecha_ingreso' => '2026-01-01',
        ])->assertForbidden();
    }

    public function test_manager_can_start_and_close_an_open_attendance(): void
    {
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            'P-OPEN', 'Luis', 'Turno', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '12.00', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));

        $this->actingAs($manager)->get(route('attendance.mark'))->assertOk()->assertSee('Iniciar jornada');
        $this->actingAs($manager)->post(route('attendance.start'), ['trabajador_id' => $worker->id])->assertRedirect();
        $open = JornadaTrabajo::query()->where('trabajador_id', $worker->id)->firstOrFail();
        $this->actingAs($manager)->post(route('attendance.close', $open))->assertRedirect();

        $this->assertDatabaseHas('jornadas_trabajo', [
            'id' => $open->id,
            'estado' => AttendanceStatus::Closed->value,
        ]);
    }
}
