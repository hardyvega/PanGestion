@extends('layouts.authenticated', ['title' => 'Usuarios | Almapan'])

@section('page')
    <section class="admin-page" aria-labelledby="users-title">
        <header class="admin-page-header">
            <div><p class="eyebrow">Administración</p><h1 id="users-title">Usuarios</h1><p class="admin-intro">Gestiona cuentas, roles y estados de acceso.</p></div>
            @if ($canManage)<a class="primary-button inline-button" href="{{ route('admin.users.create') }}">Nuevo usuario</a>@endif
        </header>
        @if (session('success'))<div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>@endif
        @if ($errors->has('operacion'))<div class="form-alert" role="alert">{{ $errors->first('operacion') }}</div>@endif
        <section class="admin-panel" aria-labelledby="users-filter-title">
            <div class="admin-panel-heading"><div><h2 id="users-filter-title">Buscar y filtrar</h2><p>La búsqueda consulta identidad, usuario y correo.</p></div><a class="text-button" href="{{ route('admin.users.index') }}">Limpiar</a></div>
            <form class="admin-filter-grid" method="GET" action="{{ route('admin.users.index') }}">
                <label class="field-group admin-filter-search"><span>Buscar usuario</span><input class="form-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Nombre o nombre de usuario"></label>
                <label class="field-group"><span>Estado</span><select class="form-input" name="activo"><option value="todos" @selected($filters['activo'] === 'todos')>Todos</option><option value="activos" @selected($filters['activo'] === 'activos')>Activos</option><option value="inactivos" @selected($filters['activo'] === 'inactivos')>Inactivos</option></select></label>
                <label class="field-group"><span>Rol</span><select class="form-input" name="rol_id"><option value="" @selected($filters['rol_id'] === '')>Todos</option>@foreach ($roles as $role)<option value="{{ $role->id }}" @selected($filters['rol_id'] === (string) $role->id)>{{ $role->nombre }}</option>@endforeach</select></label>
                <button class="secondary-button filter-submit" type="submit">Aplicar filtros</button>
            </form>
        </section>
        <section class="admin-panel" aria-labelledby="users-list-title">
            <div class="admin-panel-heading"><div><h2 id="users-list-title">Directorio</h2><p>{{ $users->total() }} usuarios encontrados</p></div></div>
            @if ($users->isEmpty())
                @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'No hay usuarios para mostrar', 'message' => 'Prueba con otros criterios o crea un usuario nuevo.'])
            @else
                <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Usuario</th><th>Rol</th><th>Correo</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
                    @foreach ($users as $managedUser)<tr><td data-label="Usuario"><strong>{{ $managedUser->nombre }} {{ $managedUser->apellido }}</strong><small>{{ $managedUser->nombre_usuario }}</small></td><td data-label="Rol">{{ $managedUser->rol?->nombre ?? 'Sin rol' }}</td><td data-label="Correo">{{ $managedUser->correo ?: 'Sin correo' }}</td><td data-label="Estado"><span class="admin-status {{ $managedUser->activo ? 'admin-status-active' : 'admin-status-inactive' }}">{{ $managedUser->activo ? 'Activo' : 'Inactivo' }}</span></td><td data-label="Acciones"><a class="text-button" href="{{ route('admin.users.show', $managedUser) }}">Ver detalle</a></td></tr>@endforeach
                </tbody></table></div><div class="admin-pagination">{{ $users->links() }}</div>
            @endif
        </section>
    </section>
@endsection
