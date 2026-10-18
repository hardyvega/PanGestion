<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, Articulo> */
final class LockedArticleSet implements IteratorAggregate
{
    /** @param array<int, Articulo> $articles */
    private function __construct(private readonly array $articles)
    {
    }

    public static function fromArticles(iterable $articles): self
    {
        $members = [];

        foreach ($articles as $article) {
            if (! $article instanceof Articulo) {
                throw new InvalidArgumentException('El conjunto solo admite articulos.');
            }

            $id = $article->getAttributes()[$article->getKeyName()] ?? null;

            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('El articulo debe tener un ID entero positivo.');
            }

            $members[$id] ??= $article;
        }

        ksort($members, SORT_NUMERIC);

        return new self($members);
    }

    public function ids(): array
    {
        return array_keys($this->articles);
    }

    public function get(int $articleId): Articulo
    {
        return $this->articles[$articleId]
            ?? throw new InvalidArgumentException('El articulo no pertenece al conjunto bloqueado.');
    }

    public function assertExactlyCovers(iterable $articleIds): void
    {
        $requested = [];

        foreach ($articleIds as $id) {
            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Los IDs deben ser enteros positivos.');
            }

            $requested[$id] = true;
        }

        ksort($requested, SORT_NUMERIC);

        if ($this->ids() !== array_keys($requested)) {
            throw new InventoryDomainException('La cobertura de articulos bloqueados no es exacta.');
        }
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->articles);
    }
}
