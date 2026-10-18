<?php

declare(strict_types=1);

namespace App\Http\Requests\Articles;

use App\Models\Articulo;
use Illuminate\Validation\Rule;

final class UpdateArticleRequest extends StoreArticleRequest
{
    public function rules(): array
    {
        $article = $this->route('articulo');
        $articleId = $article instanceof Articulo ? $article->getKey() : $article;

        return [
            ...parent::rules(),
            'sku' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('articulos', 'sku')->ignore($articleId)->where(fn ($query) => $query->whereRaw('lower(sku) = lower(?)', [$this->input('sku')]))],
            'codigo_barras' => ['nullable', 'string', 'max:64', 'regex:/^\S+$/', Rule::unique('articulos', 'codigo_barras')->ignore($articleId)],
            'quitar_imagen' => ['nullable', 'boolean'],
        ];
    }
}
