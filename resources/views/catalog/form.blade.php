@extends('layouts.authenticated', ['title' => ($kind === 'units' ? 'Unidad de medida' : 'Categoría') . ' | Almapan'])

@section('page')
    @php
        $isUnit = $kind === 'units';
        $editing = $entry !== null;
        $prefix = 'catalog.'.$kind;
        $protectedUnit = $isUnit && ($unitInUse ?? false);
    @endphp
    <section class="articles-page catalog-page" aria-labelledby="catalog-form-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Catálogo</p><h1 id="catalog-form-title">{{ $isUnit ? ($editing ? 'Editar unidad de medida' : 'Nueva unidad de medida') : ($editing ? 'Editar categoría' : 'Nueva categoría') }}</h1></div>
            <a class="secondary-button inline-button" href="{{ route($prefix.'.index') }}">Volver al listado</a>
        </header>
        @if ($errors->any())<div class="form-alert" role="alert">Revisa los campos marcados. {{ $errors->first() }}</div>@endif
        @if ($editing)<p><span class="article-status {{ $entry->activo ? 'article-status-active' : 'article-status-inactive' }}">{{ $entry->activo ? 'Activa' : 'Inactiva' }}</span></p>@endif
        @if ($protectedUnit)<div class="form-alert" role="status">Esta unidad tiene artículos asociados. El código, símbolo y tipo de medida están protegidos para conservar las cantidades existentes.</div>@endif
        <form class="article-form" method="POST" action="{{ $editing ? route($prefix.'.update', $entry) : route($prefix.'.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="article-form-grid">
                <label class="field-group"><span>Código</span><input class="form-input" name="codigo" value="{{ $protectedUnit ? $entry->codigo : old('codigo', $entry?->codigo) }}" maxlength="50" @readonly($protectedUnit) required>@error('codigo')<small class="field-error">{{ $message }}</small>@enderror</label>
                <label class="field-group"><span>Nombre</span><input class="form-input" name="nombre" value="{{ old('nombre', $entry?->nombre) }}" maxlength="100" required>@error('nombre')<small class="field-error">{{ $message }}</small>@enderror</label>
                @if ($isUnit)
                    <label class="field-group"><span>Símbolo</span><input class="form-input" name="simbolo" value="{{ $protectedUnit ? $entry->simbolo : old('simbolo', $entry?->simbolo) }}" maxlength="20" @readonly($protectedUnit) required>@error('simbolo')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label class="field-group"><span>Tipo de medida</span>
                        @if ($protectedUnit)<input type="hidden" name="magnitud" value="{{ $entry->magnitud }}">@endif
                        <select class="form-input" name="magnitud" @disabled($protectedUnit) required>
                            <option value="">Selecciona un tipo de medida</option>
                            @foreach (\App\Support\ArticleCatalogRules::MAGNITUDES as $value => $name)<option value="{{ $value }}" @selected(($protectedUnit ? $entry->magnitud : old('magnitud', $entry?->magnitud)) === $value)>{{ $name }}</option>@endforeach
                        </select>
                        @error('magnitud')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                @else
                    <label class="field-group article-form-wide"><span>Descripción <small>(opcional)</small></span><textarea class="form-input" name="descripcion" rows="4">{{ old('descripcion', $entry?->descripcion) }}</textarea>@error('descripcion')<small class="field-error">{{ $message }}</small>@enderror</label>
                @endif
            </div>
            <div class="article-form-actions"><button class="primary-button" type="submit">{{ $editing ? 'Guardar cambios' : ($isUnit ? 'Crear unidad' : 'Crear categoría') }}</button></div>
        </form>
    </section>
@endsection
