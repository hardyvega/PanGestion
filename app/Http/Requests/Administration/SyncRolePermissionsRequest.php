<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::RolePermissionsManage);
    }

    public function rules(): array
    {
        return [
            '_editor_user_id' => ['sometimes', 'integer', Rule::in([(string) $this->user()->getAuthIdentifier()])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'distinct', Rule::exists('permisos', 'id')->where(fn ($query) => $query->where('activo', true))],
        ];
    }

    public function messages(): array
    {
        return [
            '_editor_user_id.in' => 'La cuenta activa cambió. Vuelve a abrir el rol con tu cuenta antes de guardar.',
            'permissions.array' => 'La selección de permisos no es válida. Revisa las casillas antes de guardar.',
            'permissions.*.integer' => 'Uno de los permisos seleccionados no es válido. Revisa la selección antes de guardar.',
            'permissions.*.distinct' => 'La selección contiene permisos repetidos. Revisa las casillas antes de guardar.',
            'permissions.*.exists' => 'Uno de los permisos seleccionados ya no está disponible. Revisa la selección antes de guardar.',
        ];
    }
}
