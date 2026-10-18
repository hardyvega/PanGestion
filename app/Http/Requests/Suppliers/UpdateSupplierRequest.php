<?php

declare(strict_types=1);

namespace App\Http\Requests\Suppliers;

use App\Models\Proveedor;
use Illuminate\Validation\Rule;

final class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function rules(): array
    {
        $supplier = $this->route('proveedor');
        $supplierId = $supplier instanceof Proveedor ? $supplier->getKey() : $supplier;

        return [
            ...parent::rules(),
            'codigo' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('proveedores', 'codigo')->ignore($supplierId)->where(fn ($query) => $query->whereRaw('lower(codigo) = lower(?)', [$this->input('codigo')]))],
        ];
    }
}
