@extends('layouts.authenticated', ['title' => $previous ? 'Nueva versión | Almapan' : 'Nueva receta | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="recipe-create-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Administración</p><h1 id="recipe-create-title">{{ $previous ? 'Nueva versión de receta' : 'Nueva receta' }}</h1><p class="articles-intro">Las versiones anteriores permanecen intactas.</p></div>
            <a class="secondary-button inline-button" href="{{ $previous ? route('recipes.show', $previous) : route('recipes.index') }}">Volver</a>
        </header>
        @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
        <section class="articles-panel">
            <form class="article-form" method="POST" action="{{ $previous ? route('recipes.versions.store', $previous) : route('recipes.store') }}">
                @csrf
                <input type="hidden" name="articulo_id" value="{{ old('articulo_id', $previous?->articulo_id) }}">
                <div class="form-grid-two">
                    <label class="field-group"><span>Producto elaborado</span><select class="form-input" data-recipe-product @disabled($previous)><option value="">Selecciona un producto</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected((string) old('articulo_id', $previous?->articulo_id) === (string) $product->id)>{{ $product->nombre }} · {{ $product->sku }}</option>@endforeach</select></label>
                    <label class="field-group"><span>Rendimiento</span><input class="form-input" type="text" name="rendimiento_cantidad" value="{{ old('rendimiento_cantidad', $previous?->rendimiento_cantidad ?? '1.000') }}" inputmode="decimal" required></label>
                </div>
                <label class="field-group"><span>Observación</span><textarea class="form-input" name="observacion" rows="3">{{ old('observacion', $previous?->observacion) }}</textarea></label>
                <div class="articles-panel-heading"><div><h2>Ingredientes</h2><p>Solo materias primas activas. La cantidad usa hasta tres decimales.</p></div><button class="secondary-button" type="button" data-add-component>Agregar ingrediente</button></div>
                <div class="recipe-component-list" data-component-list>
                    @php($previousDetails = $previous?->detalles ?? collect())
                    @forelse ($previousDetails as $index => $detail)
                        <div class="recipe-component-row" data-component-row><label class="field-group"><span>Materia prima</span><select class="form-input" name="componentes[{{ $index }}][articulo_componente_id]" required><option value="">Selecciona un ingrediente</option>@foreach ($components as $component)<option value="{{ $component->id }}" @selected((int) $detail->articulo_componente_id === (int) $component->id)>{{ $component->nombre }} · {{ $component->sku }}</option>@endforeach</select></label><label class="field-group"><span>Cantidad</span><input class="form-input" type="text" name="componentes[{{ $index }}][cantidad]" value="{{ $detail->cantidad }}" inputmode="decimal" required></label><button class="danger-button" type="button" data-remove-component>Quitar</button></div>
                    @empty
                        <div class="recipe-component-row" data-component-row><label class="field-group"><span>Materia prima</span><select class="form-input" name="componentes[0][articulo_componente_id]" required><option value="">Selecciona un ingrediente</option>@foreach ($components as $component)<option value="{{ $component->id }}">{{ $component->nombre }} · {{ $component->sku }}</option>@endforeach</select></label><label class="field-group"><span>Cantidad</span><input class="form-input" type="text" name="componentes[0][cantidad]" value="1.000" inputmode="decimal" required></label><button class="danger-button" type="button" data-remove-component>Quitar</button></div>
                    @endforelse
                </div>
                <label class="checkbox-field"><input type="checkbox" name="activa" value="1" @checked(old('activa', true))><span>Dejar esta versión vigente</span></label>
                <div class="article-form-actions"><button class="primary-button" type="submit">{{ $previous ? 'Crear versión' : 'Crear receta' }}</button></div>
            </form>
        </section>
    </section>
    <template data-component-template><div class="recipe-component-row" data-component-row><label class="field-group"><span>Materia prima</span><select class="form-input" data-component-select required><option value="">Selecciona un ingrediente</option>@foreach ($components as $component)<option value="{{ $component->id }}">{{ $component->nombre }} · {{ $component->sku }}</option>@endforeach</select></label><label class="field-group"><span>Cantidad</span><input class="form-input" type="text" data-component-quantity value="1.000" inputmode="decimal" required></label><button class="danger-button" type="button" data-remove-component>Quitar</button></div></template>
    @push('scripts')<script>
        (() => { const list = document.querySelector('[data-component-list]'); const template = document.querySelector('[data-component-template]'); let index = list.querySelectorAll('[data-component-row]').length; document.querySelector('[data-add-component]')?.addEventListener('click', () => { const row = template.content.cloneNode(true); row.querySelector('[data-component-select]').name = `componentes[${index}][articulo_componente_id]`; row.querySelector('[data-component-quantity]').name = `componentes[${index}][cantidad]`; index += 1; list.appendChild(row); }); list.addEventListener('click', (event) => { if (event.target.closest('[data-remove-component]') && list.querySelectorAll('[data-component-row]').length > 1) event.target.closest('[data-component-row]').remove(); }); document.querySelector('[data-recipe-product]')?.addEventListener('change', (event) => { document.querySelector('input[name="articulo_id"]').value = event.target.value; }); })();
    </script>@endpush
@endsection
