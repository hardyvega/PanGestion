<?php

declare(strict_types=1);

namespace App\Services\Articles;

use App\Models\Articulo;
use Illuminate\Database\Eloquent\Collection;

final class SellableArticleLookupService
{
    private const SELLABLE_TYPES = ['elaborado', 'reventa'];

    public function search(string $term): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection();
        }

        $exact = $this->sellableQuery()->where('codigo_barras', $term)->first();

        if ($exact !== null) {
            return new Collection([$exact]);
        }

        $exact = $this->sellableQuery()->where('sku', $term)->first();

        if ($exact !== null) {
            return new Collection([$exact]);
        }

        if (ctype_digit($term) && (int) $term > 0) {
            $exact = $this->sellableQuery()->whereKey((int) $term)->first();

            if ($exact !== null) {
                return new Collection([$exact]);
            }
        }

        $pattern = '%'.addcslashes($term, '\\%_').'%';

        return $this->sellableQuery()
            ->whereRaw("nombre ILIKE ? ESCAPE E'\\\\'", [$pattern])
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    private function sellableQuery()
    {
        return Articulo::query()
            ->where('activo', true)
            ->whereIn('tipo_articulo', self::SELLABLE_TYPES);
    }
}
