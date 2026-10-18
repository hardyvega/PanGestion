<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::SuppliersManage);
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('proveedores', 'codigo')->where(fn ($query) => $query->whereRaw('lower(codigo) = lower(?)', [$this->input('codigo')]))],
            'nombre' => ['required', 'string', 'max:150'],
            'nombre_contacto' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'correo' => ['nullable', 'email', 'max:254'],
            'observacion' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => is_string($this->codigo) ? strtolower(trim($this->codigo)) : $this->codigo,
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'nombre_contacto' => is_string($this->nombre_contacto) ? trim($this->nombre_contacto) : $this->nombre_contacto,
            'telefono' => is_string($this->telefono) ? trim($this->telefono) : $this->telefono,
            'correo' => is_string($this->correo) ? trim($this->correo) : $this->correo,
            'observacion' => is_string($this->observacion) ? trim($this->observacion) : $this->observacion,
        ]);
    }
}
