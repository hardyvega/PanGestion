<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Exceptions\OperationalAdminException;
use App\Http\Requests\Administration\StoreAdminUserRequest;
use App\Http\Requests\Administration\StoreRoleRequest;
use App\Http\Requests\Administration\SyncRolePermissionsRequest;
use App\Http\Requests\Administration\UpdateAdminUserRequest;
use App\Http\Requests\Administration\UpdateRoleRequest;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\User;
use App\Services\Administration\PermissionAdministrationService;
use App\Services\Administration\RoleAdministrationService;
use App\Services\Administration\UserAdministrationService;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AdministrationController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly UserAdministrationService $users,
        private readonly RoleAdministrationService $roles,
        private readonly PermissionAdministrationService $permissionCatalog,
    ) {
    }

    public function users(Request $request): View
    {
        $actor = $this->authorize($request, PermissionCode::UsersView);
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'activo' => in_array($request->query('activo', 'todos'), ['todos', 'activos', 'inactivos'], true) ? $request->query('activo', 'todos') : 'todos',
            'rol_id' => is_numeric($request->query('rol_id')) ? (string) (int) $request->query('rol_id') : '',
        ];

        return view('administration.users.index', [
            ...$this->pageData($actor),
            'users' => $this->users->list($actor, $filters),
            'roles' => Rol::query()->where('activo', true)->orderBy('nombre')->get(),
            'filters' => $filters,
            'canManage' => $this->permissions->allows($actor, PermissionCode::UsersManage),
        ]);
    }

    public function createUser(Request $request): View
    {
        $actor = $this->authorize($request, PermissionCode::UsersManage);

        return view('administration.users.create', [
            ...$this->pageData($actor),
            'roles' => Rol::query()->where('activo', true)->orderBy('nombre')->get(),
        ]);
    }

    public function storeUser(StoreAdminUserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->user(), $request->validated());

        return redirect()->route('admin.users.show', $user)->with('success', 'Usuario creado correctamente.');
    }

    public function showUser(Request $request, User $usuario): View
    {
        $actor = $this->authorize($request, PermissionCode::UsersView);
        $usuario->load('rol');

        return view('administration.users.show', [
            ...$this->pageData($actor),
            'managedUser' => $usuario,
            'canManage' => $this->permissions->allows($actor, PermissionCode::UsersManage),
        ]);
    }

    public function editUser(Request $request, User $usuario): View
    {
        $actor = $this->authorize($request, PermissionCode::UsersManage);

        return view('administration.users.edit', [
            ...$this->pageData($actor),
            'managedUser' => $usuario->load('rol'),
            'roles' => Rol::query()->where('activo', true)->orderBy('nombre')->get(),
        ]);
    }

    public function updateUser(UpdateAdminUserRequest $request, User $usuario): RedirectResponse
    {
        try {
            $user = $this->users->update($request->user(), $usuario, $request->validated());
        } catch (OperationalAdminException $exception) {
            return back()->withInput()->withErrors(['operacion' => $exception->getMessage()]);
        }

        return redirect()->route('admin.users.show', $user)->with('success', 'Usuario actualizado correctamente.');
    }

    public function activateUser(Request $request, User $usuario): RedirectResponse
    {
        return $this->changeUserStatus($request, $usuario, true);
    }

    public function deactivateUser(Request $request, User $usuario): RedirectResponse
    {
        return $this->changeUserStatus($request, $usuario, false);
    }

    public function roles(Request $request): View
    {
        $actor = $this->authorize($request, PermissionCode::RolesView);
        $active = in_array($request->query('activo', 'todos'), ['todos', 'activos', 'inactivos'], true) ? $request->query('activo', 'todos') : 'todos';

        return view('administration.roles.index', [
            ...$this->pageData($actor),
            'roles' => $this->roles->list($actor, trim((string) $request->query('q', '')), $active),
            'filters' => ['q' => trim((string) $request->query('q', '')), 'activo' => $active],
            'canManage' => $this->permissions->allows($actor, PermissionCode::RolesManage),
        ]);
    }

    public function createRole(Request $request): View
    {
        $actor = $this->authorize($request, PermissionCode::RolesManage);

        return view('administration.roles.create', $this->pageData($actor));
    }

    public function storeRole(StoreRoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return redirect()->route('admin.roles.show', $role)->with('success', 'Rol creado correctamente.');
    }

    public function showRole(Request $request, Rol $rol): View
    {
        $actor = $this->authorize($request, PermissionCode::RolesView);
        $rol->load(['usuarios' => fn ($query) => $query->orderBy('apellido')->orderBy('nombre'), 'permisos' => fn ($query) => $query->orderBy('modulo')->orderBy('codigo')]);

        return view('administration.roles.show', [
            ...$this->pageData($actor),
            'role' => $rol,
            'permissions' => Permiso::query()->where('activo', true)->orderBy('modulo')->orderBy('codigo')->get(),
            'canManage' => $this->permissions->allows($actor, PermissionCode::RolesManage),
            'canManagePermissions' => $this->permissions->allows($actor, PermissionCode::RolePermissionsManage),
        ]);
    }

    public function editRole(Request $request, Rol $rol): View
    {
        $actor = $this->authorize($request, PermissionCode::RolesManage);

        return view('administration.roles.edit', [
            ...$this->pageData($actor),
            'role' => $rol,
        ]);
    }

    public function updateRole(UpdateRoleRequest $request, Rol $rol): RedirectResponse
    {
        try {
            $role = $this->roles->update($request->user(), $rol, $request->validated());
        } catch (OperationalAdminException $exception) {
            return back()->withInput()->withErrors(['operacion' => $exception->getMessage()]);
        }

        return redirect()->route('admin.roles.show', $role)->with('success', 'Rol actualizado correctamente.');
    }

    public function activateRole(Request $request, Rol $rol): RedirectResponse
    {
        return $this->changeRoleStatus($request, $rol, true);
    }

    public function deactivateRole(Request $request, Rol $rol): RedirectResponse
    {
        return $this->changeRoleStatus($request, $rol, false);
    }

    public function syncRolePermissions(SyncRolePermissionsRequest $request, Rol $rol): RedirectResponse
    {
        try {
            $this->roles->syncPermissions($request->user(), $rol, $request->validated('permissions', []));
        } catch (OperationalAdminException $exception) {
            return back()->withInput()->withErrors(['operacion' => $exception->getMessage()]);
        }

        return back()->with('success', 'Permisos del rol actualizados correctamente.');
    }

    public function permissions(Request $request): View
    {
        $actor = $this->authorize($request, PermissionCode::PermissionsView);
        $module = is_string($request->query('modulo')) ? trim($request->query('modulo')) : '';
        $term = trim((string) $request->query('q', ''));

        return view('administration.permissions.index', [
            ...$this->pageData($actor),
            'permissions' => $this->permissionCatalog->list($actor, $term, $module),
            'modules' => $this->permissionCatalog->modules($actor),
            'filters' => ['q' => $term, 'modulo' => $module],
        ]);
    }

    private function changeUserStatus(Request $request, User $user, bool $active): RedirectResponse
    {
        $this->authorize($request, PermissionCode::UsersManage);

        try {
            $this->users->setActive($request->user(), $user, $active);
        } catch (OperationalAdminException $exception) {
            return back()->withErrors(['operacion' => $exception->getMessage()]);
        }

        return back()->with('success', $active ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    private function changeRoleStatus(Request $request, Rol $role, bool $active): RedirectResponse
    {
        $this->authorize($request, PermissionCode::RolesManage);

        try {
            $this->roles->setActive($request->user(), $role, $active);
        } catch (OperationalAdminException $exception) {
            return back()->withErrors(['operacion' => $exception->getMessage()]);
        }

        return back()->with('success', $active ? 'Rol activado.' : 'Rol desactivado.');
    }

    private function authorize(Request $request, PermissionCode $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->permissions->authorize($actor, $permission);

        return $actor;
    }

    /** @return array{user: User} */
    private function pageData(User $user): array
    {
        return ['user' => $user];
    }
}
