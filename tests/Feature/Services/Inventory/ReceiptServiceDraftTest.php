<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Enums\PermissionCode;
use App\Enums\ReceiptOriginType;
use App\Enums\ReceiptStatus;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\DetalleRecepcionMercaderia;
use App\Models\RecepcionMercaderia;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryLedgerWriter;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\ReceiptService;
use App\Services\Inventory\StockDailyRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;
use Throwable;

final class ReceiptServiceDraftTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const COMMAND = 'b1100000-0000-4000-8000-000000000001';

    private const OTHER_COMMAND = 'b1100000-0000-4000-8000-000000000002';

    private User $actor;

    private User $contributor;

    private Articulo $article;

    private Articulo $otherArticle;

    private int $supplierId;

    private int $roleId;

    private array $permissionIds;

    private ?CarbonImmutable $previousTestNow = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousTestNow = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08T01:30:00.123456+00:00'));
        $this->roleId = $this->createRole(['codigo' => 'operador_recepcion', 'nombre' => 'Operador de recepcion']);
        $this->permissionIds = [];

        foreach ([
            PermissionCode::InventoryReceiptsCreate, PermissionCode::InventoryReceiptsEdit,
            PermissionCode::InventoryReceiptsOtherOrigin, PermissionCode::InventoryReceiptsRetroactive,
        ] as $permission) {
            $id = $this->createPermission([
                'codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(),
            ]);
            $this->attachPermission($this->roleId, $id);
            $this->permissionIds[$permission->value] = $id;
        }

        $password = Hash::make('receipt-test-only');
        $this->actor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'receipt_actor', 'password' => $password,
        ]));
        $this->contributor = User::query()->findOrFail($this->createUser($this->roleId, [
            'nombre_usuario' => 'receipt_contributor', 'password' => $password,
        ]));
        $this->supplierId = $this->createSupplier();
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->article = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'recepcion_pan', 'stock_actual' => '10.000',
        ]));
        $this->otherArticle = Articulo::query()->findOrFail($this->createArticle($categoryId, $unitId, [
            'sku' => 'recepcion_harina', 'nombre' => 'Harina de prueba', 'stock_actual' => '4.000',
        ]));
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow($this->previousTestNow);
        } finally {
            parent::tearDown();
        }
    }

    public function test_supplier_draft_has_only_the_actual_creator_and_no_historical_fields(): void
    {
        $before = $this->physicalState();
        $receipt = $this->supplierDraft();

        $this->assertSame(ReceiptStatus::Draft, $receipt->estado);
        $this->assertSame(ReceiptOriginType::Supplier, $receipt->tipo_origen);
        $this->assertSame($this->supplierId, $receipt->proveedor_id);
        $this->assertSame((int) $this->actor->getKey(), $receipt->usuario_creador_id);
        $this->assertSame(self::COMMAND, $receipt->clave_idempotencia);
        foreach ([
            'usuario_aportante_id', 'origen_detalle', 'usuario_confirmador_id', 'usuario_anulador_id',
            'confirmada_en', 'anulada_en', 'motivo_anulacion', 'motivo_ajuste_ocurrido_en',
        ] as $column) {
            $this->assertNull($receipt->getAttribute($column), $column);
        }
        $this->assertTrue($receipt->ocurrido_en->equalTo($receipt->created_at));
        $this->assertSame($before, $this->physicalState());
    }

    public function test_internal_contributor_can_differ_from_creator_without_an_invented_active_policy(): void
    {
        DB::table('usuarios')->where('id', $this->contributor->getKey())->update(['activo' => false]);
        $receipt = $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::Internal,
            contributorId: (int) $this->contributor->getKey(),
        );

        $this->assertSame(ReceiptOriginType::Internal, $receipt->tipo_origen);
        $this->assertSame((int) $this->contributor->getKey(), $receipt->usuario_aportante_id);
        $this->assertSame((int) $this->actor->getKey(), $receipt->usuario_creador_id);
        $this->assertNull($receipt->proveedor_id);
        $this->assertNull($receipt->origen_detalle);
    }

    public function test_other_authorized_origin_trims_detail_and_observation(): void
    {
        $receipt = $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::AuthorizedOther,
            originDetail: "  Aporte documentado\t", observation: '  Revisar empaque  ',
        );

        $this->assertSame('Aporte documentado', $receipt->origen_detalle);
        $this->assertSame('Revisar empaque', $receipt->observacion);
        $this->assertNull($receipt->proveedor_id);
        $this->assertNull($receipt->usuario_aportante_id);
        $this->assertArrayNotHasKey('usuario_autorizador_id', $receipt->getAttributes());
    }

    #[DataProvider('invalidOrigins')]
    public function test_origin_invariants_are_rejected_semantically_without_partial_rows(
        ReceiptOriginType $origin, ?string $supplier, ?string $contributor, ?string $detail, string $exception,
    ): void {
        $this->assertRejectedWithoutChanges($exception, fn () => $this->service()->createDraft(
            $this->actor, self::COMMAND, $origin,
            supplierId: $supplier === 'valid' ? $this->supplierId : ($supplier === 'missing' ? PHP_INT_MAX : null),
            contributorId: $contributor === 'valid' ? (int) $this->contributor->getKey()
                : ($contributor === 'missing' ? PHP_INT_MAX : null),
            originDetail: $detail,
        ));
    }

    public static function invalidOrigins(): array
    {
        return [
            'supplier required' => [ReceiptOriginType::Supplier, null, null, null, InvalidArgumentException::class],
            'supplier missing' => [ReceiptOriginType::Supplier, 'missing', null, null, ModelNotFoundException::class],
            'supplier plus contributor' => [ReceiptOriginType::Supplier, 'valid', 'valid', null, InvalidArgumentException::class],
            'supplier plus detail' => [ReceiptOriginType::Supplier, 'valid', null, 'Extra', InvalidArgumentException::class],
            'contributor required' => [ReceiptOriginType::Internal, null, null, null, InvalidArgumentException::class],
            'contributor missing' => [ReceiptOriginType::Internal, null, 'missing', null, ModelNotFoundException::class],
            'internal plus supplier' => [ReceiptOriginType::Internal, 'valid', 'valid', null, InvalidArgumentException::class],
            'internal plus detail' => [ReceiptOriginType::Internal, null, 'valid', 'Extra', InvalidArgumentException::class],
            'other plus supplier' => [ReceiptOriginType::AuthorizedOther, 'valid', null, 'Aporte', InvalidArgumentException::class],
            'other plus contributor' => [ReceiptOriginType::AuthorizedOther, null, 'valid', 'Aporte', InvalidArgumentException::class],
            'other blank detail' => [ReceiptOriginType::AuthorizedOther, null, null, '   ', InvalidArgumentException::class],
            'other missing detail' => [ReceiptOriginType::AuthorizedOther, null, null, null, InvalidArgumentException::class],
        ];
    }

    #[DataProvider('creationPermissions')]
    public function test_creation_requires_base_and_special_effective_permissions(PermissionCode $permission): void
    {
        $this->revoke($permission);
        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::AuthorizedOther, originDetail: 'Aporte',
        ));
    }

    public static function creationPermissions(): array
    {
        return [
            'base create' => [PermissionCode::InventoryReceiptsCreate],
            'other origin' => [PermissionCode::InventoryReceiptsOtherOrigin],
        ];
    }

    public function test_uuid_retry_normalizes_payload_and_keeps_original_time_after_clock_advances(): void
    {
        $original = $this->service()->createDraft(
            $this->actor, strtoupper(self::COMMAND), ReceiptOriginType::Supplier,
            supplierId: $this->supplierId, observation: '  Entrega  ',
        );
        $before = $this->draftState();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addDay());
        $retry = $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::Supplier,
            supplierId: $this->supplierId, observation: 'Entrega',
        );

        $this->assertSame($original->getKey(), $retry->getKey());
        $this->assertSame($before, $this->draftState());
        $this->assertDatabaseCount('recepciones_mercaderia', 1);
    }

    public function test_uuid_incompatible_payload_is_a_domain_conflict(): void
    {
        $this->supplierDraft();
        $this->assertRejectedWithoutChanges(IdempotencyConflictException::class, fn () => $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::Internal,
            contributorId: (int) $this->contributor->getKey(),
        ));
        $this->assertRejectedWithoutChanges(IdempotencyConflictException::class, fn () => $this->service()->createDraft(
            $this->contributor, self::COMMAND, ReceiptOriginType::Supplier, supplierId: $this->supplierId,
        ));
    }

    public function test_uuid_is_required_and_must_be_valid(): void
    {
        $this->assertRejectedWithoutChanges(InvalidArgumentException::class, fn () => $this->service()->createDraft(
            $this->actor, 'invalid', ReceiptOriginType::Supplier, supplierId: $this->supplierId,
        ));
    }

    #[DataProvider('revokedCreationAuthorization')]
    public function test_identical_retry_checks_fresh_authorization(string $target): void
    {
        $this->supplierDraft();
        match ($target) {
            'user' => DB::table('usuarios')->where('id', $this->actor->getKey())->update(['activo' => false]),
            'role' => DB::table('roles')->where('id', $this->roleId)->update(['activo' => false]),
            'permission' => DB::table('permisos')->where('id', $this->permissionIds[PermissionCode::InventoryReceiptsCreate->value])
                ->update(['activo' => false]),
            'pivot' => $this->revoke(PermissionCode::InventoryReceiptsCreate),
        };
        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->supplierDraft());
    }

    public static function revokedCreationAuthorization(): array
    {
        return ['inactive actor' => ['user'], 'inactive role' => ['role'], 'inactive permission' => ['permission'], 'removed pivot' => ['pivot']];
    }

    public function test_retroactive_same_commercial_day_uses_exact_instant_and_preserves_microseconds(): void
    {
        $now = CarbonImmutable::now('UTC');
        $occurred = $now->subSecond()->setTimezone('America/Santiago');
        $receipt = $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::Supplier, supplierId: $this->supplierId,
            occurredAt: $occurred, occurrenceReason: '  Entrega registrada tarde  ',
        );

        $this->assertSame('Entrega registrada tarde', $receipt->motivo_ajuste_ocurrido_en);
        $this->assertSame($occurred->setTimezone('UTC')->format('Y-m-d H:i:s.uP'), $receipt->ocurrido_en->format('Y-m-d H:i:s.uP'));
        $this->assertTrue($receipt->created_at->equalTo($now));
        $this->assertTrue($receipt->ocurrido_en->lessThan($receipt->created_at));
    }

    #[DataProvider('invalidTemporalDeclarations')]
    public function test_invalid_temporal_declaration_has_no_partial_effects(
        int $seconds, ?string $reason, bool $revokeRetroactive, string $exception,
    ): void {
        if ($revokeRetroactive) {
            $this->revoke(PermissionCode::InventoryReceiptsRetroactive);
        }
        $this->assertRejectedWithoutChanges($exception, fn () => $this->service()->createDraft(
            $this->actor, self::COMMAND, ReceiptOriginType::Supplier, supplierId: $this->supplierId,
            occurredAt: CarbonImmutable::now('UTC')->addSeconds($seconds), occurrenceReason: $reason,
        ));
    }

    public static function invalidTemporalDeclarations(): array
    {
        return [
            'future' => [1, null, false, InvalidArgumentException::class],
            'past without permission' => [-1, 'Entrega tardia', true, AuthorizationException::class],
            'past without reason' => [-1, null, false, InvalidArgumentException::class],
            'past blank reason' => [-1, '   ', false, InvalidArgumentException::class],
            'normal extra reason' => [0, 'Sobrante', false, InvalidArgumentException::class],
        ];
    }

    public function test_update_revalidates_origin_and_time_without_replacing_creation_evidence(): void
    {
        $receipt = $this->supplierDraft();
        $created = $receipt->created_at;
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $updated = $this->service()->updateDraft(
            $this->actor, (int) $receipt->getKey(), ReceiptOriginType::Internal,
            contributorId: (int) $this->contributor->getKey(), occurredAt: $created->subMinute(),
            occurrenceReason: '  Registro corregido  ', observation: '  Nueva observacion  ',
        );

        $this->assertSame(ReceiptStatus::Draft, $updated->estado);
        $this->assertSame(ReceiptOriginType::Internal, $updated->tipo_origen);
        $this->assertNull($updated->proveedor_id);
        $this->assertNull($updated->origen_detalle);
        $this->assertTrue($updated->created_at->equalTo($created));
        $this->assertTrue($updated->ocurrido_en->equalTo($created->subMinute()));
        $this->assertSame('Registro corregido', $updated->motivo_ajuste_ocurrido_en);
        $this->assertSame('Nueva observacion', $updated->observacion);
        $this->assertSame((int) $this->actor->getKey(), $updated->usuario_creador_id);
    }

    public function test_update_cannot_move_occurrence_beyond_original_creation_even_if_not_future_now(): void
    {
        $receipt = $this->supplierDraft();
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addHour());
        $this->assertRejectedWithoutChanges(InvalidArgumentException::class, fn () => $this->service()->updateDraft(
            $this->actor, (int) $receipt->getKey(), ReceiptOriginType::Supplier, supplierId: $this->supplierId,
            occurredAt: $receipt->created_at->addMinute(),
        ));
        $this->assertRejectedWithoutChanges(AuthorizationException::class, function () use ($receipt): void {
            $this->revoke(PermissionCode::InventoryReceiptsOtherOrigin);
            $this->service()->updateDraft(
                $this->actor, (int) $receipt->getKey(), ReceiptOriginType::AuthorizedOther, originDetail: 'Aporte',
            );
        });
    }

    #[DataProvider('draftMutations')]
    public function test_every_existing_aggregate_mutation_reauthorizes_edit_permission(string $operation): void
    {
        $receipt = $this->supplierDraft();
        $line = $this->addLine($receipt);
        $this->revoke(PermissionCode::InventoryReceiptsEdit);
        $this->assertRejectedWithoutChanges(AuthorizationException::class, fn () => $this->mutate($operation, $receipt, $line));
    }

    public static function draftMutations(): array
    {
        return ['update header' => ['update'], 'add line' => ['add'], 'update line' => ['line'], 'remove line' => ['remove'], 'delete draft' => ['delete']];
    }

    #[DataProvider('historicalMutations')]
    public function test_confirmed_and_annulled_history_is_immutable(ReceiptStatus $status, string $operation): void
    {
        $receipt = $this->supplierDraft();
        $line = $this->addLine($receipt);
        $this->makeHistorical($receipt, $status);
        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->mutate($operation, $receipt, $line));
    }

    public static function historicalMutations(): array
    {
        $cases = [];
        foreach ([ReceiptStatus::Confirmed, ReceiptStatus::Annulled] as $status) {
            foreach (self::draftMutations() as [$operation]) {
                $cases[$status->value.' '.$operation] = [$status, $operation];
            }
        }
        return $cases;
    }

    public function test_lines_preserve_null_draft_values_and_exact_complete_values(): void
    {
        $receipt = $this->supplierDraft();
        $incomplete = $this->addLine($receipt);
        $complete = $this->service()->addLine(
            $this->actor, (int) $receipt->getKey(), (int) $this->otherArticle->getKey(), '10.250', '1234.50',
        );

        $this->assertNull($incomplete->cantidad);
        $this->assertNull($incomplete->costo_unitario);
        $this->assertNull($incomplete->motivo_costo_cero);
        $this->assertSame('10.250', $complete->cantidad);
        $this->assertSame('1234.50', $complete->costo_unitario);
        $this->assertNull($complete->motivo_costo_cero);
        $this->assertSame($this->article->sku, $incomplete->articulo_sku_snapshot);
        $this->assertSame($this->article->nombre, $incomplete->articulo_nombre_snapshot);
        $this->assertSame('kg', $incomplete->unidad_codigo_snapshot);
        $this->assertSame('kg', $incomplete->unidad_simbolo_snapshot);
        $this->assertCount(2, $receipt->refresh()->detalles);
        $this->assertSame($receipt->getKey(), $incomplete->recepcion->getKey());
    }

    public function test_zero_cost_requires_and_normalizes_its_reason(): void
    {
        $receipt = $this->supplierDraft();
        $line = $this->service()->addLine(
            $this->actor, (int) $receipt->getKey(), (int) $this->article->getKey(), '1', '0', '  Aporte gratuito  ',
        );
        $this->assertSame('1.000', $line->cantidad);
        $this->assertSame('0.00', $line->costo_unitario);
        $this->assertSame('Aporte gratuito', $line->motivo_costo_cero);
    }

    #[DataProvider('invalidLineValues')]
    public function test_invalid_line_values_are_rejected_before_sql_and_leave_no_partial_line(
        ?string $quantity, ?string $cost, ?string $reason, string $exception,
    ): void {
        $receipt = $this->supplierDraft();
        $this->assertRejectedWithoutChanges($exception, fn () => $this->service()->addLine(
            $this->actor, (int) $receipt->getKey(), (int) $this->article->getKey(), $quantity, $cost, $reason,
        ));
    }

    public static function invalidLineValues(): array
    {
        return [
            'quantity zero' => ['0.000', null, null, InvalidArgumentException::class],
            'quantity negative' => ['-1.000', null, null, InvalidArgumentException::class],
            'quantity scale' => ['1.0001', null, null, InvalidArgumentException::class],
            'quantity scientific' => ['1e3', null, null, InvalidArgumentException::class],
            'quantity comma' => ['1,5', null, null, InvalidArgumentException::class],
            'quantity whitespace' => [' 1.000', null, null, InvalidArgumentException::class],
            'quantity NaN' => ['NaN', null, null, InvalidArgumentException::class],
            'quantity range' => ['100000000000.000', null, null, OutOfRangeException::class],
            'cost negative' => [null, '-1.00', null, InvalidArgumentException::class],
            'cost scale' => [null, '1.001', null, InvalidArgumentException::class],
            'cost scientific' => [null, '1e2', null, InvalidArgumentException::class],
            'cost comma' => [null, '1,50', null, InvalidArgumentException::class],
            'cost whitespace' => [null, '1.00 ', null, InvalidArgumentException::class],
            'cost range' => [null, '1000000000000.00', null, OutOfRangeException::class],
            'zero missing reason' => [null, '0.00', null, InvalidArgumentException::class],
            'zero blank reason' => [null, '0.00', '   ', InvalidArgumentException::class],
            'positive extra reason' => [null, '1.00', 'Sobrante', InvalidArgumentException::class],
            'null extra reason' => [null, null, 'Sobrante', InvalidArgumentException::class],
        ];
    }

    public function test_missing_and_inactive_articles_are_rejected_semantically(): void
    {
        $receipt = $this->supplierDraft();
        $this->assertRejectedWithoutChanges(ModelNotFoundException::class, fn () => $this->service()->addLine(
            $this->actor, (int) $receipt->getKey(), PHP_INT_MAX,
        ));
        DB::table('articulos')->where('id', $this->article->getKey())->update(['activo' => false]);
        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->addLine($receipt));
    }

    public function test_duplicate_article_is_rejected_but_the_same_article_in_another_draft_is_allowed(): void
    {
        $receipt = $this->supplierDraft();
        $this->addLine($receipt);
        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->addLine($receipt));
        $other = $this->supplierDraft(self::OTHER_COMMAND);
        $this->addLine($other);
        $this->assertDatabaseCount('detalle_recepciones_mercaderia', 2);
    }

    public function test_update_line_replaces_article_and_values_then_can_restore_incomplete_nulls(): void
    {
        $receipt = $this->supplierDraft();
        $line = $this->addLine($receipt);
        $updated = $this->service()->updateLine(
            $this->actor, (int) $receipt->getKey(), (int) $line->getKey(), (int) $this->otherArticle->getKey(),
            '10.250', '0.00', '  Gratuito  ',
        );
        $this->assertSame((int) $this->otherArticle->getKey(), $updated->articulo_id);
        $this->assertSame($this->otherArticle->sku, $updated->articulo_sku_snapshot);
        $this->assertSame('10.250', $updated->cantidad);
        $this->assertSame('0.00', $updated->costo_unitario);
        $this->assertSame('Gratuito', $updated->motivo_costo_cero);
        $updated = $this->service()->updateLine(
            $this->actor, (int) $receipt->getKey(), (int) $line->getKey(), (int) $this->otherArticle->getKey(),
        );
        $this->assertNull($updated->cantidad);
        $this->assertNull($updated->costo_unitario);
        $this->assertNull($updated->motivo_costo_cero);
    }

    public function test_update_line_revalidates_activity_uniqueness_and_decimal_rules(): void
    {
        $receipt = $this->supplierDraft();
        $line = $this->addLine($receipt);
        $this->service()->addLine($this->actor, (int) $receipt->getKey(), (int) $this->otherArticle->getKey());
        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->service()->updateLine(
            $this->actor, (int) $receipt->getKey(), (int) $line->getKey(), (int) $this->otherArticle->getKey(),
        ));
        DB::table('articulos')->where('id', $this->otherArticle->getKey())->update(['activo' => false]);
        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->service()->updateLine(
            $this->actor, (int) $receipt->getKey(), (int) $line->getKey(), (int) $this->otherArticle->getKey(),
        ));
        $this->assertRejectedWithoutChanges(InvalidArgumentException::class, fn () => $this->service()->updateLine(
            $this->actor, (int) $receipt->getKey(), (int) $line->getKey(), (int) $this->article->getKey(), '0.000',
        ));
    }

    #[DataProvider('childMutations')]
    public function test_line_of_another_receipt_cannot_be_mutated(string $operation): void
    {
        $receipt = $this->supplierDraft();
        $other = $this->supplierDraft(self::OTHER_COMMAND);
        $line = $this->addLine($other);
        $this->assertRejectedWithoutChanges(InventoryDomainException::class, fn () => $this->mutate($operation, $receipt, $line));
    }

    public static function childMutations(): array
    {
        return ['update foreign line' => ['line'], 'remove foreign line' => ['remove']];
    }

    public function test_remove_and_delete_draft_leave_no_orphans_or_physical_effects(): void
    {
        $before = $this->physicalState();
        $receipt = $this->supplierDraft();
        $line = $this->addLine($receipt);
        $this->service()->removeLine($this->actor, (int) $receipt->getKey(), (int) $line->getKey());
        $this->assertDatabaseCount('detalle_recepciones_mercaderia', 0);
        $this->assertDatabaseCount('recepciones_mercaderia', 1);
        $this->addLine($receipt);
        $this->service()->addLine($this->actor, (int) $receipt->getKey(), (int) $this->otherArticle->getKey());
        $this->service()->deleteDraft($this->actor, (int) $receipt->getKey());
        $this->assertDatabaseCount('detalle_recepciones_mercaderia', 0);
        $this->assertDatabaseCount('recepciones_mercaderia', 0);
        $this->assertSame($before, $this->physicalState());
    }

    public function test_all_draft_crud_is_physically_silent_even_with_existing_cost_and_daily_rows(): void
    {
        $this->createArticleCostHistory((int) $this->article->getKey(), (int) $this->actor->getKey());
        $this->createStockDaily((int) $this->article->getKey());
        $before = $this->physicalState();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void { $queries[] = $query->sql; });
        $receipt = $this->supplierDraft();
        $this->service()->updateDraft($this->actor, (int) $receipt->getKey(), ReceiptOriginType::Supplier, supplierId: $this->supplierId);
        $line = $this->service()->addLine($this->actor, (int) $receipt->getKey(), (int) $this->article->getKey(), '10.250', '1234.50');
        $this->service()->updateLine($this->actor, (int) $receipt->getKey(), (int) $line->getKey(), (int) $this->article->getKey(), '2', '10');
        $this->service()->removeLine($this->actor, (int) $receipt->getKey(), (int) $line->getKey());
        $this->service()->deleteDraft($this->actor, (int) $receipt->getKey());
        $this->assertSame($before, $this->physicalState());
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool =>
            preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+"?(articulos|movimientos_inventario|stock_diario|historial_costos_articulo|movimientos_caja|sesiones_caja)"?\b/i', $sql) === 1
        )));
    }

    #[DataProvider('lockingOperations')]
    public function test_workflow_owns_one_transaction_and_locks_root_before_children_without_article_locks(string $operation): void
    {
        $receipt = $this->supplierDraft();
        $line = $this->addLine($receipt);
        $level = DB::connection()->transactionLevel();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        });
        $this->mutate($operation, $receipt, $line);
        $locks = array_filter($queries, static fn (array $q): bool => str_contains($q['sql'], 'for update'));
        $sql = array_column($locks, 'sql');
        $this->assertCount(2, $sql);
        $this->assertStringContainsString('from "recepciones_mercaderia"', $sql[0]);
        $this->assertStringContainsString('from "detalle_recepciones_mercaderia"', $sql[1]);
        if ($operation === 'delete') {
            $this->assertStringContainsString('order by "id" asc', $sql[1]);
        }
        foreach ($queries as $query) {
            if (str_contains($query['sql'], 'for update') || preg_match('/^\s*(insert|update|delete)\b/i', $query['sql']) === 1) {
                $this->assertSame($level + 1, $query['level']);
            }
        }
        $this->assertSame($level, DB::connection()->transactionLevel());
    }

    public static function lockingOperations(): array
    {
        return ['update line' => ['line'], 'remove line' => ['remove'], 'delete draft' => ['delete']];
    }

    public function test_models_and_public_api_protect_workflow_fields_and_allow_approved_evolution(): void
    {
        $receipt = new RecepcionMercaderia();
        $line = new DetalleRecepcionMercaderia();
        $this->assertSame('recepciones_mercaderia', $receipt->getTable());
        $this->assertSame('detalle_recepciones_mercaderia', $line->getTable());
        foreach ([$receipt, $line] as $model) {
            $this->assertSame(['*'], $model->getGuarded());
            $this->assertSame([], $model->getFillable());
            $this->assertSame('Y-m-d H:i:s.u', $model->getDateFormat());
            $this->assertSame('immutable_datetime', $model->getCasts()['created_at']);
            $this->assertSame('immutable_datetime', $model->getCasts()['updated_at']);
        }
        $this->assertSame(ReceiptStatus::class, $receipt->getCasts()['estado']);
        $this->assertSame(ReceiptOriginType::class, $receipt->getCasts()['tipo_origen']);
        $this->assertSame('immutable_datetime', $receipt->getCasts()['ocurrido_en']);
        $this->assertSame('decimal:3', $line->getCasts()['cantidad']);
        $this->assertSame('decimal:2', $line->getCasts()['costo_unitario']);
        $methods = (new ReflectionClass(ReceiptService::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodNames = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(), $methods,
        );
        $requiredMethods = ['__construct', 'createDraft', 'updateDraft', 'addLine', 'updateLine', 'removeLine', 'deleteDraft'];
        $allowedMethods = [...$requiredMethods, 'confirm', 'annul'];
        $this->assertSame([], array_values(array_diff($requiredMethods, $methodNames)));
        $this->assertSame([], array_values(array_diff($methodNames, $allowedMethods)));
        $this->assertSame(in_array('confirm', $methodNames, true), in_array('annul', $methodNames, true));
        $constructor = new ReflectionMethod(ReceiptService::class, '__construct');
        $constructorDependencies = array_map(
            static fn ($p): string => $p->getType()->getName(), $constructor->getParameters(),
        );
        $allowedDependencies = [
            EffectivePermissionResolver::class,
            InventoryLockManager::class,
            InventoryStockService::class,
            StockDailyRebuilder::class,
            InventoryLedgerWriter::class,
        ];
        $this->assertContains(EffectivePermissionResolver::class, $constructorDependencies);
        $this->assertSame([], array_values(array_diff($constructorDependencies, $allowedDependencies)));
        $this->assertSame($constructorDependencies, array_values(array_unique($constructorDependencies)));
        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotContains($parameter->getName(), [
                    'estado', 'status', 'creatorId', 'authorizerId', 'confirmador', 'anulador', 'stock', 'force', 'override',
                ]);
                $this->assertNotContains($parameter->getType()->getName(), ['array', 'float', 'bool']);
                if (in_array($parameter->getName(), ['quantity', 'unitCost'], true)) {
                    $this->assertSame('string', $parameter->getType()->getName());
                    $this->assertTrue($parameter->getType()->allowsNull());
                }
            }
        }
    }

    private function service(): ReceiptService
    {
        return $this->app->make(ReceiptService::class);
    }

    private function supplierDraft(string $command = self::COMMAND): RecepcionMercaderia
    {
        return $this->service()->createDraft($this->actor, $command, ReceiptOriginType::Supplier, supplierId: $this->supplierId);
    }

    private function addLine(RecepcionMercaderia $receipt): DetalleRecepcionMercaderia
    {
        return $this->service()->addLine($this->actor, (int) $receipt->getKey(), (int) $this->article->getKey());
    }

    private function revoke(PermissionCode $permission): void
    {
        DB::table('rol_permiso')->where('rol_id', $this->roleId)->where('permiso_id', $this->permissionIds[$permission->value])->delete();
    }

    private function mutate(string $operation, RecepcionMercaderia $receipt, DetalleRecepcionMercaderia $line): void
    {
        $id = (int) $receipt->getKey();
        match ($operation) {
            'update' => $this->service()->updateDraft($this->actor, $id, ReceiptOriginType::Supplier, supplierId: $this->supplierId),
            'add' => $this->service()->addLine($this->actor, $id, (int) $this->otherArticle->getKey()),
            'line' => $this->service()->updateLine($this->actor, $id, (int) $line->getKey(), (int) $this->article->getKey(), '2', '10'),
            'remove' => $this->service()->removeLine($this->actor, $id, (int) $line->getKey()),
            'delete' => $this->service()->deleteDraft($this->actor, $id),
        };
    }

    private function makeHistorical(RecepcionMercaderia $receipt, ReceiptStatus $status): void
    {
        $annulled = $status === ReceiptStatus::Annulled;
        // DDL-valid historical fixture, not an operational confirmation or annulment.
        DB::table('recepciones_mercaderia')->where('id', $receipt->getKey())->update([
            'estado' => $status->value, 'usuario_confirmador_id' => $this->actor->getKey(),
            'confirmada_en' => $receipt->created_at->addMinute()->format('Y-m-d H:i:s.u'),
            'usuario_anulador_id' => $annulled ? $this->actor->getKey() : null,
            'anulada_en' => $annulled ? $receipt->created_at->addMinutes(2)->format('Y-m-d H:i:s.u') : null,
            'motivo_anulacion' => $annulled ? 'Fixture historico' : null,
        ]);
    }

    private function physicalState(): array
    {
        return $this->tableState(['articulos', 'movimientos_inventario', 'stock_diario', 'historial_costos_articulo', 'movimientos_caja', 'sesiones_caja']);
    }

    private function draftState(): array
    {
        return $this->tableState(['recepciones_mercaderia', 'detalle_recepciones_mercaderia']);
    }

    private function tableState(array $tables): array
    {
        return array_map(static fn (string $table): array => DB::table($table)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all(), $tables);
    }

    private function assertRejectedWithoutChanges(string $exceptionClass, callable $operation): void
    {
        $before = [$this->draftState(), $this->physicalState()];
        $caught = null;
        try {
            $operation();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $this->assertInstanceOf($exceptionClass, $caught);
        $this->assertSame($before, [$this->draftState(), $this->physicalState()]);
    }
}
