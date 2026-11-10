@extends('layouts.authenticated', ['title' => 'Ajuste de inventario | Almapan'])
@use('App\Support\InventoryQuantity')

@section('page')
<section class="articles-page inventory-page" aria-labelledby="adjustment-title">
    <header class="articles-page-header">
        <div>
            <p class="eyebrow">Inventario</p>
            <h1 id="adjustment-title">Ajuste de inventario</h1>
            <p class="articles-intro">Cada ajuste genera un movimiento causal; el stock se actualiza solo por el servicio aprobado.</p>
        </div>
        <a class="secondary-button inline-button" href="{{ route('inventory.index') }}">Volver</a>
    </header>
    @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
    <section class="articles-panel">
        <form class="article-form" method="POST" action="{{ route('inventory.adjustments.store') }}" data-adjustment-form>
            @csrf
            <label class="field-group">
                <span>Tipo de ajuste</span>
                <select class="form-input" name="tipo" required>
                    <option value="entrada" @selected(old('tipo') === 'entrada' && $canInput)>Entrada de inventario</option>
                    <option value="salida" @selected(old('tipo') === 'salida' || (!$canInput && $canOutput))>Salida de inventario</option>
                </select>
            </label>
            <div class="field-group adjustment-picker">
                <label class="field-group" data-adjustment-fallback>
                    <span>Artículo</span>
                    <select class="form-input" name="articulo_id" required>
                        <option value="">Selecciona un artículo</option>
                        @foreach ($articles as $article)
                            @php($stockLabel = InventoryQuantity::format((string) $article->stock_actual, $article->unidad?->simbolo))
                            <option value="{{ $article->id }}" data-name="{{ $article->nombre }}" data-sku="{{ $article->sku }}"
                                data-barcode="{{ $article->codigo_barras }}" data-stock="{{ $article->stock_actual }}"
                                data-unit="{{ $article->unidad?->simbolo }}" data-stock-label="{{ $stockLabel }}" @selected(old('articulo_id') == $article->id)>
                                {{ $article->nombre }} · {{ $article->sku }} · stock {{ $stockLabel }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <div class="field-group" data-adjustment-search hidden>
                    <label for="adjustment-article">Artículo</label>
                    <div class="adjustment-search-row">
                        <input class="form-input" id="adjustment-article" type="text" role="combobox" aria-autocomplete="list" aria-required="true"
                            aria-controls="adjustment-suggestions" aria-expanded="false" aria-describedby="adjustment-search-status{{ $errors->has('articulo_id') ? ' adjustment-article-error' : '' }}"
                            @if ($errors->has('articulo_id')) aria-invalid="true" @endif
                            autocomplete="off" spellcheck="false" placeholder="Nombre, SKU o código de barras" disabled>
                        <button class="secondary-button adjustment-clear" type="button" aria-label="Limpiar artículo" title="Limpiar artículo" data-adjustment-clear>
                            <span aria-hidden="true">×</span>
                        </button>
                    </div>
                    <div class="picker-results adjustment-suggestions" id="adjustment-suggestions" role="listbox" aria-label="Artículos encontrados" hidden></div>
                    <p class="field-help" id="adjustment-search-status" role="status"></p>
                </div>
                @error('articulo_id')<small class="field-error" id="adjustment-article-error">{{ $message }}</small>@enderror
            </div>
            <div class="form-grid-two">
                <label class="field-group"><span>Cantidad</span><input class="form-input" type="text" name="cantidad" value="{{ old('cantidad') }}" inputmode="decimal" required></label>
                <label class="field-group"><span>Fecha y hora de Santiago (opcional)</span><input class="form-input" type="datetime-local" name="ocurrido_en" value="{{ old('ocurrido_en') }}"></label>
                <x-ui.commercial-time-occurrence field="ocurrido_en" label="Hora del ajuste" />
            </div>
            <label class="field-group"><span>Motivo</span><textarea class="form-input" name="motivo" rows="4" required>{{ old('motivo') }}</textarea></label>
            <button class="primary-button" type="submit">Registrar ajuste</button>
        </form>
    </section>
</section>
@endsection
