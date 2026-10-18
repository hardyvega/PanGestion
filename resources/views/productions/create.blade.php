@extends('layouts.authenticated', ['title' => 'Iniciar producción | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="production-create-title">
        <header class="articles-page-header"><div><p class="eyebrow">Operación</p><h1 id="production-create-title">Iniciar producción</h1><p class="articles-intro">El stock se mantiene intacto hasta la confirmación.</p></div><a class="secondary-button inline-button" href="{{ route('productions.index') }}">Volver</a></header>
        @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
        <section class="articles-panel"><form class="article-form" method="POST" action="{{ route('productions.store') }}">@csrf
            <label class="field-group"><span>Receta vigente</span><select class="form-input" name="receta_id" data-production-recipe required><option value="">Selecciona una receta</option>@foreach ($recipes as $recipe)<option value="{{ $recipe->id }}" data-yield="{{ $recipe->rendimiento_cantidad }}">{{ $recipe->articulo->nombre }} · versión {{ $recipe->version }}</option>@endforeach</select></label>
            <div class="form-grid-two"><label class="field-group"><span>Cantidad producida</span><input class="form-input" type="text" name="cantidad_producida" value="{{ old('cantidad_producida', '1.000') }}" inputmode="decimal" required></label><label class="field-group"><span>Observación</span><input class="form-input" type="text" name="observacion" value="{{ old('observacion') }}" maxlength="5000"></label></div>
            @foreach ($recipes as $recipe)<fieldset class="production-consumption-set" data-recipe-fields="{{ $recipe->id }}" hidden><legend>{{ $recipe->articulo->nombre }} · consumos reales</legend><p class="form-help">Ajusta las cantidades reales que se consumirán al confirmar.</p>@foreach ($recipe->detalles as $detail)<label class="field-group"><span>{{ $detail->componente->nombre }} · {{ $detail->cantidad }} {{ $detail->componente->unidad?->simbolo }}</span><input class="form-input" type="text" name="consumos[{{ $detail->id }}]" value="{{ old('consumos.'.$detail->id, $detail->cantidad) }}" inputmode="decimal" disabled></label>@endforeach</fieldset>@endforeach
            <div class="article-form-actions"><button class="primary-button" type="submit">Crear borrador</button></div>
        </form></section>
    </section>
    @push('scripts')<script>
        (() => { const select = document.querySelector('[data-production-recipe]'); const output = document.querySelector('[name="cantidad_producida"]'); const sets = [...document.querySelectorAll('[data-recipe-fields]')]; const update = () => { sets.forEach((set) => { const active = set.dataset.recipeFields === select.value; set.hidden = !active; set.querySelectorAll('input').forEach((input) => { input.disabled = !active; }); }); const option = select.selectedOptions[0]; if (option?.dataset.yield && (!output.value || output.dataset.auto === '1')) { output.value = option.dataset.yield; output.dataset.auto = '1'; } }; select?.addEventListener('change', update); output?.addEventListener('input', () => { output.dataset.auto = '0'; }); update(); })();
    </script>@endpush
@endsection
