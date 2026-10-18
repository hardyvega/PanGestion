@extends('layouts.authenticated', ['title' => 'Recetas | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="recipes-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Administración</p><h1 id="recipes-title">Recetas</h1><p class="articles-intro">Versiones de ingredientes y rendimientos para productos elaborados.</p></div>
            @if ($canManage)<a class="primary-button inline-button" href="{{ route('recipes.create') }}">Nueva receta</a>@endif
        </header>
        @if (session('success'))<div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>@endif
        <section class="articles-panel" aria-labelledby="recipes-filter-title">
            <div class="articles-panel-heading"><div><h2 id="recipes-filter-title">Buscar y filtrar</h2><p>Consulta por artículo, SKU o estado de vigencia.</p></div><a class="text-button" href="{{ route('recipes.index') }}">Limpiar</a></div>
            <form class="articles-filter-grid" method="GET" action="{{ route('recipes.index') }}">
                <label class="field-group articles-filter-search"><span>Artículo o SKU</span><input class="form-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Ej. marraqueta"></label>
                <label class="field-group"><span>Vigencia</span><select class="form-input" name="estado"><option value="todas" @selected($filters['estado'] === 'todas')>Todas</option><option value="activas" @selected($filters['estado'] === 'activas')>Vigentes</option><option value="inactivas" @selected($filters['estado'] === 'inactivas')>Históricas</option></select></label>
                <button class="secondary-button filter-submit" type="submit">Aplicar filtros</button>
            </form>
        </section>
        <section class="articles-panel" aria-labelledby="recipes-list-title">
            <div class="articles-panel-heading"><div><h2 id="recipes-list-title">Versiones registradas</h2><p>{{ $recipes->total() }} recetas encontradas</p></div></div>
            @if ($recipes->isEmpty())
                @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'No hay recetas para mostrar', 'message' => 'Crea una receta para comenzar a producir artículos elaborados.'])
            @else
                <div class="articles-table-wrap"><table class="articles-table"><thead><tr><th>Producto</th><th>Versión</th><th>Rendimiento</th><th>Ingredientes</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
                    @foreach ($recipes as $recipe)<tr><td data-label="Producto"><strong>{{ $recipe->articulo->nombre }}</strong><small>{{ $recipe->articulo->sku }}</small></td><td data-label="Versión">v{{ $recipe->version }}</td><td data-label="Rendimiento">{{ $recipe->rendimiento_cantidad }} {{ $recipe->articulo->unidad?->simbolo }}</td><td data-label="Ingredientes">{{ $recipe->detalles_count }}</td><td data-label="Estado"><span class="article-status {{ $recipe->activa ? 'article-status-active' : 'article-status-inactive' }}">{{ $recipe->activa ? 'Vigente' : 'Histórica' }}</span></td><td data-label="Acciones"><a class="text-button" href="{{ route('recipes.show', $recipe) }}">Ver detalle</a></td></tr>@endforeach
                </tbody></table></div><div class="articles-pagination">{{ $recipes->links() }}</div>
            @endif
        </section>
    </section>
@endsection
