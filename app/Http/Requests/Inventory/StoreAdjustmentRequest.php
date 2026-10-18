<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $permission = $this->input('tipo') === 'entrada'
            ? PermissionCode::InventoryAdjustmentsInput
            : PermissionCode::InventoryAdjustmentsOutput;

        return app(EffectivePermissionResolver::class)->allows($this->user(), $permission);
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:entrada,salida'],
            'articulo_id' => ['required', 'integer', 'exists:articulos,id'],
            'cantidad' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/', 'not_regex:/^0+(?:\.0{1,3})?$/'],
            'motivo' => ['required', 'string', 'max:2000'],
            'ocurrido_en' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->motivo)) {
            $this->merge(['motivo' => trim($this->motivo)]);
        }
    }
}
