@php($editing = $supplier !== null)
<form class="article-form" method="POST" action="{{ $editing ? route('suppliers.update', $supplier) : route('suppliers.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    <div class="article-form-grid">
        <label class="field-group"><span>Código</span><input class="form-input" type="text" name="codigo" value="{{ old('codigo', $supplier?->codigo) }}" maxlength="50" required><small class="field-help">Minúsculas, números, guion y guion bajo.</small>@error('codigo')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Nombre</span><input class="form-input" type="text" name="nombre" value="{{ old('nombre', $supplier?->nombre) }}" maxlength="150" required>@error('nombre')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Persona de contacto</span><input class="form-input" type="text" name="nombre_contacto" value="{{ old('nombre_contacto', $supplier?->nombre_contacto) }}" maxlength="150">@error('nombre_contacto')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Teléfono</span><input class="form-input" type="text" name="telefono" value="{{ old('telefono', $supplier?->telefono) }}" maxlength="50">@error('telefono')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Correo</span><input class="form-input" type="email" name="correo" value="{{ old('correo', $supplier?->correo) }}" maxlength="254">@error('correo')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group article-form-wide"><span>Observación</span><textarea class="form-input" name="observacion" rows="4">{{ old('observacion', $supplier?->observacion) }}</textarea>@error('observacion')<small class="field-error">{{ $message }}</small>@enderror</label>
    </div>
    <div class="article-form-actions"><button class="primary-button" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear proveedor' }}</button></div>
</form>
