@php($editing = $worker !== null)
<form class="personnel-form" method="POST" action="{{ $editing ? route('personnel.update', $worker) : route('personnel.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    <div class="personnel-form-grid">
        <label class="field-group"><span>Código interno</span><input class="form-input" name="codigo" value="{{ old('codigo', $worker?->codigo) }}" required maxlength="50" autocomplete="off"><small>Se guarda normalizado en mayúsculas.</small></label>
        <label class="field-group"><span>Nombres</span><input class="form-input" name="nombres" value="{{ old('nombres', $worker?->nombres) }}" required maxlength="100"></label>
        <label class="field-group"><span>Apellidos</span><input class="form-input" name="apellidos" value="{{ old('apellidos', $worker?->apellidos) }}" required maxlength="100"></label>
        <label class="field-group"><span>Fecha de ingreso</span><input class="form-input" type="date" name="fecha_ingreso" value="{{ old('fecha_ingreso', $worker?->fecha_ingreso?->format('Y-m-d')) }}" required></label>
        <label class="field-group"><span>Fecha de término</span><input class="form-input" type="date" name="fecha_termino" value="{{ old('fecha_termino', $worker?->fecha_termino?->format('Y-m-d')) }}"><small>Déjala vacía si no tiene término definido.</small></label>
        <label class="field-group"><span>Usuario vinculado</span><select class="form-input" name="usuario_id"><option value="">Sin usuario vinculado</option>@foreach ($users as $userOption)<option value="{{ $userOption->id }}" @selected((string) old('usuario_id', $worker?->usuario_id) === (string) $userOption->id)>{{ $userOption->nombre }} {{ $userOption->apellido }} · {{ $userOption->nombre_usuario }}</option>@endforeach</select><small>Vincula un usuario existente; no crea cuentas.</small></label>
    </div>
    @if ($errors->any())<div class="form-alert form-alert-error" role="alert">{{ $errors->first() }}</div>@endif
    <div class="personnel-form-actions"><a class="secondary-button" href="{{ $editing ? route('personnel.show', $worker) : route('personnel.index') }}">Cancelar</a><button class="primary-button" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear trabajador' }}</button></div>
</form>
