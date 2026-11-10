@extends('layouts.authenticated', ['title' => ($kind === 'units' ? 'Unidades de medida' : 'Categorías') . ' | Almapan'])

@section('page')
    @php
        $isUnit = $kind === 'units';
        $prefix = 'catalog.'.$kind;
        $label = $isUnit ? 'Unidades de medida' : 'Categorías';
    @endphp
    <section class="articles-page catalog-page" aria-labelledby="catalog-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Catálogo</p><h1 id="catalog-title">{{ $label }}</h1></div>
            <a class="primary-button inline-button" href="{{ route($prefix.'.create') }}">{{ $isUnit ? 'Nueva unidad de medida' : 'Nueva categoría' }}</a>
        </header>
        @if (session('success'))<div class="form-success" role="status">{{ session('success') }}</div>@endif
        @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
        <form class="catalog-filter-grid" method="GET" action="{{ route($prefix.'.index') }}">
            <label class="field-group"><span>Buscar</span><input class="form-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ $isUnit ? 'Nombre, código o símbolo' : 'Nombre o código' }}"></label>
            <label class="field-group"><span>Estado</span><select class="form-input" name="activo"><option value="todos" @selected($filters['activo'] === 'todos')>Todas</option><option value="activos" @selected($filters['activo'] === 'activos')>Activas</option><option value="inactivos" @selected($filters['activo'] === 'inactivos')>Inactivas</option></select></label>
            <button class="secondary-button" type="submit">Buscar</button>
            <a class="text-button" href="{{ route($prefix.'.index') }}">Limpiar</a>
        </form>
        <p class="field-help" role="status">{{ $entries->total() }} {{ $isUnit ? 'unidades de medida' : 'categorías' }}</p>
        @if ($entries->isEmpty())
            @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'No hay resultados', 'message' => $filters['q'] !== '' || $filters['activo'] !== 'todos' ? 'No hay coincidencias para estos filtros.' : ($isUnit ? 'Todavía no hay unidades de medida registradas.' : 'Todavía no hay categorías registradas.')])
        @else
            <div class="articles-table-wrap"><table class="articles-table catalog-table">
                <thead><tr><th>Nombre</th>@if ($isUnit)<th>Símbolo</th><th>Tipo de medida</th>@endif<th>Artículos</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td data-label="Nombre"><strong>{{ $entry->nombre }}</strong>@if (! $isUnit && $entry->descripcion)<small>{{ $entry->descripcion }}</small>@endif</td>
                            @if ($isUnit)<td data-label="Símbolo">{{ $entry->simbolo }}</td><td data-label="Tipo de medida">{{ \App\Support\ArticleCatalogRules::MAGNITUDES[$entry->magnitud] }}</td>@endif
                            <td data-label="Artículos">{{ $entry->articulos_count }}</td>
                            <td data-label="Estado"><span class="article-status {{ $entry->activo ? 'article-status-active' : 'article-status-inactive' }}">{{ $entry->activo ? 'Activa' : 'Inactiva' }}</span></td>
                            <td data-label="Acciones"><div class="catalog-actions">
                                <a class="catalog-icon-action" href="{{ route($prefix.'.edit', $entry) }}" title="Editar {{ $entry->nombre }}"><span aria-hidden="true">✎</span><span class="sr-only">Editar {{ $entry->nombre }}</span></a>
                                <form method="POST" action="{{ route($prefix.($entry->activo ? '.deactivate' : '.activate'), $entry) }}">
                                    @csrf
                                    <button class="catalog-icon-action" type="submit" title="{{ $entry->activo ? 'Desactivar' : 'Activar' }} {{ $entry->nombre }}"><span aria-hidden="true">{{ $entry->activo ? '⊘' : '✓' }}</span><span class="sr-only">{{ $entry->activo ? 'Desactivar' : 'Activar' }} {{ $entry->nombre }}</span></button>
                                </form>
                            </div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="articles-pagination">{{ $entries->links() }}</div>
        @endif
    </section>
@endsection
