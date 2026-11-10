<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;

final class NavigationBuilder
{
    public function __construct(private readonly EffectivePermissionResolver $permissions) {}

    /** @return array<int, array{label: string, items: array<int, array<string, mixed>>}> */
    public function forUser(User $user): array
    {
        $allowed = [];
        $can = function (PermissionCode $permission) use ($user, &$allowed): bool {
            return $allowed[$permission->value] ??= $this->permissions->allows($user, $permission);
        };
        $groups = [];
        $add = function (string $group, string $label, string $icon, string $route, array $permissions = [], string|array|null $active = null) use (&$groups, $can): void {
            if ($permissions !== [] && ! collect($permissions)->contains($can)) {
                return;
            }

            $groups[$group][] = [
                'label' => $label,
                'icon' => $icon,
                'href' => route($route),
                'active' => request()->routeIs(...(array) ($active ?? $route)),
            ];
        };

        $add('Principal', 'Inicio', '⌂', 'inicio');
        $add('Operación', 'Nueva venta', '+', 'sales.create', [PermissionCode::SalesCreate], 'sales.*');
        $add('Operación', 'Pedidos', '→', 'orders.index', [PermissionCode::OrdersCreate, PermissionCode::OrdersManage, PermissionCode::OrdersCancel, PermissionCode::OrdersDeliver, PermissionCode::OrdersCollect], 'orders.*');

        $add('Inventario', 'Stock', '▦', 'inventory.index', [PermissionCode::ArticlesView], ['inventory.index', 'inventory.show']);
        $add('Inventario', 'Recepciones', '+', 'receipts.index', [PermissionCode::InventoryReceiptsView], 'receipts.*');
        $add('Inventario', 'Movimientos', '↗', 'inventory.movements', [PermissionCode::InventoryMovementsView]);
        $add('Inventario', 'Conteos', '☷', 'inventory.counts', [PermissionCode::InventoryCountsManage, PermissionCode::InventoryCountsConfirm], 'inventory.counts*');
        $add('Inventario', 'Mermas', '−', 'inventory.wastes', [PermissionCode::InventoryWasteRegister, PermissionCode::InventoryWasteAnnul], 'inventory.wastes*');
        $add('Inventario', 'Ajustes', '±', 'inventory.adjustments.create', [PermissionCode::InventoryAdjustmentsInput, PermissionCode::InventoryAdjustmentsOutput], 'inventory.adjustments.*');

        $add('Producción', 'Producción', '◎', 'productions.index', [PermissionCode::ProductionView], 'productions.*');
        $add('Producción', 'Recetas', '◫', 'recipes.index', [PermissionCode::RecipesView], 'recipes.*');
        $add('Catálogo', 'Artículos', '▤', 'articles.index', [PermissionCode::ArticlesView], 'articles.*');
        $add('Catálogo', 'Categorías', '☷', 'catalog.categories.index', [PermissionCode::ArticlesManage], 'catalog.categories.*');
        $add('Catálogo', 'Unidades de medida', '▦', 'catalog.units.index', [PermissionCode::ArticlesManage], 'catalog.units.*');
        $add('Catálogo', 'Proveedores', '◇', 'suppliers.index', [PermissionCode::SuppliersView], 'suppliers.*');

        $add('Caja', 'Sesiones e historial', '$', 'cash.index', [PermissionCode::CashSessionsView, PermissionCode::CashSessionsOpen, PermissionCode::CashSessionsClose, PermissionCode::CashMovementsView, PermissionCode::CashManualMovementsManage, PermissionCode::CashFundsAuthorize]);
        $add('Caja', 'Movimientos de caja', '↗', 'cash.movements', [PermissionCode::CashMovementsView]);

        $add('Personal', 'Trabajadores', '◎', 'personnel.index', [PermissionCode::PersonnelView], ['personnel.index', 'personnel.create', 'personnel.show', 'personnel.edit']);
        $add('Personal', 'Mi asistencia', '◷', 'attendance.mark', [PermissionCode::AttendanceMark, PermissionCode::AttendanceManage]);
        $add('Personal', 'Jornadas', '≡', 'attendance.index', [PermissionCode::AttendanceManage]);
        $add('Personal', 'Pago por hora', '$', 'personnel.pay-report', [PermissionCode::HourlyPayView]);

        $add('Administración', 'Usuarios', '◉', 'admin.users.index', [PermissionCode::UsersView], 'admin.users.*');
        $add('Administración', 'Roles', '◇', 'admin.roles.index', [PermissionCode::RolesView], 'admin.roles.*');
        $add('Administración', 'Permisos', '✓', 'admin.permissions.index', [PermissionCode::PermissionsView], 'admin.permissions.*');

        return collect($groups)->map(static fn (array $items, string $label): array => compact('label', 'items'))->values()->all();
    }
}
