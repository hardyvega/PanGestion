<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::UsersManage);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'nombre_usuario' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('usuarios', 'nombre_usuario')->where(fn ($query) => $query->whereRaw('lower(nombre_usuario) = lower(?)', [$this->input('nombre_usuario')]))],
            'correo' => ['nullable', 'email', 'max:254'],
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('activo', true))],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'apellido' => is_string($this->apellido) ? trim($this->apellido) : $this->apellido,
            'nombre_usuario' => is_string($this->nombre_usuario) ? trim($this->nombre_usuario) : $this->nombre_usuario,
            'correo' => is_string($this->correo) ? trim($this->correo) : $this->correo,
        ]);
    }
}
