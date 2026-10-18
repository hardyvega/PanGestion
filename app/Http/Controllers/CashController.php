<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Http\Requests\Cash\CloseCashSessionRequest;
use App\Http\Requests\Cash\StoreCashMovementRequest;
use App\Http\Requests\Cash\StoreCashSessionRequest;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Cash\CashService;
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
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeAccess($user);
        $canViewSessions = $this->permissions->allows($user, PermissionCode::CashSessionsView);
        $canOpen = $this->permissions->allows($user, PermissionCode::CashSessionsOpen);
        $canClose = $this->permissions->allows($user, PermissionCode::CashSessionsClose);
        $canViewMovements = $this->permissions->allows($user, PermissionCode::CashMovementsView);
        $canManageMovements = $this->permissions->allows($user, PermissionCode::CashManualMovementsManage);
        $openSessions = $canViewSessions ? $this->cash->openSessions($user) : collect();
        $selectedId = $this->positiveInt($request->query('session'))
            ?? $openSessions->first()?->getKey();
        $overview = $canViewSessions && $selectedId !== null
            ? $this->cash->overview($user, $selectedId)
            : null;

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
        ]);
    }

    public function movements(Request $request): View
    {
        $user = $request->user();
        $this->permissions->authorize($user, PermissionCode::CashMovementsView);
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
                (string) $request->validated('monto_inicial'),
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
                $request->validated('observacion'),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cerrar la sesión de caja.');
        }

        return redirect()->route('cash.index', ['session' => $session->getKey()])
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
        abort_unless(collect([
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashMovementsView,
            PermissionCode::CashManualMovementsManage,
        ])->contains(fn (PermissionCode $permission): bool => $this->permissions->allows($user, $permission)), 403);
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
            $fallback = $exception->getMessage() !== '' ? $exception->getMessage() : $fallback;
        } elseif ($exception instanceof ModelNotFoundException) {
            $fallback = 'La caja o sesión seleccionada ya no está disponible.';
        } else {
            report($exception);
        }

        return back()->withInput()->withErrors(['cash' => $fallback]);
    }
}
