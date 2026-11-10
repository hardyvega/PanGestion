@extends('layouts.authenticated', ['title' => 'Artículos | Almapan'])
@use('App\Support\InventoryQuantity')

@section('page')
    <section class="articles-page" aria-labelledby="articles-title">
        <header class="articles-page-header">
            <div>
                <p class="eyebrow">Administración</p>
                <h1 id="articles-title">Artículos</h1>
                <p class="articles-intro">Consulta y mantén el catálogo operativo de la panadería.</p>
            </div>
            @if ($canManage ?? false)
                <a class="primary-button inline-button" href="{{ route('articles.create') }}">Nuevo artículo</a>
            @endif
        </header>

        @if (session('success'))
            <div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>
        @endif

        <section class="articles-panel" aria-labelledby="articles-filter-title">
            <div class="articles-panel-heading">
                <div>
                    <h2 id="articles-filter-title">Buscar y filtrar</h2>
                    <p>El stock actual se muestra como información de inventario.</p>
                </div>
                <a class="text-button" href="{{ route('articles.index') }}">Limpiar</a>
            </div>
            <form class="articles-filter-grid" method="GET" action="{{ route('articles.index') }}">
                <label class="field-group articles-filter-search">
                    <span>Nombre, SKU, código o ID</span>
                    <input class="form-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Ej. marraqueta o 000123">
                </label>
                <label class="field-group">
                    <span>Estado</span>
                    <select class="form-input" name="activo">
                        <option value="todos" @selected($filters['activo'] === 'todos')>Todos</option>
                        <option value="activos" @selected($filters['activo'] === 'activos')>Activos</option>
                        <option value="inactivos" @selected($filters['activo'] === 'inactivos')>Inactivos</option>
                    </select>
                </label>
                <label class="field-group">
                    <span>Tipo</span>
                    <select class="form-input" name="tipo">
                        <option value="">Todos</option>
                        @foreach ($types as $type => $label)
                            <option value="{{ $type }}" @selected($filters['tipo'] === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field-group">
                    <span>Categoría</span>
                    <select class="form-input" name="categoria">
                        <option value="">Todas</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $filters['categoria'] === (string) $category->id)>{{ $category->nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="articles-check-field">
                    <input type="checkbox" name="stock_bajo" value="1" @checked($filters['stock_bajo'])>
                    <span>Stock bajo</span>
                </label>
                <button class="secondary-button filter-submit" type="submit">Aplicar filtros</button>
            </form>
        </section>

        <section class="articles-panel" aria-labelledby="articles-list-title">
            <div class="articles-panel-heading">
                <div>
                    <h2 id="articles-list-title">Catálogo</h2>
                    <p>{{ $articles->total() }} artículos encontrados</p>
                </div>
            </div>
            @if ($articles->isEmpty())
                @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'No hay artículos para mostrar', 'message' => 'Prueba con otros criterios o crea un artículo nuevo.'])
            @else
                <div class="articles-table-wrap">
                    <table class="articles-table">
                        <thead>
                            <tr>
                                <th>Artículo</th>
                                <th>Identificación</th>
                                <th>Tipo</th>
                                <th>Stock actual</th>
                                <th>Stock mínimo</th>
                                <th>Precio vigente</th>
                                <th>Estado</th>
                                <th><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($articles as $article)
                                <tr>
                                    <td data-label="Artículo">
                                        <strong>{{ $article->nombre }}</strong>
                                        <small>{{ $article->categoria?->nombre ?? 'Sin categoría' }} · {{ $article->unidad?->simbolo ?? 'Sin unidad' }}</small>
                                    </td>
                                    <td data-label="Identificación">
                                        <strong>{{ $article->sku }}</strong>
                                        <small>{{ $article->codigo_barras ?: 'Sin código de barras' }}</small>
                                    </td>
                                    <td data-label="Tipo">{{ $types[$article->tipo_articulo] ?? $article->tipo_articulo }}</td>
                                    <td data-label="Stock actual" class="{{ bccomp((string) $article->stock_actual, (string) $article->stock_minimo, 3) <= 0 ? 'article-stock-low' : '' }}">{{ InventoryQuantity::format((string) $article->stock_actual, $article->unidad?->simbolo) }}</td>
                                    <td data-label="Stock mínimo">{{ InventoryQuantity::format((string) $article->stock_minimo, $article->unidad?->simbolo) }}</td>
                                    <td data-label="Precio vigente">{{ $article->precio_venta_actual !== null ? '$'.number_format((float) $article->precio_venta_actual, 2, ',', '.') : 'Sin precio' }}</td>
                                    <td data-label="Estado"><span class="article-status {{ $article->activo ? 'article-status-active' : 'article-status-inactive' }}">{{ $article->activo ? 'Activo' : 'Inactivo' }}</span></td>
                                    <td data-label="Acciones"><a class="text-button" href="{{ route('articles.show', $article) }}">Ver detalle</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="articles-pagination">{{ $articles->links() }}</div>
            @endif
        </section>
    </section>
@endsection
