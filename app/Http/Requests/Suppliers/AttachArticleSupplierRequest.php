<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use App\Enums\PermissionCode;
use App\Models\Proveedor;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AttachArticleSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::SuppliersManage);
    }

    public function rules(): array
    {
        $supplier = $this->route('proveedor');
        $supplierId = $supplier instanceof Proveedor ? $supplier->getKey() : $supplier;

        return [
            'articulo_id' => [
                'required',
                'integer',
                'exists:articulos,id,activo,1',
                Rule::unique('articulo_proveedor', 'articulo_id')->where(fn ($query) => $query->where('proveedor_id', $supplierId)),
            ],
            'codigo_articulo_proveedor' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->codigo_articulo_proveedor)) {
            $this->merge(['codigo_articulo_proveedor' => trim($this->codigo_articulo_proveedor)]);
        }
    }
}
