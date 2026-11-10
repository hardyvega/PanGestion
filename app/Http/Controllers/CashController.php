<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\UserFacingError;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Http\Requests\Cash\AuthorizeCashFundRequest;
use App\Http\Requests\Cash\CloseCashSessionRequest;
use App\Http\Requests\Cash\StoreCashMovementRequest;
use App\Http\Requests\Cash\StoreCashSessionRequest;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Cash\CashFundService;
use App\Services\Cash\CashService;
use App\Support\CommercialDate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

final class CashController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly CashService $cash,
        private readonly CashFundService $funds,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeAccess($user);
        $canViewSessions = $this->permissions->allows($user, PermissionCode::CashSessionsView);
        $canOpen = $this->permissions->allows($user, PermissionCode::CashSessionsOpen);
        $canAuthorizeFunds = $this->permissions->allows($user, PermissionCode::CashFundsAuthorize);
        $canClose = $this->permissions->allowsAll($user, [PermissionCode::CashSessionsClose, PermissionCode::CashFundsAuthorize]);
        $canViewMovements = $this->permissions->allows($user, PermissionCode::CashMovementsView);
        $canManageMovements = $this->permissions->allows($user, PermissionCode::CashManualMovementsManage);
        $openSessions = $canViewSessions ? $this->cash->openSessions($user) : (($canManageMovements || $canClose) ? $this->cash->actionableSessions($user) : collect());
        $selectedId = $this->positiveInt($request->query('session'))
            ?? $openSessions->first()?->getKey();
        $overview = $selectedId !== null && $canViewSessions ? $this->cash->overview($user, $selectedId)
            : ($selectedId !== null && $canClose ? $this->cash->closingOverview($user, $selectedId) : null);
        $operationSession = $overview === null ? $openSessions->firstWhere('id', $selectedId) : null;
        $today = CommercialDate::today();

        return view('cash.index', [
            'user' => $user,
            'registers' => $canOpen ? $this->cash->activeRegisters($user) : collect(),
            'sessions' => $canViewSessions ? $this->cash->sessions($user) : null,
            'openSessions' => $openSessions,
            'overview' => $overview,
            'canViewSessions' => $canViewSessions,
            'canOpen' => $canOpen,
            'canClose' => $canClose,
            'canViewMovements' => $canViewMovements,
            'canManageMovements' => $canManageMovements,
            'canAuthorizeInitialFund' => $canAuthorizeFunds && $this->funds->initialRegisters()->isNotEmpty(),
            'canAuthorizeOpeningFund' => $canAuthorizeFunds && $this->funds->openingRegister($today) !== null,
            'fundHistory' => $canAuthorizeFunds ? $this->funds->history($user) : null,
            'fundHistoryToday' => $today,
            'operationSession' => $operationSession,
        ]);
    }

    public function movements(Request $request): View
    {
        $user = $request->user();
        $this->permissions->authorize($user, PermissionCode::CashMovementsView);
        $request->validate([
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $filters = $request->only([
            'fecha_desde', 'fecha_hasta', 'tipo', 'origen', 'session_id', 'caja_id', 'usuario_id',
        ]);

        return view('cash.movements', [
            'user' => $user,
            'movements' => $this->cash->movements($user, $filters),
            'registers' => $this->cash->registersForFilter($user),
            'users' => $this->cash->usersForFilter($user),
            'filters' => $filters,
        ]);
    }

    public function storeSession(StoreCashSessionRequest $request): RedirectResponse
    {
        try {
            $session = $this->cash->open(
                $request->user(),
                (int) $request->validated('caja_id'),
                (int) $request->validated('fondo_autorizado_id'),
                (string) $request->validated('fecha_operativa'),
                (string) $request->validated('clave_idempotencia'),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo abrir la sesión de caja.');
        }

        return redirect()->route('cash.index', ['session' => $session->getKey()])
            ->with('success', 'Sesión de caja abierta correctamente.');
    }

    public function closeSession(CloseCashSessionRequest $request, SesionCaja $sesion): RedirectResponse
    {
        try {
            $session = $this->cash->close(
                $request->user(),
                (int) $sesion->getKey(),
                (string) $request->validated('monto_declarado'),
                (string) $request->validated('fondo_siguiente'),
                (string) $request->validated('fecha_siguiente'),
                (string) $request->validated('clave_idempotencia'),
                $request->boolean('confirmacion'),
                $request->validated('observacion'),
                $request->validated('esperado_revisado'),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cerrar la sesión de caja.');
        }

        return redirect()->route('cash.index', $this->permissions->allows($request->user(), PermissionCode::CashSessionsView) ? ['session' => $session->getKey()] : [])
            ->with('success', 'Sesión de caja cerrada correctamente.');
    }

    public function storeMovement(StoreCashMovementRequest $request, SesionCaja $sesion): RedirectResponse
    {
        try {
            $this->cash->registerManualMovement(
                $request->user(),
                (int) $sesion->getKey(),
                (string) $request->validated('tipo_movimiento'),
                (string) $request->validated('monto'),
                (string) $request->validated('concepto'),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo registrar el movimiento de caja.');
        }

        return redirect()->route('cash.index', ['session' => $sesion->getKey()])
            ->with('success', 'Movimiento de caja registrado correctamente.');
    }

    private function authorizeAccess(?User $user): void
    {
        abort_unless($user instanceof User, 401);
        abort_unless($this->cash->canAccess($user), 403);
    }

    public function initialFund(Request $request): View|RedirectResponse
    {
        $this->permissions->authorize($request->user(), PermissionCode::CashFundsAuthorize);
        $registers = $this->funds->initialRegisters();
        if ($registers->isEmpty()) {
            return redirect()->route('cash.index')->withErrors(['cash' => 'El primer fondo solo se autoriza para una caja sin historial.']);
        }

        return view('cash.initial-fund', ['user' => $request->user(), 'registers' => $registers]);
    }

    public function authorizeInitialFund(AuthorizeCashFundRequest $request): RedirectResponse
    {
        try {
            $this->funds->authorizeInitial($request->user(), (int) $request->validated('caja_id'), $request->validated('fecha_operativa'), (string) $request->validated('monto'), $request->validated('motivo'), $request->validated('clave_idempotencia'));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo autorizar el primer fondo.');
        }

        return redirect()->route('cash.index')->with('success', 'Primer fondo autorizado. Todavía no se ha abierto una sesión ni registrado dinero como ingreso.');
    }

    public function openingFund(Request $request): View|RedirectResponse
    {
        $this->permissions->authorize($request->user(), PermissionCode::CashFundsAuthorize);
        $today = CommercialDate::today();
        $register = $this->funds->openingRegister($today);
        if ($register === null) {
            return redirect()->route('cash.index')->withErrors(['cash' => 'Caja principal no admite una nueva autorización para hoy. Revisa el estado de la sesión y del fondo.']);
        }

        return view('cash.opening-fund', [
            'user' => $request->user(),
            'register' => $register,
            'today' => $today,
            'hasExpiredFund' => $register->fondosAutorizados()->where('fecha_operativa', '<', $today)->whereNull('utilizada_en')->exists(),
        ]);
    }

    public function authorizeOpeningFund(AuthorizeCashFundRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->funds->authorizeOpening(
                $request->user(), (int) $data['caja_id'], $data['fecha_operativa'], (string) $data['monto'],
                $data['motivo'], $data['clave_idempotencia'],
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo autorizar el fondo de apertura.');
        }

        return redirect()->route('cash.index')->with('success', 'Fondo de apertura autorizado para la jornada. La autorización no abre una sesión de Caja.');
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function failure(Throwable $exception, string $fallback): RedirectResponse
    {
        if ($exception instanceof ValidationException) {
            throw $exception;
        }
        if ($exception instanceof InventoryDomainException || $exception instanceof InvalidArgumentException) {
            $fallback = UserFacingError::message($exception, $fallback);
        } elseif ($exception instanceof ModelNotFoundException) {
            $fallback = 'La caja o sesión seleccionada ya no está disponible.';
        } else {
            throw $exception;
        }

        return back()->withInput()->withErrors(['cash' => $fallback]);
    }
}
