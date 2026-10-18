<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::InventoryCountsManage);
    }

    public function rules(): array
    {
        return [
            'observacion' => ['nullable', 'string', 'max:5000'],
            'articulos' => ['required', 'array', 'min:1'],
            'articulos.*.articulo_id' => ['required', 'integer', 'distinct', 'exists:articulos,id'],
            'articulos.*.cantidad_contada' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'],
        ];
    }
}
