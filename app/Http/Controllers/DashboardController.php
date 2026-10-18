<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly EffectivePermissionResolver $permissions,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $period = $this->period($request->query('period'));

        return view('inicio', [
            ...$this->pageData($user),
            'metrics' => $this->dashboard->summary($user),
            'selectedPeriod' => $period,
        ]);
    }

    private function period(mixed $period): string
    {
        return is_string($period) && in_array($period, ['hoy', 'semana', 'mes'], true)
            ? $period
            : 'hoy';
    }

    /** @return array{user: User, quickActions: array<int, array<string, mixed>>} */
    private function pageData(User $user): array
    {
        $can = fn (PermissionCode $permission): bool => $this->permissions->allows($user, $permission);
        $hasAny = fn (array $permissions): bool => collect($permissions)->contains($can);

        $quickActions = [];
        if ($can(PermissionCode::SalesCreate)) {
            $quickActions[] = [
                'label' => 'Nueva venta',
                'description' => 'Registrar una venta.',
                'href' => route('sales.create'),
                'accent' => true,
            ];
        }
        if ($hasAny([
            PermissionCode::OrdersCreate,
            PermissionCode::OrdersManage,
            PermissionCode::OrdersCancel,
            PermissionCode::OrdersDeliver,
            PermissionCode::OrdersCollect,
        ])) {
            $quickActions[] = [
                'label' => 'Pedidos',
                'description' => 'Gestionar pedidos y entregas.',
                'href' => route('orders.index'),
            ];
        }
        if ($hasAny([
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashMovementsView,
            PermissionCode::CashManualMovementsManage,
        ])) {
            $quickActions[] = [
                'label' => 'Caja',
                'description' => 'Abrir sesiones, consultar movimientos y cuadrar efectivo.',
                'href' => route('cash.index'),
            ];
        }
        if ($can(PermissionCode::ArticlesView)) {
            $quickActions[] = [
                'label' => 'Artículos',
                'description' => 'Consultar y mantener el catálogo de artículos.',
                'href' => route('articles.index'),
            ];
        }
        if ($can(PermissionCode::ArticlesView)) {
            $quickActions[] = [
                'label' => 'Inventario',
                'description' => 'Consultar stock y operar workflows trazables.',
                'href' => route('inventory.index'),
            ];
        }
        if ($can(PermissionCode::ProductionView)) {
            $quickActions[] = [
                'label' => 'Producción',
                'description' => 'Preparar y confirmar producciones con trazabilidad.',
                'href' => route('productions.index'),
            ];
        }
        if ($hasAny([PermissionCode::AttendanceMark, PermissionCode::AttendanceManage])) {
            $quickActions[] = [
                'label' => 'Mi asistencia',
                'description' => 'Iniciar y cerrar tu jornada operativa.',
                'href' => route('attendance.mark'),
            ];
        }

        return compact('user', 'quickActions');
    }
}
