@extends('layouts.authenticated', ['title' => 'Proveedores | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="suppliers-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Administración</p><h1 id="suppliers-title">Proveedores</h1><p class="articles-intro">Consulta proveedores y sus artículos asociados.</p></div>
            @if ($canManage)<a class="primary-button inline-button" href="{{ route('suppliers.create') }}">Nuevo proveedor</a>@endif
        </header>
        @if (session('success'))<div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>@endif
        <section class="articles-panel" aria-labelledby="suppliers-filter-title">
            <div class="articles-panel-heading"><div><h2 id="suppliers-filter-title">Buscar y filtrar</h2><p>La búsqueda usa únicamente campos maestros del proveedor.</p></div><a class="text-button" href="{{ route('suppliers.index') }}">Limpiar</a></div>
            <form class="articles-filter-grid supplier-filter-grid" method="GET" action="{{ route('suppliers.index') }}">
                <label class="field-group articles-filter-search"><span>Nombre, código o contacto</span><input class="form-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Ej. proveedor_prueba"></label>
                <label class="field-group"><span>Estado</span><select class="form-input" name="activo"><option value="todos" @selected($filters['activo'] === 'todos')>Todos</option><option value="activos" @selected($filters['activo'] === 'activos')>Activos</option><option value="inactivos" @selected($filters['activo'] === 'inactivos')>Inactivos</option></select></label>
                <button class="secondary-button filter-submit" type="submit">Aplicar filtros</button>
            </form>
        </section>
        <section class="articles-panel" aria-labelledby="suppliers-list-title">
            <div class="articles-panel-heading"><div><h2 id="suppliers-list-title">Directorio</h2><p>{{ $suppliers->total() }} proveedores encontrados</p></div></div>
            @if ($suppliers->isEmpty())
                @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'No hay proveedores para mostrar', 'message' => 'Prueba con otros criterios o crea un proveedor nuevo.'])
            @else
                <div class="articles-table-wrap"><table class="articles-table supplier-table"><thead><tr><th>Proveedor</th><th>Contacto</th><th>Artículos</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
                    @foreach ($suppliers as $supplier)<tr><td data-label="Proveedor"><strong>{{ $supplier->nombre }}</strong><small>{{ $supplier->codigo }}</small></td><td data-label="Contacto"><strong>{{ $supplier->nombre_contacto ?: 'Sin contacto' }}</strong><small>{{ $supplier->correo ?: ($supplier->telefono ?: 'Sin datos') }}</small></td><td data-label="Artículos">{{ $supplier->articulos_activos_count }}</td><td data-label="Estado"><span class="article-status {{ $supplier->activo ? 'article-status-active' : 'article-status-inactive' }}">{{ $supplier->activo ? 'Activo' : 'Inactivo' }}</span></td><td data-label="Acciones"><a class="text-button" href="{{ route('suppliers.show', $supplier) }}">Ver detalle</a></td></tr>@endforeach
                </tbody></table></div><div class="articles-pagination">{{ $suppliers->links() }}</div>
            @endif
        </section>
    </section>
@endsection
