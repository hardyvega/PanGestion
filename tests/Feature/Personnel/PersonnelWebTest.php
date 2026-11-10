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
use Illuminate\Support\Facades\DB;
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

    public function test_repeated_santiago_hours_require_a_choice_and_preserve_elapsed_time_and_rate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00:00 UTC'));
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            'P-DST', 'Rosa', 'Jornada', new CarbonImmutable('2026-01-01', 'UTC'), null,
        ));
        $this->actingAs($manager);
        $rate = route('personnel.rates.store', $worker);
        $rateData = ['valor_hora' => '12.00', 'vigente_desde' => '2026-04-04T23:00'];
        $this->post($rate, $rateData)->assertSessionHasErrors('vigente_desde');
        $this->post($rate, $rateData + ['vigente_desde_occurrence' => 'primera'])->assertSessionHasNoErrors();
        $this->post($rate, [
            'valor_hora' => '18.00', 'vigente_desde' => '2026-04-04T23:00', 'vigente_desde_occurrence' => 'segunda',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tarifas_hora_trabajador', ['valor_hora' => '18.00', 'vigente_desde' => '2026-04-05 03:00:00+00']);

        $url = route('attendance.manual.store');
        $this->post($url, [
            'trabajador_id' => $worker->id, 'iniciada_en' => '2026-09-06T00:30', 'finalizada_en' => '2026-09-06T01:30',
        ])->assertSessionHasErrors(['iniciada_en' => 'Esta hora no existe en Santiago por el cambio de horario. Elige otra hora.']);
        $repeated = ['trabajador_id' => $worker->id, 'iniciada_en' => '2026-04-04T23:45', 'finalizada_en' => '2026-04-04T23:15'];
        $this->post($url, $repeated)->assertSessionHasErrors(['iniciada_en', 'finalizada_en']);
        $this->assertDatabaseCount('jornadas_trabajo', 0);

        $this->post($url, $repeated + ['iniciada_en_occurrence' => 'primera', 'finalizada_en_occurrence' => 'segunda'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('jornadas_trabajo', [
            'trabajador_id' => $worker->id,
            'iniciada_en' => '2026-04-05 02:45:00+00', 'finalizada_en' => '2026-04-05 03:15:00+00',
            'minutos_trabajados' => 30, 'valor_hora_snapshot' => '12.00', 'monto_calculado' => '6.00',
        ]);
        $this->get(route('personnel.pay-report', ['desde' => '2026-04-04', 'hasta' => '2026-04-04']))
            ->assertOk()->assertSeeInOrder(['04/04/2026 23:45', '04/04/2026 23:15'])
            ->assertViewHas('report', fn (array $report) => $report['jornadas'] === 1);
    }

    public function test_manual_close_and_default_month_follow_santiago(): void
    {
        $manager = $this->b12Manager();
        $worker = app(WorkerService::class)->create($manager, new CreateWorkerCommand(
            'P-MIDNIGHT', 'Luis', 'Invierno', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));
        app(HourlyRateService::class)->add($manager, new SetHourlyRateCommand(
            $worker->id, '12.00', new CarbonImmutable('2020-01-01', 'UTC'), null,
        ));

        $this->travelTo(CarbonImmutable::parse('2026-07-15 03:30:00 UTC'));
        $this->actingAs($manager)->post(route('attendance.start'), ['trabajador_id' => $worker->id])->assertSessionHasNoErrors();
        $open = JornadaTrabajo::query()->firstOrFail();
        $this->travelTo(CarbonImmutable::parse('2026-07-15 04:30:00 UTC'));
        $this->post(route('attendance.close', $open), ['finalizada_en' => '2026-07-15T00:15'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('jornadas_trabajo', [
            'id' => $open->id, 'finalizada_en' => '2026-07-15 04:15:00+00', 'minutos_trabajados' => 45,
        ]);
        $this->get(route('personnel.pay-report', ['desde' => '2026-07-14', 'hasta' => '2026-07-14']))
            ->assertOk()->assertSee('14/07/2026 23:30')->assertSee('15/07/2026 00:15');

        $this->travelTo(CarbonImmutable::parse('2026-11-01 02:59:59 UTC'));
        $this->get(route('personnel.pay-report'))->assertOk()->assertViewHas('filters', ['desde' => '2026-10-01', 'hasta' => '2026-10-31']);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00:00 UTC'));
        $this->get(route('personnel.pay-report'))->assertOk()->assertViewHas('filters', ['desde' => '2026-11-01', 'hasta' => '2026-11-30']);
    }
}
