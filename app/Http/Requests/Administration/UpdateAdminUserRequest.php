<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateAdminUserRequest extends StoreAdminUserRequest
{
    public function rules(): array
    {
        $user = $this->route('usuario');
        $userId = $user instanceof User ? $user->getKey() : $user;

        return [
            ...parent::rules(),
            'nombre_usuario' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('usuarios', 'nombre_usuario')->ignore($userId)->where(fn ($query) => $query->whereRaw('lower(nombre_usuario) = lower(?)', [$this->input('nombre_usuario')]))],
            'correo' => ['nullable', 'email', 'max:254', Rule::unique('usuarios', 'correo')->ignore($userId)->where(fn ($query) => $query->whereRaw('lower(correo) = lower(?)', [$this->input('correo')]))],
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'activo' => ['required', 'boolean'],
        ];
    }
}
