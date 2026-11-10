<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\UserFacingError;

use App\Data\Attendance\AnnulAttendanceCommand;
use App\Data\Attendance\CloseAttendanceCommand;
use App\Data\Attendance\RecordManualAttendanceCommand;
use App\Data\Attendance\StartAttendanceCommand;
use App\Data\Personnel\CreateWorkerCommand;
use App\Data\Personnel\SetHourlyRateCommand;
use App\Data\Personnel\UpdateWorkerCommand;
use App\Data\Reports\HourlyPayReportQuery;
use App\Enums\AttendanceStatus;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Http\Requests\Attendance\AnnulAttendanceRequest;
use App\Http\Requests\Attendance\CloseAttendanceRequest;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Requests\Attendance\StartAttendanceRequest;
use App\Http\Requests\Personnel\StoreHourlyRateRequest;
use App\Http\Requests\Personnel\StoreWorkerRequest;
use App\Http\Requests\Personnel\UpdateWorkerRequest;
use App\Http\Requests\Reports\HourlyPayReportRequest;
use App\Models\JornadaTrabajo;
use App\Models\TarifaHoraTrabajador;
use App\Models\Trabajador;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Personnel\HourlyRateService;
use App\Services\Personnel\WorkerService;
use App\Services\Reports\HourlyPayReportService;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class PersonnelController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly WorkerService $workers,
        private readonly HourlyRateService $rates,
        private readonly AttendanceService $attendance,
        private readonly HourlyPayReportService $payReport,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $this->actor($request);
        $this->permissions->authorize($user, PermissionCode::PersonnelView);
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'activo' => in_array($request->query('activo', 'activos'), ['todos', 'activos', 'inactivos'], true)
                ? $request->query('activo', 'activos') : 'activos',
        ];
        $workers = Trabajador::query()
            ->with('usuario')
            ->withCount(['jornadas as jornadas_abiertas_count' => fn ($query) => $query->where('estado', AttendanceStatus::Open->value)])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($nested) use ($term): void {
                    $nested->where('codigo', 'ilike', $term)
                        ->orWhere('nombres', 'ilike', $term)
                        ->orWhere('apellidos', 'ilike', $term);
                });
            })
            ->when($filters['activo'] === 'activos', fn ($query) => $query->where('activo', true))
            ->when($filters['activo'] === 'inactivos', fn ($query) => $query->where('activo', false))
            ->orderBy('apellidos')->orderBy('nombres')->orderBy('id')
            ->paginate(15)->withQueryString();

        return view('personnel.index', [
            ...$this->pageData($user),
            'workers' => $workers,
            'filters' => $filters,
            'canManage' => $this->permissions->allows($user, PermissionCode::PersonnelManage),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $this->actor($request);
        $this->permissions->authorize($user, PermissionCode::PersonnelManage);

        return view('personnel.create', [...$this->pageData($user), 'worker' => null, 'users' => $this->availableUsers()]);
    }

    public function store(StoreWorkerRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $worker = $this->workers->create($request->user(), new CreateWorkerCommand(
                $data['codigo'], $data['nombres'], $data['apellidos'],
                $this->date($data['fecha_ingreso']),
                isset($data['usuario_id']) ? (int) $data['usuario_id'] : null,
                isset($data['fecha_termino']) ? $this->date($data['fecha_termino']) : null,
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo crear el trabajador.');
        }

        return redirect()->route('personnel.show', $worker)->with('success', 'Trabajador creado correctamente.');
    }

    public function show(Request $request, Trabajador $trabajador): View
    {
        $user = $this->actor($request);
        $this->permissions->authorize($user, PermissionCode::PersonnelView);
        $trabajador->load('usuario');
        $canViewRates = $this->permissions->allows($user, PermissionCode::HourlyRatesView);
        $rates = $canViewRates ? $this->rates->list($user, (int) $trabajador->getKey()) : collect();
        $currentRate = $rates->first(fn (TarifaHoraTrabajador $rate): bool => $rate->vigente_desde <= now('UTC'));
        $recentSessions = $this->permissions->allows($user, PermissionCode::AttendanceManage)
            ? $trabajador->jornadas()->latest('iniciada_en')->limit(8)->get()
            : collect();

        return view('personnel.show', [
            ...$this->pageData($user),
            'worker' => $trabajador,
            'rates' => $rates,
            'currentRate' => $currentRate,
            'recentSessions' => $recentSessions,
            'canManage' => $this->permissions->allows($user, PermissionCode::PersonnelManage),
            'canManageRates' => $this->permissions->allows($user, PermissionCode::HourlyRatesManage),
            'canViewRates' => $canViewRates,
        ]);
    }

    public function edit(Request $request, Trabajador $trabajador): View
    {
        $user = $this->actor($request);
        $this->permissions->authorize($user, PermissionCode::PersonnelManage);

        return view('personnel.edit', [...$this->pageData($user), 'worker' => $trabajador, 'users' => $this->availableUsers($trabajador)]);
    }

    public function update(UpdateWorkerRequest $request, Trabajador $trabajador): RedirectResponse
    {
        $data = $request->validated();
        try {
            $worker = $this->workers->update($request->user(), new UpdateWorkerCommand(
                (int) $trabajador->getKey(), $data['codigo'], $data['nombres'], $data['apellidos'],
                $this->date($data['fecha_ingreso']),
                isset($data['usuario_id']) ? (int) $data['usuario_id'] : null,
                isset($data['fecha_termino']) ? $this->date($data['fecha_termino']) : null,
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo actualizar el trabajador.');
        }

        return redirect()->route('personnel.show', $worker)->with('success', 'Datos del trabajador actualizados.');
    }

    public function activate(Request $request, Trabajador $trabajador): RedirectResponse
    {
        $this->permissions->authorize($this->actor($request), PermissionCode::PersonnelManage);
        try {
            $this->workers->reactivate($request->user(), (int) $trabajador->getKey());
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo activar el trabajador.');
        }

        return back()->with('success', 'Trabajador activado.');
    }

    public function deactivate(Request $request, Trabajador $trabajador): RedirectResponse
    {
        $this->permissions->authorize($this->actor($request), PermissionCode::PersonnelManage);
        try {
            $this->workers->deactivate($request->user(), (int) $trabajador->getKey());
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo desactivar el trabajador.');
        }

        return back()->with('success', 'Trabajador desactivado.');
    }

    public function storeRate(StoreHourlyRateRequest $request, Trabajador $trabajador): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->rates->add($request->user(), new SetHourlyRateCommand(
                (int) $trabajador->getKey(), $data['valor_hora'],
                $request->commercialInstant('vigente_desde'),
                $data['observacion'] ?? null,
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo registrar la tarifa.');
        }

        return back()->with('success', 'Nueva tarifa registrada en el historial.');
    }

    public function attendance(Request $request): View
    {
        $user = $this->actor($request);
        $this->authorizeAttendanceAccess($user);
        $manager = $this->permissions->allows($user, PermissionCode::AttendanceManage);
        $workers = $manager
            ? Trabajador::query()->where('activo', true)->orderBy('apellidos')->orderBy('nombres')->get()
            : Trabajador::query()->where('usuario_id', $user->getKey())->where('activo', true)->get();
        $open = JornadaTrabajo::query()->with('trabajador')->where('estado', AttendanceStatus::Open->value)
            ->whereIn('trabajador_id', $workers->modelKeys())->orderBy('iniciada_en')->get();

        return view('attendance.mark', [
            ...$this->pageData($user),
            'workers' => $workers,
            'openAttendances' => $open,
            'canManage' => $manager,
        ]);
    }

    public function start(StartAttendanceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->attendance->start($request->user(), new StartAttendanceCommand((int) $data['trabajador_id'], (string) Str::uuid()));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo iniciar la jornada.');
        }

        return back()->with('success', 'Jornada iniciada con la hora del servidor.');
    }

    public function close(CloseAttendanceRequest $request, JornadaTrabajo $jornada): RedirectResponse
    {
        try {
            $finishedAt = $request->commercialInstant('finalizada_en');
            $this->attendance->close($request->user(), new CloseAttendanceCommand((int) $jornada->getKey(), (string) Str::uuid(), $finishedAt));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cerrar la jornada.');
        }

        return back()->with('success', 'Jornada cerrada y monto calculado por el servicio.');
    }

    public function managedAttendance(Request $request): View
    {
        $user = $this->actor($request);
        $this->permissions->authorize($user, PermissionCode::AttendanceManage);
        $filters = [
            'estado' => in_array($request->query('estado', 'todas'), ['todas', 'abierta', 'cerrada', 'anulada'], true) ? $request->query('estado', 'todas') : 'todas',
            'q' => trim((string) $request->query('q', '')),
        ];
        $sessions = JornadaTrabajo::query()->with('trabajador')
            ->when($filters['estado'] !== 'todas', fn ($query) => $query->where('estado', $filters['estado']))
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->whereHas('trabajador', fn ($worker) => $worker->where('codigo', 'ilike', $term)->orWhere('nombres', 'ilike', $term)->orWhere('apellidos', 'ilike', $term));
            })
            ->orderByDesc('iniciada_en')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('attendance.index', [
            ...$this->pageData($user),
            'workers' => Trabajador::query()->where('activo', true)->orderBy('apellidos')->orderBy('nombres')->get(),
            'sessions' => $sessions,
            'filters' => $filters,
        ]);
    }

    public function storeManual(ManualAttendanceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->attendance->recordManual($request->user(), new RecordManualAttendanceCommand(
                (int) $data['trabajador_id'], (string) Str::uuid(), (string) Str::uuid(),
                $request->commercialInstant('iniciada_en'),
                $request->commercialInstant('finalizada_en'),
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo registrar la asistencia manual.');
        }

        return back()->with('success', 'Asistencia manual registrada con trazabilidad.');
    }

    public function annul(AnnulAttendanceRequest $request, JornadaTrabajo $jornada): RedirectResponse
    {
        try {
            $this->attendance->annul($request->user(), new AnnulAttendanceCommand((int) $jornada->getKey(), (string) Str::uuid(), $request->validated('motivo')));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo anular la jornada.');
        }

        return back()->with('success', 'Jornada anulada sin borrar su historial.');
    }

    public function report(HourlyPayReportRequest $request): View
    {
        $user = $this->actor($request);
        $this->permissions->authorize($user, PermissionCode::HourlyPayView);
        [$from, $until, $filters] = $this->reportRange($request);

        return view('personnel.pay-report', [
            ...$this->pageData($user),
            'report' => $this->payReport->report($user, new HourlyPayReportQuery($from, $until)),
            'filters' => $filters,
        ]);
    }

    private function actor(Request $request): User
    {
        abort_unless($request->user() instanceof User, 401);

        return $request->user();
    }

    private function authorizeAttendanceAccess(User $user): void
    {
        abort_unless(
            $this->permissions->allows($user, PermissionCode::AttendanceMark)
            || $this->permissions->allows($user, PermissionCode::AttendanceManage),
            403,
        );
    }

    private function availableUsers(?Trabajador $worker = null)
    {
        return User::query()->where('activo', true)
            ->where(function ($query) use ($worker): void {
                $query->whereNotExists(function ($nested): void {
                    $nested->selectRaw('1')->from('trabajadores')->whereColumn('trabajadores.usuario_id', 'usuarios.id');
                })->when($worker !== null, fn ($nested) => $nested->orWhereKey($worker->usuario_id));
            })->orderBy('apellido')->orderBy('nombre')->get(['id', 'nombre', 'apellido', 'nombre_usuario']);
    }

    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC');
    }

    private function reportRange(HourlyPayReportRequest $request): array
    {
        $today = $this->date(CommercialDate::today());
        $fromValue = $request->validated('desde') ?: $today->startOfMonth()->toDateString();
        $untilValue = $request->validated('hasta') ?: $today->endOfMonth()->toDateString();
        $range = CommercialDate::utcRange($fromValue, $untilValue);

        return [$range['start'], $range['end'], ['desde' => $fromValue, 'hasta' => $untilValue]];
    }

    /** @return array{user: User} */
    private function pageData(User $user): array
    {
        return ['user' => $user];
    }

    private function failure(Throwable $exception, string $fallback): RedirectResponse
    {
        if ($exception instanceof ValidationException) {
            throw $exception;
        }
        if ($exception instanceof InventoryDomainException || $exception instanceof InvalidArgumentException) {
            $fallback = UserFacingError::message($exception, $fallback);
        } elseif ($exception instanceof ModelNotFoundException) {
            $fallback = 'El registro seleccionado ya no está disponible.';
        } else {
            throw $exception;
        }

        return back()->withInput()->withErrors(['personnel' => $fallback]);
    }
}
