@php($editing = $role !== null)
<form class="admin-form" method="POST" action="{{ $action }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    @if ($errors->any())<div class="form-alert" role="alert">Revisa los campos marcados antes de continuar.</div>@endif
    <div class="admin-form-grid"><label class="field-group"><span>Código</span><input class="form-input" name="codigo" value="{{ old('codigo', $role?->codigo) }}" required maxlength="50">@error('codigo')<small class="field-error">{{ $message }}</small>@enderror</label><label class="field-group"><span>Nombre</span><input class="form-input" name="nombre" value="{{ old('nombre', $role?->nombre) }}" required maxlength="100">@error('nombre')<small class="field-error">{{ $message }}</small>@enderror</label><label class="field-group admin-form-wide"><span>Descripción</span><textarea class="form-input" name="descripcion" rows="4">{{ old('descripcion', $role?->descripcion) }}</textarea>@error('descripcion')<small class="field-error">{{ $message }}</small>@enderror</label></div>
    <div class="admin-form-actions"><a class="secondary-button" href="{{ $cancel }}">Cancelar</a><button class="primary-button" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear rol' }}</button></div>
</form>
