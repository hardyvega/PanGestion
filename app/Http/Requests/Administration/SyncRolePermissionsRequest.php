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
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['integer', 'distinct', Rule::exists('permisos', 'id')->where(fn ($query) => $query->where('activo', true))],
        ];
    }
}
