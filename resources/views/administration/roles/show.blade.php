@extends('layouts.authenticated', ['title' => 'Detalle de rol | Almapan'])

@section('page')
    @php
        $savedIds = $role->permisos->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $oldSelection = session()->hasOldInput('_role_permissions') ? old('permissions', []) : $savedIds;
        $selectedIds = is_array($oldSelection) ? array_map('intval', array_filter($oldSelection, fn ($id) => (is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id)))) : [];
        $selected = $canManagePermissions ? $permissions->whereIn('id', $selectedIds) : $role->permisos->where('activo', true);
        $inactiveAssigned = $role->permisos->where('activo', false);
        $presentation = \App\Support\PermissionPresentation::class;
    @endphp
    <section class="admin-page role-administration-page" id="role-permissions-page" aria-labelledby="role-show-title"
        data-role-permissions data-saved-ids="{{ json_encode(array_map('strval', $savedIds)) }}"
        data-user-id="{{ $user->getKey() }}" data-role-id="{{ $role->getKey() }}"
        data-session-url="{{ route('admin.roles.show', $role) }}" data-session-minutes="{{ config('session.lifetime') }}">
        <header class="admin-page-header">
            <div><p class="eyebrow">{{ $role->activo ? 'Rol activo' : 'Rol inactivo' }}</p><h1 id="role-show-title">{{ $role->nombre }}</h1><p class="admin-intro">{{ $role->codigo }}</p></div>
            <div class="admin-header-actions">
                @if ($canManage)<a class="primary-button inline-button" href="{{ route('admin.roles.edit', $role) }}">Editar rol</a>@endif
                <a class="secondary-button inline-button" href="{{ route('admin.roles.index') }}">Volver a roles</a>
            </div>
        </header>
        @if (session('success'))<div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>@endif
        @if ($errors->any())
            <div class="form-alert" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
        @endif

        <div class="role-permissions-layout">
            @if ($canManagePermissions)
                <section class="role-permission-editor" aria-labelledby="permission-editor-title">
                    <h2 id="permission-editor-title">Gestionar permisos</h2>
                    <div class="role-permission-feedback" data-permission-feedback role="status" aria-live="polite" tabindex="-1" hidden></div>
                    <div class="role-permission-recovery" data-permission-recovery hidden>
                        <p>La selección se conserva solo en esta pestaña. No la cierres ni recargues.</p>
                        <a href="{{ route('login') }}" target="_blank" rel="noopener noreferrer">Iniciar sesión en otra pestaña</a>
                        <button type="button" class="secondary-button" data-permission-recheck>Comprobar sesión</button>
                    </div>
                    <form method="POST" action="{{ route('admin.roles.permissions.update', $role) }}" data-role-permission-form>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_role_permissions" value="1">
                        <input type="hidden" name="_editor_user_id" value="{{ $user->getKey() }}">
                        <div class="role-permission-toolbar">
                            <label class="field-group" for="permission-search"><span>Buscar permiso o acción</span>
                                <input class="form-input" type="search" id="permission-search" placeholder="Buscar permiso o acción..." autocomplete="off" data-permission-search>
                            </label>
                            <fieldset class="role-permission-filters">
                                <legend class="sr-only">Filtrar permisos</legend>
                                <label><input type="radio" name="permission_filter" value="all" data-permission-filter checked><span>Todos</span></label>
                                <label><input type="radio" name="permission_filter" value="assigned" data-permission-filter><span>Asignados</span></label>
                                <label><input type="radio" name="permission_filter" value="unassigned" data-permission-filter><span>Sin asignar</span></label>
                            </fieldset>
                            <p class="role-filter-count" data-permission-result-count role="status" aria-live="polite">{{ $permissions->count() }} permisos disponibles</p>
                        </div>
                        <div class="role-permission-groups">
                            @foreach ($permissions->groupBy('modulo') as $module => $modulePermissions)
                                <details class="role-permission-category" data-permission-category data-category-label="{{ $presentation::category($module) }}">
                                    <summary><span>{{ $presentation::category($module) }}</span><small data-category-count>{{ $modulePermissions->whereIn('id', $selectedIds)->count() }} seleccionados / {{ $modulePermissions->count() }} disponibles</small></summary>
                                    <div class="role-permission-options">
                                        @foreach ($modulePermissions as $permission)
                                            @php($description = $presentation::description($permission->codigo))
                                            <label class="role-permission-option" data-role-permission data-permission-name="{{ $permission->nombre }}" data-permission-code="{{ $permission->codigo }}" data-permission-description="{{ $description }}">
                                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array((int) $permission->id, $selectedIds, true))>
                                                <span><strong>{{ $permission->nombre }}</strong><small>{{ $description }}</small></span>
                                            </label>
                                        @endforeach
                                    </div>
                                </details>
                            @endforeach
                        </div>
                        <p class="role-permission-no-results" data-permission-no-results hidden>No hay permisos que coincidan con la búsqueda y el filtro.</p>
                        <div class="role-permission-actions">
                            <span data-permission-save-status role="status" aria-live="polite">{{ $selected->pluck('id')->sort()->values()->all() === collect($savedIds)->sort()->values()->all() ? 'Sin cambios pendientes' : 'Cambios sin guardar' }}</span>
                            <button class="primary-button" type="submit" data-save-role-permissions>Guardar permisos</button>
                        </div>
                    </form>
                </section>
            @endif

            <aside class="role-permission-context{{ $canManagePermissions ? '' : ' role-permission-context-readonly' }}">
                <section aria-labelledby="assigned-permissions-title" class="role-selection-summary">
                    <h2 id="assigned-permissions-title">{{ $canManagePermissions ? 'Selección de permisos' : 'Permisos asignados' }}</h2>
                    <p class="role-selected-total"><strong data-selected-total>{{ $selected->count() }}</strong> permisos activos {{ $canManagePermissions ? 'seleccionados' : 'asignados' }}</p>
                    <p class="admin-empty-copy" data-selected-empty @if($selected->isNotEmpty()) hidden @endif>Este rol todavía no tiene permisos asignados.</p>
                    <ul class="role-selected-categories" data-selected-categories>
                        @foreach ($selected->groupBy('modulo') as $module => $group)
                            <li><span>{{ $presentation::category($module) }}</span><strong>{{ $group->count() }}</strong></li>
                        @endforeach
                    </ul>
                    <details class="role-selection-preview" data-selection-preview @if($selected->isEmpty()) hidden @endif>
                        <summary>Ver permisos seleccionados</summary>
                        <ul data-selected-permissions>
                            @foreach ($selected as $permission)<li><strong>{{ $permission->nombre }}</strong><small>{{ $presentation::description($permission->codigo) }}</small></li>@endforeach
                        </ul>
                    </details>
                    @if ($inactiveAssigned->isNotEmpty())
                        <div class="role-permission-warning" role="note"><strong>{{ $inactiveAssigned->count() }} permisos inactivos vinculados</strong><p>No otorgan acceso. Al guardar se conservarán únicamente los permisos activos seleccionados.</p>
                            <ul>@foreach ($inactiveAssigned as $permission)<li>{{ $permission->nombre }}: {{ $presentation::description($permission->codigo) }}</li>@endforeach</ul>
                        </div>
                    @endif
                </section>
                <section class="role-associated-users" aria-labelledby="associated-users-title">
                    <h2 id="associated-users-title">Usuarios asociados <small>({{ $role->usuarios->count() }})</small></h2>
                    @if ($role->usuarios->isEmpty())<p class="admin-empty-copy">No hay usuarios asociados a este rol.</p>
                    @else<ul>@foreach ($role->usuarios as $managedUser)<li><strong>{{ $managedUser->nombre }} {{ $managedUser->apellido }}</strong><small>{{ $managedUser->nombre_usuario }} · {{ $managedUser->activo ? 'Activo' : 'Inactivo' }}</small></li>@endforeach</ul>@endif
                </section>
                @if ($canManage)
                    <section class="admin-actions-panel"><h2>Estado</h2><p>Desactivar conserva usuarios y permisos asociados.</p>
                        @if ($role->activo)<form method="POST" action="{{ route('admin.roles.deactivate', $role) }}">@csrf<button class="danger-button" type="submit">Desactivar rol</button></form>
                        @else<form method="POST" action="{{ route('admin.roles.activate', $role) }}">@csrf<button class="primary-button" type="submit">Activar rol</button></form>@endif
                    </section>
                @endif
            </aside>
        </div>
    </section>
@endsection
