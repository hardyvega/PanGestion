<?php

namespace Tests\Feature\Database\BlockTen;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const CREATED_AT = '2026-09-20 09:00:00-03';

    private const CONFIRMED_AT = '2026-09-20 10:00:00-03';

    private int $articleId;

    private int $cashRegisterId;

    private int $cashSessionId;

    private int $categoryId;

    private int $confirmingUserId;

    private int $creatorUserId;

    private int $inventoryCountId;

    private int $openingUserId;

    private int $registrarUserId;

    private int $responsibleUserId;

    private int $roleId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->openingUserId = $this->createDistinctUser('fk_apertura_base');
        $this->creatorUserId = $this->createDistinctUser('fk_conteo_creador_base');
        $this->responsibleUserId = $this->createDistinctUser('fk_conteo_responsable_base');
        $this->confirmingUserId = $this->createDistinctUser('fk_conteo_confirmador_base');
        $this->registrarUserId = $this->createDistinctUser('fk_merma_registrador_base');
        $this->cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createDistinctArticle('pan_fk_b10', 'Pan FK B10');
        $this->inventoryCountId = $this->createConfirmedInventoryCount([
            'sesion_caja_id' => $this->cashSessionId,
        ]);
    }

    public function test_inventory_count_rejects_missing_cash_session(): void
    {
        $cashRegisterId = $this->createCashRegister([
            'codigo' => 'caja_fk_eliminada',
            'nombre' => 'Caja FK eliminada',
        ]);
        $missingSessionId = $this->createCashSession($cashRegisterId, $this->openingUserId);
        $this->assertSame(1, DB::table('sesiones_caja')->where('id', $missingSessionId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createConfirmedInventoryCount([
            'sesion_caja_id' => $missingSessionId,
        ]));
    }

    public function test_inventory_count_rejects_missing_creator_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_conteo_creador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createConfirmedInventoryCount([
            'usuario_creador_id' => $missingUserId,
        ]));
    }

    public function test_inventory_count_rejects_missing_responsible_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_conteo_responsable_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createConfirmedInventoryCount([
            'usuario_responsable_id' => $missingUserId,
        ]));
    }

    public function test_confirmed_inventory_count_rejects_missing_confirming_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_conteo_confirmador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createConfirmedInventoryCount([
            'usuario_confirmador_id' => $missingUserId,
        ]));
    }

    public function test_inventory_count_detail_rejects_missing_inventory_count(): void
    {
        $missingCountId = $this->createConfirmedInventoryCount();
        $this->assertSame(1, DB::table('conteos_inventario')->where('id', $missingCountId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createInventoryCountDetail(
            $missingCountId,
            $this->articleId,
        ));
    }

    public function test_inventory_count_detail_rejects_missing_article(): void
    {
        $missingArticleId = $this->createDistinctArticle('conteo_articulo_eliminado', 'Conteo articulo eliminado');
        $this->assertSame(1, DB::table('articulos')->where('id', $missingArticleId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $missingArticleId,
        ));
    }

    public function test_waste_rejects_missing_article(): void
    {
        $missingArticleId = $this->createDistinctArticle('merma_articulo_eliminado', 'Merma articulo eliminado');
        $this->assertSame(1, DB::table('articulos')->where('id', $missingArticleId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createWaste(
            $missingArticleId,
            $this->registrarUserId,
            self::UUID_ONE,
        ));
    }

    public function test_waste_rejects_missing_registrar_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_merma_registrador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createWaste(
            $this->articleId,
            $missingUserId,
            self::UUID_ONE,
        ));
    }

    public function test_annulled_waste_rejects_missing_annulling_user(): void
    {
        $missingUserId = $this->createDistinctUser('fk_merma_anulador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createWaste(
            $this->articleId,
            $this->registrarUserId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CONFIRMED_AT,
                'created_at' => self::CONFIRMED_AT,
                'usuario_anulador_id' => $missingUserId,
                'anulada_en' => '2026-09-20 11:00:00-03',
                'motivo_anulacion' => 'Usuario inexistente',
            ],
        ));
    }

    public function test_waste_rejects_missing_replaced_waste(): void
    {
        $missingWasteId = $this->createWaste(
            $this->articleId,
            $this->registrarUserId,
            self::UUID_ONE,
        );
        $this->assertSame(1, DB::table('mermas')->where('id', $missingWasteId)->delete());

        $this->assertForeignKeyViolation(fn () => $this->createWaste(
            $this->articleId,
            $this->registrarUserId,
            self::UUID_TWO,
            ['merma_reemplazada_id' => $missingWasteId],
        ));
    }

    public function test_cash_session_with_inventory_count_cannot_be_deleted(): void
    {
        $this->assertForeignKeyViolation(
            fn () => DB::table('sesiones_caja')->where('id', $this->cashSessionId)->delete(),
            '23001',
        );
    }

    public function test_creator_user_with_inventory_count_cannot_be_deleted(): void
    {
        $this->assertForeignKeyViolation(
            fn () => DB::table('usuarios')->where('id', $this->creatorUserId)->delete(),
            '23001',
        );
    }

    public function test_responsible_user_with_inventory_count_cannot_be_deleted(): void
    {
        $this->assertForeignKeyViolation(
            fn () => DB::table('usuarios')->where('id', $this->responsibleUserId)->delete(),
            '23001',
        );
    }

    public function test_confirming_user_with_inventory_count_cannot_be_deleted(): void
    {
        $this->assertForeignKeyViolation(
            fn () => DB::table('usuarios')->where('id', $this->confirmingUserId)->delete(),
            '23001',
        );
    }

    public function test_inventory_count_with_detail_cannot_be_deleted(): void
    {
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId);

        $this->assertForeignKeyViolation(
            fn () => DB::table('conteos_inventario')->where('id', $this->inventoryCountId)->delete(),
            '23001',
        );
    }

    public function test_article_with_inventory_count_detail_cannot_be_deleted(): void
    {
        $articleId = $this->createDistinctArticle('conteo_articulo_restringido', 'Conteo articulo restringido');
        $this->createInventoryCountDetail($this->inventoryCountId, $articleId, [
            'articulo_sku_snapshot' => 'conteo_articulo_restringido',
            'articulo_nombre_snapshot' => 'Conteo articulo restringido',
        ]);

        $this->assertForeignKeyViolation(
            fn () => DB::table('articulos')->where('id', $articleId)->delete(),
            '23001',
        );
    }

    public function test_article_with_waste_cannot_be_deleted(): void
    {
        $articleId = $this->createDistinctArticle('merma_articulo_restringido', 'Merma articulo restringido');
        $this->createWaste($articleId, $this->registrarUserId, self::UUID_ONE, [
            'articulo_sku_snapshot' => 'merma_articulo_restringido',
            'articulo_nombre_snapshot' => 'Merma articulo restringido',
        ]);

        $this->assertForeignKeyViolation(
            fn () => DB::table('articulos')->where('id', $articleId)->delete(),
            '23001',
        );
    }

    public function test_registrar_user_with_waste_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_merma_registrador_restringido');
        $this->createWaste($this->articleId, $userId, self::UUID_ONE);

        $this->assertForeignKeyViolation(
            fn () => DB::table('usuarios')->where('id', $userId)->delete(),
            '23001',
        );
    }

    public function test_annulling_user_with_waste_cannot_be_deleted(): void
    {
        $userId = $this->createDistinctUser('fk_merma_anulador_restringido');
        $this->createWaste($this->articleId, $this->registrarUserId, self::UUID_ONE, [
            'ocurrido_en' => self::CONFIRMED_AT,
            'created_at' => self::CONFIRMED_AT,
            'usuario_anulador_id' => $userId,
            'anulada_en' => '2026-09-20 11:00:00-03',
            'motivo_anulacion' => 'Relacion restringida',
        ]);

        $this->assertForeignKeyViolation(
            fn () => DB::table('usuarios')->where('id', $userId)->delete(),
            '23001',
        );
    }

    public function test_replaced_waste_cannot_be_deleted(): void
    {
        $originalId = $this->createWaste(
            $this->articleId,
            $this->registrarUserId,
            self::UUID_ONE,
        );
        $this->createWaste(
            $this->articleId,
            $this->registrarUserId,
            self::UUID_TWO,
            ['merma_reemplazada_id' => $originalId],
        );

        $this->assertForeignKeyViolation(
            fn () => DB::table('mermas')->where('id', $originalId)->delete(),
            '23001',
        );
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

    private function createConfirmedInventoryCount(array $overrides = []): int
    {
        return $this->createInventoryCount(
            $this->creatorUserId,
            $this->responsibleUserId,
            array_merge([
                'estado' => 'confirmado',
                'usuario_confirmador_id' => $this->confirmingUserId,
                'confirmada_en' => self::CONFIRMED_AT,
                'created_at' => self::CREATED_AT,
            ], $overrides),
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
            'apellido' => 'Integridad B10',
            'nombre_usuario' => $username,
        ]);
    }
}
