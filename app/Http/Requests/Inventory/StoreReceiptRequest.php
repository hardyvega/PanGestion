<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\PermissionCode;
use App\Enums\ReceiptOriginType;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::InventoryReceiptsCreate);
    }

    public function rules(): array
    {
        return [
            'tipo_origen' => ['required', Rule::enum(ReceiptOriginType::class)],
            'proveedor_id' => ['nullable', 'integer', 'exists:proveedores,id'],
            'usuario_aportante_id' => ['nullable', 'integer', 'exists:usuarios,id'],
            'origen_detalle' => ['nullable', 'string', 'max:500'],
            'ocurrido_en' => ['nullable', 'date', 'before_or_equal:now'],
            'motivo_ajuste_ocurrido_en' => ['nullable', 'string', 'max:2000'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['origen_detalle', 'motivo_ajuste_ocurrido_en', 'observacion'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }
}
