@php($editing = isset($article))
<div class="article-form-grid">
    <label class="field-group">
        <span>SKU</span>
        <input class="form-input" type="text" name="sku" value="{{ old('sku', $article->sku ?? '') }}" maxlength="50" required>
        @error('sku') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group">
        <span>Nombre</span>
        <input class="form-input" type="text" name="nombre" value="{{ old('nombre', $article->nombre ?? '') }}" maxlength="150" required>
        @error('nombre') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group">
        <span>Tipo de artículo</span>
        <select class="form-input" name="tipo_articulo" required>
            @foreach ($types as $type => $label)
                <option value="{{ $type }}" @selected(old('tipo_articulo', $article->tipo_articulo ?? '') === $type)>{{ $label }}</option>
            @endforeach
        </select>
        @error('tipo_articulo') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group">
        <span>Categoría</span>
        <select class="form-input" name="categoria_articulo_id" required>
            <option value="">Selecciona una categoría</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('categoria_articulo_id', $article->categoria_articulo_id ?? '') === (string) $category->id)>{{ $category->nombre }}{{ $category->activo ? '' : ' (inactiva, asignada)' }}</option>
            @endforeach
        </select>
        @error('categoria_articulo_id') <small class="field-error">{{ $message }}</small> @enderror
        @if ($categories->isEmpty())
            <small class="field-help">No hay categorías disponibles. Crea una categoría antes de registrar artículos. @can('articulos.gestionar')<a class="text-button" href="{{ route('catalog.categories.create') }}">Crear categoría</a>@endcan</small>
        @elseif ($editing && ! $article->categoria?->activo)
            <small class="field-help">La categoría asignada está inactiva. Puedes conservarla o seleccionar una activa.</small>
        @endif
    </label>
    <label class="field-group">
        <span>Unidad de medida</span>
        <select class="form-input" name="unidad_id" required>
            <option value="">Selecciona una unidad</option>
            @foreach ($units as $unit)
                <option value="{{ $unit->id }}" @selected((string) old('unidad_id', $article->unidad_id ?? '') === (string) $unit->id)>{{ $unit->nombre }} ({{ $unit->simbolo }}){{ $unit->activo ? '' : ' (inactiva, asignada)' }}</option>
            @endforeach
        </select>
        @error('unidad_id') <small class="field-error">{{ $message }}</small> @enderror
        @if ($units->isEmpty())
            <small class="field-help">No hay unidades de medida disponibles. Crea una unidad antes de registrar artículos. @can('articulos.gestionar')<a class="text-button" href="{{ route('catalog.units.create') }}">Crear unidad de medida</a>@endcan</small>
        @elseif ($editing && ! $article->unidad?->activo)
            <small class="field-help">La unidad asignada está inactiva. Puedes conservarla o seleccionar una activa.</small>
        @endif
    </label>
    <label class="field-group">
        <span>Código de barras</span>
        <input class="form-input" type="text" name="codigo_barras" value="{{ old('codigo_barras', $article->codigo_barras ?? '') }}" maxlength="64" inputmode="numeric">
        <small class="field-help">Se conserva como texto, incluidos ceros iniciales.</small>
        @error('codigo_barras') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group">
        <span>Stock mínimo</span>
        <input class="form-input" type="text" name="stock_minimo" value="{{ old('stock_minimo', $article->stock_minimo ?? '0.000') }}" inputmode="decimal" required>
        @error('stock_minimo') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group">
        <span>Precio de venta vigente</span>
        <input class="form-input" type="text" name="precio_venta" value="{{ old('precio_venta', $article->precio_venta_actual ?? '') }}" inputmode="decimal">
        @error('precio_venta') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group article-form-wide">
        <span>Descripción</span>
        <textarea class="form-input" name="descripcion" rows="4">{{ old('descripcion', $article->descripcion ?? '') }}</textarea>
        @error('descripcion') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group article-form-wide">
        <span>Motivo del precio</span>
        <input class="form-input" type="text" name="motivo_precio" value="{{ old('motivo_precio') }}" maxlength="1000" placeholder="Opcional, queda en el historial">
        @error('motivo_precio') <small class="field-error">{{ $message }}</small> @enderror
    </label>
    <label class="field-group article-form-wide">
        <span>Imagen del artículo</span>
        <input class="form-input" type="file" name="imagen" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
        <small class="field-help">JPG, PNG o WEBP. Máximo 2 MB.</small>
        @if ($editing && ($article->imagen_ruta ?? null))
            <label class="articles-check-field"><input type="checkbox" name="quitar_imagen" value="1"><span>Quitar imagen actual</span></label>
        @endif
        @error('imagen') <small class="field-error">{{ $message }}</small> @enderror
    </label>
</div>
