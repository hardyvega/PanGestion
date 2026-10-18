@php($editing = $managedUser !== null)
<form class="admin-form" method="POST" action="{{ $action }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    @if ($errors->any())<div class="form-alert" role="alert">Revisa los campos marcados antes de continuar.</div>@endif
    <div class="admin-form-grid">
        <label class="field-group"><span>Nombre</span><input class="form-input" name="nombre" value="{{ old('nombre', $managedUser?->nombre) }}" required maxlength="100">@error('nombre')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Apellido</span><input class="form-input" name="apellido" value="{{ old('apellido', $managedUser?->apellido) }}" required maxlength="100">@error('apellido')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Nombre de usuario</span><input class="form-input" name="nombre_usuario" value="{{ old('nombre_usuario', $managedUser?->nombre_usuario) }}" required maxlength="50" autocomplete="username">@error('nombre_usuario')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Correo (opcional)</span><input class="form-input" type="email" name="correo" value="{{ old('correo', $managedUser?->correo) }}" maxlength="254" autocomplete="email">@error('correo')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>Rol</span><select class="form-input" name="rol_id" required><option value="">Selecciona un rol</option>@foreach ($roles as $role)<option value="{{ $role->id }}" @selected((string) old('rol_id', $managedUser?->rol_id) === (string) $role->id)>{{ $role->nombre }}</option>@endforeach</select>@error('rol_id')<small class="field-error">{{ $message }}</small>@enderror</label>
        @if ($editing)<label class="field-group"><span>Estado</span><select class="form-input" name="activo" required><option value="1" @selected((string) old('activo', $managedUser->activo ? '1' : '0') === '1')>Activo</option><option value="0" @selected((string) old('activo', $managedUser->activo ? '1' : '0') === '0')>Inactivo</option></select>@error('activo')<small class="field-error">{{ $message }}</small>@enderror</label>@endif
        <label class="field-group"><span>{{ $editing ? 'Nueva contraseña (opcional)' : 'Contraseña' }}</span><input class="form-input" type="password" name="password" {{ $editing ? '' : 'required' }} minlength="8" autocomplete="new-password"><small class="field-help">Mínimo 8 caracteres, con mayúscula, minúscula y número.</small>@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
        <label class="field-group"><span>{{ $editing ? 'Confirmar nueva contraseña' : 'Confirmar contraseña' }}</span><input class="form-input" type="password" name="password_confirmation" {{ $editing ? '' : 'required' }} minlength="8" autocomplete="new-password"></label>
    </div>
    <div class="admin-form-actions"><a class="secondary-button" href="{{ $cancel }}">Cancelar</a><button class="primary-button" type="submit">{{ $editing ? 'Guardar cambios' : 'Crear usuario' }}</button></div>
</form>
