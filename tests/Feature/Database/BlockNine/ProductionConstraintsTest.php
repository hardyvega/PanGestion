<?php

namespace Tests\Feature\Database\BlockNine;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ProductionConstraintsTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const CREATED_AT = '2026-09-20 09:00:00-03';

    private const CONFIRMED_AT = '2026-09-20 10:00:00-03';

    private const ANNULLED_AT = '2026-09-20 11:00:00-03';

    private int $articleId;

    private int $recipeId;

    private int $roleId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->userId = $this->createUser($this->roleId, ['nombre_usuario' => 'produccion_base']);
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'pan_produccion',
            'nombre' => 'Pan produccion',
        ]);
        $this->recipeId = $this->createRecipe($this->articleId, ['activa' => true]);
    }

    public function test_production_can_be_created_as_draft_with_database_defaults(): void
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
        $production = DB::table('producciones')->where('id', $productionId)->first();

        $this->assertNotNull($production);
        $this->assertSame($this->recipeId, $production->receta_id);
        $this->assertSame($this->userId, $production->usuario_responsable_id);
        $this->assertSame($this->userId, $production->usuario_creador_id);
        $this->assertSame('borrador', $production->estado);
        $this->assertNull($production->usuario_confirmador_id);
        $this->assertNull($production->usuario_anulador_id);
        $this->assertNull($production->confirmada_en);
        $this->assertNull($production->anulada_en);
        $this->assertNull($production->motivo_anulacion);
        $this->assertNull($production->observacion);
        $this->assertNotNull($production->created_at);
        $this->assertNotNull($production->updated_at);
    }

    public function test_confirmed_production_with_complete_audit_is_accepted(): void
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'confirmada',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => self::CONFIRMED_AT,
            'created_at' => self::CREATED_AT,
        ]);
        $production = DB::table('producciones')->where('id', $productionId)->first();

        $this->assertSame('confirmada', $production->estado);
        $this->assertSame($this->userId, $production->usuario_confirmador_id);
        $this->assertNotNull($production->confirmada_en);
        $this->assertNull($production->anulada_en);
        $this->assertNull($production->usuario_anulador_id);
        $this->assertNull($production->motivo_anulacion);
    }

    public function test_annulled_production_with_complete_audit_is_accepted(): void
    {
        $annullingUserId = $this->createDistinctUser('produccion_anulador');
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'anulada',
            'usuario_confirmador_id' => $this->userId,
            'usuario_anulador_id' => $annullingUserId,
            'confirmada_en' => self::CONFIRMED_AT,
            'anulada_en' => self::ANNULLED_AT,
            'motivo_anulacion' => 'Produccion duplicada',
            'created_at' => self::CREATED_AT,
        ]);
        $production = DB::table('producciones')->where('id', $productionId)->first();

        $this->assertSame('anulada', $production->estado);
        $this->assertSame($annullingUserId, $production->usuario_anulador_id);
        $this->assertSame('Produccion duplicada', $production->motivo_anulacion);
    }

    #[DataProvider('invalidProductionStates')]
    public function test_invalid_production_state_is_rejected(string $state): void
    {
        $this->expectException(QueryException::class);
        $this->createProduction($this->recipeId, $this->userId, $this->userId, ['estado' => $state]);
    }

    public static function invalidProductionStates(): array
    {
        return [
            'uppercase draft' => ['BORRADOR'],
            'uppercase confirmed' => ['CONFIRMADA'],
            'uppercase annulled' => ['ANULADA'],
            'pending' => ['pendiente'],
            'cancelled' => ['cancelada'],
            'empty' => [''],
            'other' => ['otro'],
        ];
    }

    #[DataProvider('invalidDraftAuditStructures')]
    public function test_invalid_draft_audit_structure_is_rejected(
        ?string $confirmedAt,
        bool $withConfirmingUser,
        ?string $annulledAt,
        bool $withAnnullingUser,
        ?string $reason,
    ): void {
        $overrides = [
            'estado' => 'borrador',
            'confirmada_en' => $confirmedAt,
            'usuario_confirmador_id' => $withConfirmingUser ? $this->createDistinctUser('confirma_borrador') : null,
            'anulada_en' => $annulledAt,
            'usuario_anulador_id' => $withAnnullingUser ? $this->createDistinctUser('anula_borrador') : null,
            'motivo_anulacion' => $reason,
            'created_at' => self::CREATED_AT,
        ];

        $this->expectException(QueryException::class);
        $this->createProduction($this->recipeId, $this->userId, $this->userId, $overrides);
    }

    public static function invalidDraftAuditStructures(): array
    {
        return [
            'confirmation timestamp only' => [self::CONFIRMED_AT, false, null, false, null],
            'confirming user only' => [null, true, null, false, null],
            'chronologically valid audit timestamps' => [self::CONFIRMED_AT, false, self::ANNULLED_AT, false, null],
            'annulling user only' => [null, false, null, true, null],
            'annulment reason only' => [null, false, null, false, 'Motivo valido'],
        ];
    }

    #[DataProvider('invalidConfirmedAuditStructures')]
    public function test_invalid_confirmed_audit_structure_is_rejected(
        ?string $confirmedAt,
        bool $withConfirmingUser,
        ?string $annulledAt,
        bool $withAnnullingUser,
        ?string $reason,
    ): void {
        $overrides = [
            'estado' => 'confirmada',
            'confirmada_en' => $confirmedAt,
            'usuario_confirmador_id' => $withConfirmingUser ? $this->createDistinctUser('confirma_confirmada') : null,
            'anulada_en' => $annulledAt,
            'usuario_anulador_id' => $withAnnullingUser ? $this->createDistinctUser('anula_confirmada') : null,
            'motivo_anulacion' => $reason,
            'created_at' => self::CREATED_AT,
        ];

        $this->expectException(QueryException::class);
        $this->createProduction($this->recipeId, $this->userId, $this->userId, $overrides);
    }

    public static function invalidConfirmedAuditStructures(): array
    {
        return [
            'missing confirmation timestamp' => [null, true, null, false, null],
            'missing confirming user' => [self::CONFIRMED_AT, false, null, false, null],
            'annulment timestamp present' => [self::CONFIRMED_AT, true, self::ANNULLED_AT, false, null],
            'annulling user present' => [self::CONFIRMED_AT, true, null, true, null],
            'annulment reason present' => [self::CONFIRMED_AT, true, null, false, 'Motivo valido'],
        ];
    }

    #[DataProvider('invalidAnnulledAuditStructures')]
    public function test_invalid_annulled_audit_structure_is_rejected(
        ?string $confirmedAt,
        bool $withConfirmingUser,
        ?string $annulledAt,
        bool $withAnnullingUser,
        ?string $reason,
    ): void {
        $overrides = [
            'estado' => 'anulada',
            'confirmada_en' => $confirmedAt,
            'usuario_confirmador_id' => $withConfirmingUser ? $this->createDistinctUser('confirma_anulada') : null,
            'anulada_en' => $annulledAt,
            'usuario_anulador_id' => $withAnnullingUser ? $this->createDistinctUser('anula_anulada') : null,
            'motivo_anulacion' => $reason,
            'created_at' => self::CREATED_AT,
        ];

        $this->expectException(QueryException::class);
        $this->createProduction($this->recipeId, $this->userId, $this->userId, $overrides);
    }

    public static function invalidAnnulledAuditStructures(): array
    {
        return [
            'missing audit timestamps' => [null, true, null, true, 'Motivo valido'],
            'missing confirming user' => [self::CONFIRMED_AT, false, self::ANNULLED_AT, true, 'Motivo valido'],
            'missing annulment timestamp' => [self::CONFIRMED_AT, true, null, true, 'Motivo valido'],
            'missing annulling user' => [self::CONFIRMED_AT, true, self::ANNULLED_AT, false, 'Motivo valido'],
            'missing annulment reason' => [self::CONFIRMED_AT, true, self::ANNULLED_AT, true, null],
        ];
    }

    #[DataProvider('validProductionChronologies')]
    public function test_valid_production_chronology_is_accepted(
        string $state,
        string $confirmedAt,
        ?string $annulledAt,
    ): void {
        $overrides = [
            'estado' => $state,
            'confirmada_en' => $confirmedAt,
            'usuario_confirmador_id' => $this->userId,
            'created_at' => self::CREATED_AT,
        ];

        if ($state === 'anulada') {
            $overrides['anulada_en'] = $annulledAt;
            $overrides['usuario_anulador_id'] = $this->userId;
            $overrides['motivo_anulacion'] = 'Cronologia valida';
        }

        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId, $overrides);

        $this->assertSame($state, DB::table('producciones')->where('id', $productionId)->value('estado'));
    }

    public static function validProductionChronologies(): array
    {
        return [
            'confirmation equals creation' => ['confirmada', self::CREATED_AT, null],
            'confirmation after creation' => ['confirmada', self::CONFIRMED_AT, null],
            'annulment equals confirmation' => ['anulada', self::CONFIRMED_AT, self::CONFIRMED_AT],
            'annulment after confirmation' => ['anulada', self::CONFIRMED_AT, self::ANNULLED_AT],
        ];
    }

    #[DataProvider('invalidProductionChronologies')]
    public function test_invalid_production_chronology_is_rejected(
        string $state,
        string $confirmedAt,
        ?string $annulledAt,
    ): void {
        $overrides = [
            'estado' => $state,
            'confirmada_en' => $confirmedAt,
            'usuario_confirmador_id' => $this->userId,
            'created_at' => self::CREATED_AT,
        ];

        if ($state === 'anulada') {
            $overrides['anulada_en'] = $annulledAt;
            $overrides['usuario_anulador_id'] = $this->userId;
            $overrides['motivo_anulacion'] = 'Cronologia invalida';
        }

        $this->expectException(QueryException::class);
        $this->createProduction($this->recipeId, $this->userId, $this->userId, $overrides);
    }

    public static function invalidProductionChronologies(): array
    {
        return [
            'confirmation before creation' => ['confirmada', '2026-09-20 08:59:59-03', null],
            'annulment before confirmation' => ['anulada', self::CONFIRMED_AT, '2026-09-20 09:59:59-03'],
        ];
    }

    #[DataProvider('invalidProductionAnnulmentReasons')]
    public function test_invalid_production_annulment_reason_is_rejected(string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'anulada',
            'confirmada_en' => self::CONFIRMED_AT,
            'usuario_confirmador_id' => $this->userId,
            'anulada_en' => self::ANNULLED_AT,
            'usuario_anulador_id' => $this->userId,
            'motivo_anulacion' => $reason,
            'created_at' => self::CREATED_AT,
        ]);
    }

    public static function invalidProductionAnnulmentReasons(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Motivo'],
            'trailing space' => ['Motivo '],
        ];
    }

    public function test_valid_production_annulment_reason_is_preserved(): void
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'anulada',
            'confirmada_en' => self::CONFIRMED_AT,
            'usuario_confirmador_id' => $this->userId,
            'anulada_en' => self::ANNULLED_AT,
            'usuario_anulador_id' => $this->userId,
            'motivo_anulacion' => 'Merma detectada',
            'created_at' => self::CREATED_AT,
        ]);

        $this->assertSame(
            'Merma detectada',
            DB::table('producciones')->where('id', $productionId)->value('motivo_anulacion'),
        );
    }

    #[DataProvider('validProductionObservations')]
    public function test_valid_production_observation_is_preserved(?string $observation): void
    {
        $productionId = $this->createProduction(
            $this->recipeId,
            $this->userId,
            $this->userId,
            ['observacion' => $observation],
        );

        $this->assertSame($observation, DB::table('producciones')->where('id', $productionId)->value('observacion'));
    }

    public static function validProductionObservations(): array
    {
        return [
            'null' => [null],
            'trimmed text' => ['Turno de madrugada'],
        ];
    }

    #[DataProvider('invalidProductionObservations')]
    public function test_invalid_production_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createProduction(
            $this->recipeId,
            $this->userId,
            $this->userId,
            ['observacion' => $observation],
        );
    }

    public static function invalidProductionObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Observacion'],
            'trailing space' => ['Observacion '],
        ];
    }

    public function test_same_recipe_can_be_used_by_multiple_productions(): void
    {
        $firstId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
        $secondId = $this->createProduction($this->recipeId, $this->userId, $this->userId);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('producciones')->where('receta_id', $this->recipeId)->count());
    }

    public function test_inactive_recipe_is_structurally_allowed(): void
    {
        $inactiveRecipeId = $this->createRecipe($this->articleId, ['version' => 2, 'activa' => false]);
        $productionId = $this->createProduction($inactiveRecipeId, $this->userId, $this->userId);

        $this->assertFalse((bool) DB::table('recetas')->where('id', $inactiveRecipeId)->value('activa'));
        $this->assertSame($inactiveRecipeId, DB::table('producciones')->where('id', $productionId)->value('receta_id'));
    }

    public function test_same_user_can_fill_all_production_roles(): void
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'anulada',
            'confirmada_en' => self::CONFIRMED_AT,
            'usuario_confirmador_id' => $this->userId,
            'anulada_en' => self::ANNULLED_AT,
            'usuario_anulador_id' => $this->userId,
            'motivo_anulacion' => 'Mismo usuario',
            'created_at' => self::CREATED_AT,
        ]);
        $production = DB::table('producciones')->where('id', $productionId)->first();

        $this->assertSame($production->usuario_responsable_id, $production->usuario_creador_id);
        $this->assertSame($production->usuario_creador_id, $production->usuario_confirmador_id);
        $this->assertSame($production->usuario_confirmador_id, $production->usuario_anulador_id);
    }

    public function test_distinct_users_can_fill_production_roles(): void
    {
        $creatorId = $this->createDistinctUser('produccion_creador');
        $confirmingUserId = $this->createDistinctUser('produccion_confirmador');
        $annullingUserId = $this->createDistinctUser('produccion_anulador_distinto');
        $productionId = $this->createProduction($this->recipeId, $this->userId, $creatorId, [
            'estado' => 'anulada',
            'confirmada_en' => self::CONFIRMED_AT,
            'usuario_confirmador_id' => $confirmingUserId,
            'anulada_en' => self::ANNULLED_AT,
            'usuario_anulador_id' => $annullingUserId,
            'motivo_anulacion' => 'Usuarios distintos',
            'created_at' => self::CREATED_AT,
        ]);
        $production = DB::table('producciones')->where('id', $productionId)->first();

        $this->assertSame(4, count(array_unique([
            $production->usuario_responsable_id,
            $production->usuario_creador_id,
            $production->usuario_confirmador_id,
            $production->usuario_anulador_id,
        ])));
    }

    public function test_draft_production_can_exist_without_children(): void
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);

        $this->assertTrue(DB::table('producciones')->where('id', $productionId)->exists());
        $this->assertSame(0, DB::table('detalle_producciones')->where('produccion_id', $productionId)->count());
        $this->assertSame(0, DB::table('consumos_produccion')->where('produccion_id', $productionId)->count());
    }

    public function test_confirmed_production_can_exist_without_inventory_ledger(): void
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'confirmada',
            'confirmada_en' => self::CONFIRMED_AT,
            'usuario_confirmador_id' => $this->userId,
            'created_at' => self::CREATED_AT,
        ]);

        $this->assertTrue(DB::table('producciones')->where('id', $productionId)->exists());
        $this->assertSame(0, DB::table('detalle_producciones')->where('produccion_id', $productionId)->count());
        $this->assertSame(0, DB::table('consumos_produccion')->where('produccion_id', $productionId)->count());
        $this->assertSame('0.000', DB::table('articulos')->where('id', $this->articleId)->value('stock_actual'));
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Produccion',
            'nombre_usuario' => $username,
        ]);
    }
}
