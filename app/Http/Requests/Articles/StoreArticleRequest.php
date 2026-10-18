<?php

declare(strict_types=1);

namespace App\Http\Requests\Articles;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::ArticlesManage);
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('articulos', 'sku')->where(fn ($query) => $query->whereRaw('lower(sku) = lower(?)', [$this->input('sku')]))],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'tipo_articulo' => ['required', Rule::in(['elaborado', 'reventa', 'materia_prima', 'insumo_operacional'])],
            'categoria_articulo_id' => ['required', 'integer', 'exists:categorias_articulo,id,activo,1'],
            'unidad_id' => ['required', 'integer', 'exists:unidades_medida,id,activo,1'],
            'codigo_barras' => ['nullable', 'string', 'max:64', 'regex:/^\S+$/', Rule::unique('articulos', 'codigo_barras')],
            'stock_minimo' => ['required', 'regex:/^\d+(?:\.\d{1,3})?$/'],
            'precio_venta' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'motivo_precio' => ['nullable', 'string', 'max:1000'],
            'imagen' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => is_string($this->sku) ? strtolower(trim($this->sku)) : $this->sku,
            'nombre' => is_string($this->nombre) ? trim($this->nombre) : $this->nombre,
            'codigo_barras' => is_string($this->codigo_barras) ? trim($this->codigo_barras) : $this->codigo_barras,
            'stock_minimo' => is_string($this->stock_minimo) ? trim($this->stock_minimo) : $this->stock_minimo,
            'precio_venta' => is_string($this->precio_venta) ? trim($this->precio_venta) : $this->precio_venta,
        ]);
    }
}
