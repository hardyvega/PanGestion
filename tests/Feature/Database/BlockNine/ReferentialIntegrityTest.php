<?php

namespace Tests\Feature\Database\BlockNine;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const CREATED_AT = '2026-09-20 09:00:00-03';

    private const CONFIRMED_AT = '2026-09-20 10:00:00-03';

    private const ANNULLED_AT = '2026-09-20 11:00:00-03';

    private int $articleId;

    private int $categoryId;

    private int $componentId;

    private int $creatorUserId;

    private int $productionDetailId;

    private int $productionId;

    private int $recipeDetailId;

    private int $recipeId;

    private int $registrarUserId;

    private int $responsibleUserId;

    private int $roleId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->responsibleUserId = $this->createDistinctUser('fk_responsable_base');
        $this->creatorUserId = $this->createDistinctUser('fk_creador_base');
        $this->registrarUserId = $this->createDistinctUser('fk_registrador_base');
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createDistinctArticle('pan_fk', 'Pan FK');
        $this->componentId = $this->createDistinctArticle(
            'harina_fk',
            'Harina FK',
            ['tipo_articulo' => 'materia_prima'],
        );
        $this->recipeId = $this->createRecipe($this->articleId, ['activa' => true]);
        $this->recipeDetailId = $this->createRecipeDetail($this->recipeId, $this->componentId);
        $this->productionId = $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
        );
        $this->productionDetailId = $this->createProductionDetail($this->productionId, $this->articleId, [
            'articulo_sku_snapshot' => 'pan_fk',
            'articulo_nombre_snapshot' => 'Pan FK',
        ]);
    }

    public function test_production_rejects_missing_recipe(): void
    {
        $productId = $this->createDistinctArticle('pan_receta_eliminada', 'Pan receta eliminada');
        $missingRecipeId = $this->createRecipe($productId);
        $this->assertSame(1, DB::table('recetas')->where('id', $missingRecipeId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProduction(
            $missingRecipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
        ));
    }

    public function test_production_rejects_missing_responsible_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_responsable_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProduction(
            $this->recipeId,
            $missingUserId,
            $this->creatorUserId,
        ));
    }

    public function test_production_rejects_missing_creator_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_creador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $missingUserId,
        ));
    }

    public function test_confirmed_production_rejects_missing_confirming_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_confirmador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
            [
                'estado' => 'confirmada',
                'confirmada_en' => self::CONFIRMED_AT,
                'usuario_confirmador_id' => $missingUserId,
                'created_at' => self::CREATED_AT,
            ],
        ));
    }

    public function test_annulled_production_rejects_missing_annulling_user(): void
    {
        $confirmingUserId = $this->createDistinctUser('fk_confirmador_valido');
        $missingUserId = $this->createDistinctUser('fk_anulador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
            [
                'estado' => 'anulada',
                'confirmada_en' => self::CONFIRMED_AT,
                'usuario_confirmador_id' => $confirmingUserId,
                'anulada_en' => self::ANNULLED_AT,
                'usuario_anulador_id' => $missingUserId,
                'motivo_anulacion' => 'Usuario inexistente',
                'created_at' => self::CREATED_AT,
            ],
        ));
    }

    public function test_production_detail_rejects_missing_production(): void
    {
        $missingProductionId = $this->createAdditionalProduction();
        $this->assertSame(1, DB::table('producciones')->where('id', $missingProductionId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProductionDetail(
            $missingProductionId,
            $this->articleId,
        ));
    }

    public function test_production_detail_rejects_missing_article(): void
    {
        $missingArticleId = $this->createDistinctArticle('producto_eliminado', 'Producto eliminado');
        $this->assertSame(1, DB::table('articulos')->where('id', $missingArticleId)->delete());

        $otherProductionId = $this->createAdditionalProduction();
        $this->assertForeignKeyViolation(fn () => $this->createProductionDetail(
            $otherProductionId,
            $missingArticleId,
        ));
    }

    public function test_production_consumption_rejects_missing_production(): void
    {
        $missingProductionId = $this->createAdditionalProduction();
        $this->assertSame(1, DB::table('producciones')->where('id', $missingProductionId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProductionConsumption(
            $missingProductionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        ));
    }

    public function test_production_consumption_rejects_missing_recipe_detail(): void
    {
        $otherComponentId = $this->createDistinctArticle(
            'componente_detalle_eliminado',
            'Componente detalle eliminado',
            ['tipo_articulo' => 'materia_prima'],
        );
        $missingRecipeDetailId = $this->createRecipeDetail($this->recipeId, $otherComponentId);
        $this->assertSame(1, DB::table('detalle_recetas')->where('id', $missingRecipeDetailId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProductionConsumption(
            $this->productionId,
            $otherComponentId,
            $missingRecipeDetailId,
            '1.000',
        ));
    }

    public function test_production_consumption_rejects_missing_component(): void
    {
        $missingComponentId = $this->createDistinctArticle(
            'componente_eliminado',
            'Componente eliminado',
            ['tipo_articulo' => 'materia_prima'],
        );
        $this->assertSame(1, DB::table('articulos')->where('id', $missingComponentId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createProductionConsumption(
            $this->productionId,
            $missingComponentId,
        ));
    }

    public function test_counter_output_rejects_missing_production_detail(): void
    {
        $otherProductionId = $this->createAdditionalProduction();
        $missingDetailId = $this->createProductionDetail($otherProductionId, $this->articleId);
        $this->assertSame(1, DB::table('detalle_producciones')->where('id', $missingDetailId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createCounterOutput(
            $missingDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_ONE,
        ));
    }

    public function test_counter_output_rejects_missing_responsible_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_salida_responsable_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createCounterOutput(
            $this->productionDetailId,
            $missingUserId,
            $this->registrarUserId,
            self::UUID_ONE,
        ));
    }

    public function test_counter_output_rejects_missing_registrar_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_salida_registrador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $missingUserId,
            self::UUID_ONE,
        ));
    }

    public function test_annulled_counter_output_rejects_missing_annulling_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_salida_anulador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CONFIRMED_AT,
                'created_at' => self::CONFIRMED_AT,
                'anulada_en' => self::ANNULLED_AT,
                'usuario_anulador_id' => $missingUserId,
                'motivo_anulacion' => 'Usuario inexistente',
            ],
        ));
    }

    public function test_counter_output_rejects_missing_replaced_output(): void
    {
        $missingOutputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_ONE,
        );
        $this->assertSame(1, DB::table('salidas_meson')->where('id', $missingOutputId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_TWO,
            ['salida_reemplazada_id' => $missingOutputId],
        ));
    }

    public function test_recipe_with_production_cannot_be_deleted(): void
    {
        $productId = $this->createDistinctArticle('pan_receta_restringida', 'Pan receta restringida');
        $recipeId = $this->createRecipe($productId);
        $this->createProduction($recipeId, $this->responsibleUserId, $this->creatorUserId);

        $this->assertForeignKeyViolation(fn () => DB::table('recetas')->where('id', $recipeId)->delete(), '23001');
    }

    public function test_responsible_user_with_production_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_responsable_restringido');
        $this->createProduction($this->recipeId, $userId, $this->creatorUserId);

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $userId)->delete(), '23001');
    }

    public function test_creator_user_with_production_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_creador_restringido');
        $this->createProduction($this->recipeId, $this->responsibleUserId, $userId);

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $userId)->delete(), '23001');
    }

    public function test_confirming_user_with_production_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_confirmador_restringido');
        $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
            [
                'estado' => 'confirmada',
                'confirmada_en' => self::CONFIRMED_AT,
                'usuario_confirmador_id' => $userId,
                'created_at' => self::CREATED_AT,
            ],
        );

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $userId)->delete(), '23001');
    }

    public function test_annulling_user_with_production_cannot_be_deleted(): void
    {
        $confirmingUserId = $this->createDistinctUser('fk_confirmador_anulada');
        $annullingUserId = $this->createDistinctUser('fk_anulador_restringido');
        $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
            [
                'estado' => 'anulada',
                'confirmada_en' => self::CONFIRMED_AT,
                'usuario_confirmador_id' => $confirmingUserId,
                'anulada_en' => self::ANNULLED_AT,
                'usuario_anulador_id' => $annullingUserId,
                'motivo_anulacion' => 'Relacion restringida',
                'created_at' => self::CREATED_AT,
            ],
        );

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $annullingUserId)->delete(), '23001');
    }

    public function test_production_with_detail_cannot_be_deleted(): void
    {
        $productionId = $this->createAdditionalProduction();
        $this->createProductionDetail($productionId, $this->articleId);

        $this->assertForeignKeyViolation(fn () => DB::table('producciones')->where('id', $productionId)->delete(), '23001');
    }

    public function test_article_with_production_detail_cannot_be_deleted(): void
    {
        $articleId = $this->createDistinctArticle('producto_detalle_restringido', 'Producto detalle restringido');
        $productionId = $this->createAdditionalProduction();
        $this->createProductionDetail($productionId, $articleId);

        $this->assertForeignKeyViolation(fn () => DB::table('articulos')->where('id', $articleId)->delete(), '23001');
    }

    public function test_production_with_consumption_cannot_be_deleted(): void
    {
        $productionId = $this->createAdditionalProduction();
        $this->createProductionConsumption(
            $productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        );

        $this->assertForeignKeyViolation(fn () => DB::table('producciones')->where('id', $productionId)->delete(), '23001');
    }

    public function test_recipe_detail_with_production_consumption_cannot_be_deleted(): void
    {
        $componentId = $this->createDistinctArticle(
            'detalle_receta_restringido',
            'Detalle receta restringido',
            ['tipo_articulo' => 'materia_prima'],
        );
        $recipeDetailId = $this->createRecipeDetail($this->recipeId, $componentId);
        $this->createProductionConsumption(
            $this->productionId,
            $componentId,
            $recipeDetailId,
            '1.000',
        );

        $this->assertForeignKeyViolation(fn () => DB::table('detalle_recetas')->where('id', $recipeDetailId)->delete(), '23001');
    }

    public function test_component_with_production_consumption_cannot_be_deleted(): void
    {
        $componentId = $this->createDistinctArticle(
            'componente_restringido',
            'Componente restringido',
            ['tipo_articulo' => 'materia_prima'],
        );
        $this->createProductionConsumption($this->productionId, $componentId);

        $this->assertForeignKeyViolation(fn () => DB::table('articulos')->where('id', $componentId)->delete(), '23001');
    }

    public function test_production_detail_with_counter_output_cannot_be_deleted(): void
    {
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_ONE,
        );

        $this->assertForeignKeyViolation(
            fn () => DB::table('detalle_producciones')->where('id', $this->productionDetailId)->delete(),
            '23001',
        );
    }

    public function test_responsible_user_with_counter_output_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_salida_responsable_restringido');
        $this->createCounterOutput(
            $this->productionDetailId,
            $userId,
            $this->registrarUserId,
            self::UUID_ONE,
        );

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $userId)->delete(), '23001');
    }

    public function test_registrar_user_with_counter_output_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_salida_registrador_restringido');
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $userId,
            self::UUID_ONE,
        );

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $userId)->delete(), '23001');
    }

    public function test_annulling_user_with_counter_output_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_salida_anulador_restringido');
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CONFIRMED_AT,
                'created_at' => self::CONFIRMED_AT,
                'anulada_en' => self::ANNULLED_AT,
                'usuario_anulador_id' => $userId,
                'motivo_anulacion' => 'Relacion restringida',
            ],
        );

        $this->assertForeignKeyViolation(fn () => DB::table('usuarios')->where('id', $userId)->delete(), '23001');
    }

    public function test_replaced_counter_output_cannot_be_deleted(): void
    {
        $originalId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_ONE,
        );
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->responsibleUserId,
            $this->registrarUserId,
            self::UUID_TWO,
            ['salida_reemplazada_id' => $originalId],
        );

        $this->assertForeignKeyViolation(fn () => DB::table('salidas_meson')->where('id', $originalId)->delete(), '23001');
    }

    private function assertForeignKeyViolation(Closure $operation, string $expectedSqlState = '23503'): void
    {
        try {
            $operation();
            $this->fail('Expected a PostgreSQL foreign-key violation.');
        } catch (QueryException $exception) {
            $this->assertSame($expectedSqlState, (string) $exception->getCode());
        }
    }

    private function createAdditionalProduction(): int
    {
        return $this->createProduction(
            $this->recipeId,
            $this->responsibleUserId,
            $this->creatorUserId,
        );
    }

    private function createDistinctArticle(string $sku, string $name, array $overrides = []): int
    {
        return $this->createArticle(
            $this->categoryId,
            $this->unitId,
            array_merge(['sku' => $sku, 'nombre' => $name], $overrides),
        );
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Integridad',
            'nombre_usuario' => $username,
        ]);
    }
}
