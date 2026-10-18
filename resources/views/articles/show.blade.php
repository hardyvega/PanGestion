@extends('layouts.authenticated', ['title' => 'Detalle de artículo | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="article-show-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">{{ $article->activo ? 'Artículo activo' : 'Artículo inactivo' }}</p><h1 id="article-show-title">{{ $article->nombre }}</h1><p class="articles-intro">SKU {{ $article->sku }}</p></div>
            <div class="article-header-actions">
                @if ($canManage)<a class="primary-button inline-button" href="{{ route('articles.edit', $article) }}">Editar artículo</a>@endif
                <a class="secondary-button inline-button" href="{{ route('articles.index') }}">Volver al catálogo</a>
            </div>
        </header>
        @if (session('success')) <div class="form-alert form-alert-success" role="status">{{ session('success') }}</div> @endif
        <div class="article-detail-layout">
            <div class="article-detail-main">
                <section class="articles-panel article-overview">
                    <div class="article-image-frame">
                        @if ($imageUrl)<img src="{{ $imageUrl }}" alt="Imagen de {{ $article->nombre }}">@else<span aria-hidden="true">▤</span><small>Sin imagen</small>@endif
                    </div>
                    <dl class="article-detail-grid">
                        <div><dt>Tipo</dt><dd>{{ ucfirst(str_replace('_', ' ', $article->tipo_articulo)) }}</dd></div>
                        <div><dt>Categoría</dt><dd>{{ $article->categoria?->nombre ?? 'Sin categoría' }}</dd></div>
                        <div><dt>Unidad</dt><dd>{{ $article->unidad?->nombre ?? 'Sin unidad' }}</dd></div>
                        <div><dt>Código de barras</dt><dd>{{ $article->codigo_barras ?: 'Sin código' }}</dd></div>
                        <div><dt>Stock actual</dt><dd>{{ $article->stock_actual }} <small>Solo lectura</small></dd></div>
                        <div><dt>Stock mínimo</dt><dd>{{ $article->stock_minimo }}</dd></div>
                        <div><dt>Precio vigente</dt><dd>{{ $article->precio_venta_actual !== null ? '$'.number_format((float) $article->precio_venta_actual, 2, ',', '.') : 'Sin precio' }}</dd></div>
                    </dl>
                    @if ($article->descripcion)<div class="article-description"><dt>Descripción</dt><p>{{ $article->descripcion }}</p></div>@endif
                </section>
                <section class="articles-panel" aria-labelledby="article-history-title">
                    <div class="articles-panel-heading"><div><h2 id="article-history-title">Historial de precios</h2><p>Los registros anteriores no se modifican.</p></div></div>
                    @if ($article->historialPrecios->isEmpty())
                        @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'Sin cambios de precio', 'message' => 'El historial aparecerá cuando se registre un precio.'])
                    @else
                        <div class="article-history-list">
                            @foreach ($article->historialPrecios->sortByDesc('id') as $history)
                                <div class="article-history-row"><div><strong>{{ $history->precio_anterior !== null ? '$'.number_format((float) $history->precio_anterior, 2, ',', '.') : 'Sin precio' }} → {{ $history->precio_nuevo !== null ? '$'.number_format((float) $history->precio_nuevo, 2, ',', '.') : 'Sin precio' }}</strong><small>{{ $history->motivo ?: 'Sin motivo indicado' }}</small></div><div><strong>{{ $history->vigente_desde?->timezone('America/Santiago')->format('d/m/Y H:i') }}</strong><small>{{ $history->usuario?->nombre ?? 'Usuario' }}</small></div></div>
                            @endforeach
                        </div>
                    @endif
                </section>
                @if ($canViewSuppliers)
                    <section class="articles-panel" aria-labelledby="article-suppliers-title">
                        <div class="articles-panel-heading"><div><h2 id="article-suppliers-title">Proveedores asociados</h2><p>Las asociaciones no modifican stock ni precios de venta.</p></div><a class="text-button" href="{{ route('suppliers.index') }}">Directorio</a></div>
                        @if ($articleSuppliers->isEmpty())
                            @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'Sin proveedores asociados', 'message' => 'La asociación se gestiona desde el detalle del proveedor.'])
                        @else
                            <div class="supplier-chip-list">@foreach ($articleSuppliers as $supplier)<a class="supplier-chip" href="{{ route('suppliers.show', $supplier) }}"><strong>{{ $supplier->nombre }}</strong><small>{{ $supplier->pivot->codigo_articulo_proveedor ?: $supplier->codigo }}</small></a>@endforeach</div>
                        @endif
                    </section>
                @endif
                @if ($canViewCosts)
                    <section class="articles-panel" aria-labelledby="article-costs-title">
                        <div class="articles-panel-heading"><div><h2 id="article-costs-title">Costo vigente e historial</h2><p>Solo se muestran costos originados por recepciones.</p></div></div>
                        <div class="supplier-current-cost"><span>Costo vigente</span><strong>{{ $currentCost !== null ? '$'.number_format((float) $currentCost, 2, ',', '.') : 'Sin costo' }}</strong></div>
                        @if ($articleCosts->isEmpty())
                            @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'Sin costos registrados', 'message' => 'El historial aparecerá cuando una recepción confirmada registre un costo.'])
                        @else
                            <div class="article-history-list">@foreach ($articleCosts as $cost)<div class="article-history-row"><div><strong>{{ $cost->costo_nuevo !== null ? '$'.number_format((float) $cost->costo_nuevo, 2, ',', '.') : 'Sin costo nuevo' }}</strong><small>{{ $cost->proveedor?->nombre ?? 'Origen interno' }} · Recepción #{{ $cost->detalle_recepcion_mercaderia_id ?? 'no disponible' }}</small></div><div><strong>{{ $cost->vigente_desde?->timezone('America/Santiago')->format('d/m/Y H:i') }}</strong><small>{{ $cost->detalleRecepcion?->recepcion?->estado?->value ?? 'Causal histórica' }}</small></div></div>@endforeach</div>
                            <div class="articles-pagination">{{ $articleCosts->links() }}</div>
                        @endif
                    </section>
                @endif
            </div>
            <aside class="article-detail-side">
                @if ($canManage)
                    <section class="articles-panel">
                        <div class="articles-panel-heading"><div><h2>Precio vigente</h2><p>Registra cada cambio en el historial.</p></div></div>
                        <form class="article-price-form" method="POST" action="{{ route('articles.prices.store', $article) }}">
                            @csrf
                            <label class="field-group"><span>Nuevo precio</span><input class="form-input" type="text" name="precio_venta" value="{{ $article->precio_venta_actual }}" inputmode="decimal"></label>
                            <label class="field-group"><span>Motivo</span><input class="form-input" type="text" name="motivo_precio" maxlength="1000"></label>
                            <button class="primary-button" type="submit">Guardar precio</button>
                        </form>
                    </section>
                    <section class="articles-panel article-actions-panel">
                        <h2>Estado</h2>
                        @if ($article->activo)
                            <p>El artículo está disponible para POS y pedidos si es vendible y tiene precio.</p>
                            <form method="POST" action="{{ route('articles.deactivate', $article) }}">@csrf<button class="danger-button" type="submit">Desactivar artículo</button></form>
                        @else
                            <p>El artículo permanece en el historial y no aparece como opción vendible.</p>
                            <form method="POST" action="{{ route('articles.activate', $article) }}">@csrf<button class="primary-button" type="submit">Activar artículo</button></form>
                        @endif
                    </section>
                @endif
            </aside>
        </div>
    </section>
@endsection
