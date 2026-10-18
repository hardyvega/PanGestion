<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Models\Rol;
use Illuminate\Validation\Rule;

final class UpdateRoleRequest extends StoreRoleRequest
{
    public function rules(): array
    {
        $role = $this->route('rol');
        $roleId = $role instanceof Rol ? $role->getKey() : $role;

        return [
            ...parent::rules(),
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9._-]*$/', Rule::unique('roles', 'codigo')->ignore($roleId)->where(fn ($query) => $query->whereRaw('lower(codigo) = lower(?)', [$this->input('codigo')]))],
        ];
    }
}
