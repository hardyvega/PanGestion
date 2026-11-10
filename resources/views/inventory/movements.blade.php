@extends('layouts.authenticated', ['title' => 'Movimientos | Almapan'])
@use('App\Support\InventoryQuantity')

@section('page')
<section class="articles-page inventory-page inventory-movements-page" aria-labelledby="movements-title">
    <header class="articles-page-header">
        <div>
            <p class="eyebrow">Inventario</p>
            <h1 id="movements-title">Movimientos</h1>
            <p class="articles-intro">Historial de cambios de stock y sus causas.</p>
        </div>
    </header>
    @if (session('success'))<div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
    <section class="articles-panel">
        <form class="articles-filter-grid" method="GET" action="{{ route('inventory.movements') }}">
            <div class="field-group movement-article-filter" data-movement-article-picker="{{ route('inventory.movements.articles') }}">
                <label for="movement-article-search">Buscar artículo</label>
                <div class="movement-article-input">
                    <input class="form-input" type="text" name="q" id="movement-article-search" value="{{ $articleSearch }}"
                        maxlength="150" autocomplete="off" spellcheck="false" placeholder="Nombre, SKU o código de barras"
                        role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="movement-article-suggestions">
                    <button class="secondary-button" type="button" aria-label="Limpiar artículo" title="Limpiar artículo" data-movement-article-clear hidden>
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <input type="hidden" name="articulo_id" value="{{ $filters['articulo_id'] ?? '' }}" disabled>
                <div class="movement-article-popup" hidden>
                    <div class="picker-results" id="movement-article-suggestions" role="listbox" aria-label="Artículos encontrados" hidden></div>
                    <p class="field-help" role="status"></p>
                </div>
            </div>
            <label class="field-group">
                <span>Tipo</span>
                <select class="form-input" name="tipo">
                    <option value="">Todos</option>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(($filters['tipo'] ?? '') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field-group"><span>Desde</span><input class="form-input" type="date" name="desde" value="{{ $filters['desde'] ?? '' }}"></label>
            <label class="field-group"><span>Hasta</span><input class="form-input" type="date" name="hasta" value="{{ $filters['hasta'] ?? '' }}"></label>
            <button class="secondary-button filter-submit" type="submit">Filtrar</button>
        </form>
    </section>
    <section class="articles-panel">
        <div class="articles-panel-heading">
            <div><h2>Historial operacional</h2><p>{{ $movements->total() }} movimientos encontrados</p></div>
        </div>
        @if ($movements->isEmpty())
            <p class="empty-state">No hay movimientos para los filtros seleccionados.</p>
        @else
            <div class="articles-table-wrap">
                <table class="articles-table movements-table" role="table">
                    <thead role="rowgroup"><tr role="row">
                        <th scope="col" role="columnheader">Fecha</th>
                        <th scope="col" role="columnheader">Artículo</th>
                        <th scope="col" role="columnheader">Movimiento</th>
                        <th scope="col" role="columnheader">Cambio de stock</th>
                        <th scope="col" role="columnheader">Stock resultante</th>
                        <th scope="col" role="columnheader">Causal</th>
                    </tr></thead>
                    <tbody role="rowgroup">
                        @foreach ($movements as $movement)
                            @php($cause = $movement->detalle_recepcion_mercaderia_id ? 'Recepción #'.$movement->detalle_recepcion_mercaderia_id : ($movement->merma_id ? 'Merma #'.$movement->merma_id : ($movement->detalle_conteo_inventario_id ? 'Conteo #'.$movement->detalle_conteo_inventario_id : ($movement->detalle_venta_id ? 'Venta #'.$movement->detalle_venta_id : 'Ajuste / otro'))))
                            <tr role="row">
                                <td data-label="Fecha" role="cell">{{ \Carbon\CarbonImmutable::parse($movement->ocurrido_en)->timezone('America/Santiago')->format('d/m/Y H:i') }}</td>
                                <td data-label="Artículo" role="cell"><span><strong>{{ $movement->nombre }}</strong><small>{{ $movement->sku }}</small></span></td>
                                <td data-label="Movimiento" role="cell">{{ str_replace('_', ' ', $movement->tipo_movimiento) }}</td>
                                <td data-label="Cambio de stock" role="cell" class="movement-quantity {{ str_starts_with((string) $movement->cantidad_delta, '-') ? 'amount-negative' : '' }}">{{ InventoryQuantity::format((string) $movement->cantidad_delta, $movement->unidad_simbolo_snapshot, signed: true) }}</td>
                                <td data-label="Stock resultante" role="cell" class="movement-quantity">{{ InventoryQuantity::format((string) $movement->stock_posterior, $movement->unidad_simbolo_snapshot) }}</td>
                                <td data-label="Causal" role="cell">{{ $cause }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="articles-pagination">{{ $movements->links() }}</div>
        @endif
    </section>
</section>
@endsection
