<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Articulo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use LogicException;

final class InventoryLockManager
{
    public function lockArticles(iterable $ids): LockedArticleSet
    {
        $requested = [];

        foreach ($ids as $id) {
            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Los IDs deben ser enteros positivos.');
            }

            $requested[$id] = true;
        }

        ksort($requested, SORT_NUMERIC);
        $ids = array_keys($requested);
        $article = new Articulo();

        if ($article->getConnection()->transactionLevel() <= 0) {
            throw new LogicException('El bloqueo requiere una transaccion externa activa.');
        }

        if ($ids === []) {
            return LockedArticleSet::fromArticles([]);
        }

        $articles = $article->newQuery()
            ->whereIn('id', $ids)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        $set = LockedArticleSet::fromArticles($articles);
        $missing = array_values(array_diff($ids, $set->ids()));

        if ($missing !== []) {
            throw (new ModelNotFoundException())->setModel(Articulo::class, $missing);
        }

        return $set;
    }
}
