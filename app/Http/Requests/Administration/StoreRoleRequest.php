<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::RolesManage);
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9._-]*$/', Rule::unique('roles', 'codigo')->where(fn ($query) => $query->whereRaw('lower(codigo) = lower(?)', [$this->input('codigo')]))],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => is_string($this->codigo) ? strtolower(trim($this->codigo)) : $this->codigo,
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'descripcion' => is_string($this->descripcion) ? trim($this->descripcion) : $this->descripcion,
        ]);
    }
}
