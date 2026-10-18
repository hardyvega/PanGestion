<?php

declare(strict_types=1);

namespace App\Http\Requests\Articles;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateArticlePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::ArticlesManage);
    }

    public function rules(): array
    {
        return [
            'precio_venta' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'motivo_precio' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->precio_venta)) {
            $this->merge(['precio_venta' => trim($this->precio_venta)]);
        }
    }
}
