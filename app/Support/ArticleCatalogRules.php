<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CategoriaArticulo;
use App\Models\UnidadMedida;
use App\Rules\UniqueCatalogValue;
use Illuminate\Validation\Rule;

final class ArticleCatalogRules
{
    public const MAGNITUDES = ['unidad' => 'Unidad', 'masa' => 'Masa', 'volumen' => 'Volumen', 'otra' => 'Otra'];

    public static function normalize(array $data): array
    {
        foreach (['codigo', 'nombre', 'descripcion', 'simbolo', 'magnitud'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (isset($data['codigo']) && is_string($data['codigo'])) {
            $data['codigo'] = strtolower($data['codigo']);
        }

        return $data;
    }

    public static function category(?CategoriaArticulo $category = null): array
    {
        return [
            ...self::common('categorias_articulo', $category?->getKey()),
            'descripcion' => ['nullable', 'string'],
        ];
    }

    public static function unit(?UnidadMedida $unit = null): array
    {
        return [
            ...self::common('unidades_medida', $unit?->getKey()),
            'simbolo' => ['bail', 'required', 'string', 'max:20', new UniqueCatalogValue('unidades_medida', 'simbolo', $unit?->getKey())],
            'magnitud' => ['required', Rule::in(array_keys(self::MAGNITUDES))],
        ];
    }

    public static function messages(): array
    {
        return [
            'codigo.required' => 'Indica un código.',
            'codigo.max' => 'El código admite hasta 50 caracteres.',
            'codigo.regex' => 'El código debe comenzar con una letra y contener solo letras minúsculas, números, guion o guion bajo.',
            'nombre.required' => 'Indica un nombre.',
            'nombre.max' => 'El nombre admite hasta 100 caracteres.',
            'simbolo.required' => 'Indica un símbolo.',
            'simbolo.max' => 'El símbolo admite hasta 20 caracteres.',
            'magnitud.required' => 'Selecciona un tipo de medida.',
            'magnitud.in' => 'El tipo de medida seleccionado no es válido.',
        ];
    }

    private static function common(string $table, ?int $id): array
    {
        return [
            'codigo' => ['bail', 'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_-]*$/', new UniqueCatalogValue($table, 'codigo', $id)],
            'nombre' => ['bail', 'required', 'string', 'max:100', new UniqueCatalogValue($table, 'nombre', $id)],
        ];
    }
}
