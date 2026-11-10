<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\PermissionCode;
use App\Http\Requests\ValidatesCommercialTime;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWasteRequest extends FormRequest
{
    use ValidatesCommercialTime;

    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::InventoryWasteRegister);
    }

    public function rules(): array
    {
        return [
            'articulo_id' => ['required', 'integer', 'exists:articulos,id'],
            'cantidad' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/', 'not_regex:/^0+(?:\.0{1,3})?$/'],
            'motivo' => ['required', 'string', 'max:2000'],
            'ocurrido_en' => ['bail', 'nullable', 'string', $this->commercialTimeRule(pastOnly: true)],
            'ocurrido_en_occurrence' => ['nullable', 'in:primera,segunda'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->motivo)) {
            $this->merge(['motivo' => trim($this->motivo)]);
        }
    }
}
