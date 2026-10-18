<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Inventory;

use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Services\Inventory\LockedArticleSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

final class LockedArticleSetTest extends TestCase
{
    public function test_empty_collection_is_a_valid_immutable_set(): void
    {
        $set = LockedArticleSet::fromArticles([]);
        $reflection = new ReflectionClass(LockedArticleSet::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->getConstructor()?->isPrivate());
        $this->assertSame([], $set->ids());
        $this->assertSame([], iterator_to_array($set->getIterator(), false));
        $set->assertExactlyCovers([]);
    }

    public function test_single_article_can_be_retrieved_by_its_exact_integer_id(): void
    {
        $article = $this->articleWithId(42);
        $set = LockedArticleSet::fromArticles([$article]);

        $this->assertSame([42], $set->ids());
        $this->assertSame($article, $set->get(42));
    }

    public function test_membership_is_deduplicated_and_sorted_by_article_id(): void
    {
        $first = $this->articleWithId(3);
        $second = $this->articleWithId(8);
        $third = $this->articleWithId(15);

        $set = LockedArticleSet::fromArticles([$third, $first, $second, $first]);

        $this->assertSame([3, 8, 15], $set->ids());
        $this->assertSame($first, $set->get(3));
        $this->assertSame($second, $set->get(8));
        $this->assertSame($third, $set->get(15));
    }

    public function test_ids_are_returned_as_integers_even_when_iterated_repeatedly(): void
    {
        $set = LockedArticleSet::fromArticles([
            $this->articleWithId(9),
            $this->articleWithId(2),
        ]);

        $this->assertSame([2, 9], $set->ids());
        $this->assertSame([2, 9], $set->ids());

        foreach ($set->ids() as $id) {
            $this->assertIsInt($id);
        }
    }

    #[DataProvider('invalidArticleIds')]
    public function test_articles_must_have_a_strictly_positive_integer_id(mixed $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        LockedArticleSet::fromArticles([$this->articleWithId($id)]);
    }

    public static function invalidArticleIds(): array
    {
        return [
            'null' => [null],
            'zero' => [0],
            'negative' => [-1],
            'numeric string' => ['12'],
            'float' => [12.0],
        ];
    }

    public function test_non_article_members_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LockedArticleSet::fromArticles([new stdClass()]);
    }

    public function test_get_rejects_an_id_that_is_not_in_the_locked_membership(): void
    {
        $set = LockedArticleSet::fromArticles([$this->articleWithId(5)]);

        $this->expectException(InvalidArgumentException::class);

        $set->get(99);
    }

    public function test_exact_coverage_accepts_any_order_and_normalizes_duplicate_ids(): void
    {
        $set = LockedArticleSet::fromArticles([
            $this->articleWithId(2),
            $this->articleWithId(7),
            $this->articleWithId(11),
        ]);

        $set->assertExactlyCovers([11, 2, 7, 2, 11]);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('coverageMismatches')]
    public function test_exact_coverage_rejects_missing_or_extra_ids(array $coverage): void
    {
        $set = LockedArticleSet::fromArticles([
            $this->articleWithId(2),
            $this->articleWithId(7),
            $this->articleWithId(11),
        ]);

        $this->expectException(InventoryDomainException::class);

        $set->assertExactlyCovers($coverage);
    }

    public static function coverageMismatches(): array
    {
        return [
            'missing id' => [[2, 11]],
            'extra id' => [[2, 7, 11, 20]],
        ];
    }

    public function test_exact_coverage_rejects_invalid_id_types_before_comparing_membership(): void
    {
        $set = LockedArticleSet::fromArticles([$this->articleWithId(2)]);

        $this->expectException(InvalidArgumentException::class);

        $set->assertExactlyCovers(['2']);
    }

    public function test_iteration_is_stable_and_ascending(): void
    {
        $set = LockedArticleSet::fromArticles([
            $this->articleWithId(17),
            $this->articleWithId(1),
            $this->articleWithId(6),
        ]);
        $ids = array_map(
            static fn (Articulo $article): int => (int) $article->getKey(),
            iterator_to_array($set->getIterator(), false),
        );

        $this->assertSame([1, 6, 17], $ids);
    }

    public function test_mutating_the_source_collection_does_not_change_internal_membership(): void
    {
        $articles = [
            $this->articleWithId(4),
            $this->articleWithId(9),
        ];
        $set = LockedArticleSet::fromArticles($articles);

        $articles[] = $this->articleWithId(12);
        unset($articles[0]);

        $this->assertSame([4, 9], $set->ids());
        $this->assertSame([4, 9], array_map(
            static fn (Articulo $article): int => (int) $article->getKey(),
            iterator_to_array($set->getIterator(), false),
        ));
    }

    private function articleWithId(mixed $id): Articulo
    {
        $article = new Articulo();
        $article->setAttribute('id', $id);

        return $article;
    }
}
