<?php

declare(strict_types=1);

namespace App\Http\Requests\Recipes;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::RecipesManage);
    }

    public function rules(): array
    {
        return [
            'articulo_id' => ['required', 'integer', 'exists:articulos,id'],
            'rendimiento_cantidad' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'],
            'activa' => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'componentes' => ['required', 'array', 'min:1'],
            'componentes.*.articulo_componente_id' => ['required', 'integer', 'distinct', 'exists:articulos,id'],
            'componentes.*.cantidad' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'rendimiento_cantidad' => is_string($this->rendimiento_cantidad) ? trim($this->rendimiento_cantidad) : $this->rendimiento_cantidad,
            'observacion' => is_string($this->observacion) ? trim($this->observacion) : $this->observacion,
        ]);
    }
}
