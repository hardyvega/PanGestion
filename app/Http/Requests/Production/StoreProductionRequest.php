<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::ProductionManage);
    }

    public function rules(): array
    {
        return [
            'receta_id' => ['required', 'integer', 'exists:recetas,id'],
            'cantidad_producida' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'consumos' => ['required', 'array', 'min:1'],
            'consumos.*' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cantidad_producida' => is_string($this->cantidad_producida) ? trim($this->cantidad_producida) : $this->cantidad_producida,
            'observacion' => is_string($this->observacion) ? trim($this->observacion) : $this->observacion,
        ]);
    }
}
