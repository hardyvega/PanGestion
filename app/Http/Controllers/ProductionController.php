<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Production\AnnulProductionCommand;
use App\Data\Production\ConfirmProductionCommand;
use App\Enums\PermissionCode;
use App\Http\Requests\Production\AnnulProductionRequest;
use App\Http\Requests\Production\ConfirmProductionRequest;
use App\Http\Requests\Production\StoreProductionRequest;
use App\Models\Produccion;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Production\ProductionAdministrationService;
use App\Services\Production\ProductionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Throwable;

final class ProductionController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly ProductionAdministrationService $productions,
        private readonly ProductionService $productionService,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ProductionView);
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'estado' => in_array($request->query('estado', 'todos'), ['todos', 'borrador', 'confirmada', 'anulada'], true)
                ? $request->query('estado', 'todos') : 'todos',
        ];

        return view('productions.index', [
            'user' => $user,
            'productions' => $this->productions->list($user, $filters),
            'canManage' => $this->permissions->allows($user, PermissionCode::ProductionManage),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ProductionManage);

        return view('productions.create', [
            'user' => $user,
            'recipes' => $this->productions->activeRecipes($user),
        ]);
    }

    public function store(StoreProductionRequest $request): RedirectResponse
    {
        try {
            $production = $this->productions->create($request->user(), $request->validated());

            return redirect()->route('productions.show', $production)->with('success', 'Producción iniciada en borrador. El stock se afecta solo al confirmar.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['production' => $this->message($exception, 'No fue posible iniciar la producción.')]);
        }
    }

    public function show(Request $request, Produccion $produccion): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ProductionView);
        $production = $this->productions->show($user, $produccion);

        return view('productions.show', [
            'user' => $user,
            'production' => $production,
            'canManage' => $this->permissions->allows($user, PermissionCode::ProductionManage),
            'canConfirm' => $this->permissions->allows($user, PermissionCode::ProductionConfirm),
            'canAnnul' => $this->permissions->allows($user, PermissionCode::ProductionAnnul),
            'confirmUuid' => (string) Str::uuid(),
            'annulUuid' => (string) Str::uuid(),
        ]);
    }

    public function confirm(ConfirmProductionRequest $request, Produccion $produccion): RedirectResponse
    {
        try {
            $this->productionService->confirm(
                $request->user(),
                new ConfirmProductionCommand((int) $produccion->getKey(), (string) $request->validated('command_uuid')),
            );

            return back()->with('success', 'Producción confirmada y registrada en inventario.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['production' => $this->message($exception, 'No fue posible confirmar la producción.')]);
        }
    }

    public function annul(AnnulProductionRequest $request, Produccion $produccion): RedirectResponse
    {
        try {
            $this->productionService->annul(
                $request->user(),
                new AnnulProductionCommand(
                    (int) $produccion->getKey(),
                    (string) $request->validated('command_uuid'),
                    (string) $request->validated('motivo_anulacion'),
                ),
            );

            return back()->with('success', 'Producción anulada mediante movimientos compensatorios.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['production' => $this->message($exception, 'No fue posible anular la producción.')]);
        }
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }

    private function message(Throwable $exception, string $fallback): string
    {
        return $exception->getMessage() !== '' ? $exception->getMessage() : $fallback;
    }


}
