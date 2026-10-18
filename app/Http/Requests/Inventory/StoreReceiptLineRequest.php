<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreReceiptLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::InventoryReceiptsEdit);
    }

    public function rules(): array
    {
        return [
            'articulo_id' => ['required', 'integer', 'exists:articulos,id'],
            'cantidad' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/', 'not_regex:/^0+(?:\.0{1,3})?$/'],
            'costo_unitario' => ['nullable', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/'],
            'motivo_costo_cero' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
