<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\TestCase;

class CountIdempotencySchemaTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use RefreshDatabase;

    private const CREATION_INDEX = 'conteos_inventario_clave_idempotencia_creacion_unique';

    private const CONFIRMATION_INDEX = 'conteos_inventario_clave_idempotencia_confirmacion_unique';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'conteo_idempotencia_schema'],
        );
    }

    public function test_count_header_has_exactly_the_two_nullable_native_uuid_columns_without_defaults(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'conteos_inventario')
            ->orderBy('ordinal_position')
            ->get([
                'column_name',
                'data_type',
                'udt_name',
                'is_nullable',
                'column_default',
            ])
            ->keyBy('column_name');

        $this->assertSame([
            'id',
            'sesion_caja_id',
            'usuario_creador_id',
            'usuario_responsable_id',
            'usuario_confirmador_id',
            'estado',
            'confirmada_en',
            'observacion',
            'created_at',
            'updated_at',
            'clave_idempotencia_creacion',
            'clave_idempotencia_confirmacion',
        ], $columns->keys()->all());

        foreach ([
            'clave_idempotencia_creacion',
            'clave_idempotencia_confirmacion',
        ] as $column) {
            $this->assertSame('uuid', $columns[$column]->data_type, $column);
            $this->assertSame('uuid', $columns[$column]->udt_name, $column);
            $this->assertSame('YES', $columns[$column]->is_nullable, $column);
            $this->assertNull($columns[$column]->column_default, $column);
        }
    }

    public function test_idempotency_indexes_are_independent_unique_native_indexes_and_allow_nulls(): void
    {
        foreach ([
            self::CREATION_INDEX => 'clave_idempotencia_creacion',
            self::CONFIRMATION_INDEX => 'clave_idempotencia_confirmacion',
        ] as $indexName => $column) {
            $index = $this->indexMetadata($indexName);

            $this->assertSame('conteos_inventario', $index->table_name, $indexName);
            $this->assertSame([$column], json_decode($index->key_columns, true, flags: JSON_THROW_ON_ERROR), $indexName);
            $this->assertTrue($this->postgresBoolean($index->is_unique), $indexName);
            $this->assertFalse($this->postgresBoolean($index->is_primary), $indexName);
            $this->assertFalse($this->postgresBoolean($index->nulls_not_distinct), $indexName);
            $this->assertNull($index->predicate, $indexName);
        }

        $draftIndex = $this->indexMetadata('conteos_inventario_estado_borrador_unique');
        $this->assertSame(['estado'], json_decode($draftIndex->key_columns, true, flags: JSON_THROW_ON_ERROR));
        $this->assertTrue($this->postgresBoolean($draftIndex->is_unique));
        $this->assertDraftPredicate($draftIndex->predicate);
    }

    public function test_multiple_null_idempotency_keys_are_allowed_for_legacy_rows(): void
    {
        $this->createConfirmedCount();
        $this->createConfirmedCount(['observacion' => 'segundo legacy']);

        $this->assertSame(2, DB::table('conteos_inventario')
            ->whereNull('clave_idempotencia_creacion')
            ->whereNull('clave_idempotencia_confirmacion')
            ->count());
    }

    public function test_duplicate_creation_uuid_is_rejected_by_the_named_unique_index(): void
    {
        $uuid = '84000000-0000-4000-8000-000000000001';
        $this->createConfirmedCount(['clave_idempotencia_creacion' => $uuid]);

        try {
            $this->createConfirmedCount(['clave_idempotencia_creacion' => $uuid]);
            $this->fail('The duplicate creation UUID was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString(self::CREATION_INDEX, $exception->getMessage());
        }
    }

    public function test_duplicate_confirmation_uuid_is_rejected_by_the_named_unique_index(): void
    {
        $uuid = '84000000-0000-4000-8000-000000000002';
        $this->createConfirmedCount(['clave_idempotencia_confirmacion' => $uuid]);

        try {
            $this->createConfirmedCount(['clave_idempotencia_confirmacion' => $uuid]);
            $this->fail('The duplicate confirmation UUID was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
            $this->assertStringContainsString(self::CONFIRMATION_INDEX, $exception->getMessage());
        }
    }

    public function test_creation_and_confirmation_uuid_namespaces_are_independent(): void
    {
        $uuid = '84000000-0000-4000-8000-000000000003';
        $this->createConfirmedCount(['clave_idempotencia_creacion' => $uuid]);
        $this->createConfirmedCount(['clave_idempotencia_confirmacion' => $uuid]);

        $this->assertSame(1, DB::table('conteos_inventario')
            ->where('clave_idempotencia_creacion', $uuid)
            ->count());
        $this->assertSame(1, DB::table('conteos_inventario')
            ->where('clave_idempotencia_confirmacion', $uuid)
            ->count());
    }

    public function test_header_foreign_keys_checks_and_single_draft_guard_remain_intact(): void
    {
        $constraints = DB::select(<<<'SQL'
            SELECT con.conname AS name,
                   con.contype AS type,
                   pg_get_constraintdef(con.oid, true) AS definition
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname = 'conteos_inventario'
               AND con.contype IN ('c', 'f')
             ORDER BY con.conname
            SQL);

        $actual = [];

        foreach ($constraints as $constraint) {
            $actual[$constraint->name] = [
                'type' => $constraint->type,
                'definition' => $constraint->definition,
            ];
        }

        $this->assertSame([
            'conteos_inventario_confirmacion_consistente_check',
            'conteos_inventario_estado_valido_check',
            'conteos_inventario_fecha_confirmacion_valida_check',
            'conteos_inventario_observacion_valida_check',
            'conteos_inventario_sesion_caja_id_foreign',
            'conteos_inventario_usuario_confirmador_id_foreign',
            'conteos_inventario_usuario_creador_id_foreign',
            'conteos_inventario_usuario_responsable_id_foreign',
        ], array_keys($actual));

        $this->assertStringContainsString('borrador', $actual['conteos_inventario_estado_valido_check']['definition']);
        $this->assertStringContainsString('confirmado', $actual['conteos_inventario_estado_valido_check']['definition']);
        $this->assertStringContainsString('usuario_confirmador_id', $actual['conteos_inventario_confirmacion_consistente_check']['definition']);
        $this->assertStringContainsString('confirmada_en', $actual['conteos_inventario_confirmacion_consistente_check']['definition']);
        $this->assertStringContainsString('confirmada_en', $actual['conteos_inventario_fecha_confirmacion_valida_check']['definition']);
        $this->assertStringContainsString('created_at', $actual['conteos_inventario_fecha_confirmacion_valida_check']['definition']);
        $this->assertStringContainsString('btrim', $actual['conteos_inventario_observacion_valida_check']['definition']);

        foreach ([
            'conteos_inventario_sesion_caja_id_foreign' => 'sesiones_caja',
            'conteos_inventario_usuario_confirmador_id_foreign' => 'usuarios',
            'conteos_inventario_usuario_creador_id_foreign' => 'usuarios',
            'conteos_inventario_usuario_responsable_id_foreign' => 'usuarios',
        ] as $name => $table) {
            $this->assertSame('f', $actual[$name]['type']);
            $this->assertStringContainsString("REFERENCES {$table}(id)", $actual[$name]['definition']);
            $this->assertStringContainsString('ON DELETE RESTRICT', $actual[$name]['definition']);
        }

        $draftIndex = $this->indexMetadata('conteos_inventario_estado_borrador_unique');
        $this->assertTrue($this->postgresBoolean($draftIndex->is_unique));
        $this->assertFalse($this->postgresBoolean($draftIndex->nulls_not_distinct));
        $this->assertDraftPredicate($draftIndex->predicate);
    }

    public function test_detail_schema_remains_unchanged_and_has_no_difference_column(): void
    {
        $detailColumns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'detalle_conteos_inventario')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();

        $this->assertSame([
            'id',
            'conteo_inventario_id',
            'articulo_id',
            'articulo_sku_snapshot',
            'articulo_nombre_snapshot',
            'unidad_codigo_snapshot',
            'unidad_simbolo_snapshot',
            'cantidad_contada',
            'contada_en',
            'stock_teorico_snapshot',
            'created_at',
            'updated_at',
            'movimiento_inventario_observado_id',
        ], $detailColumns);
        $this->assertNotContains('diferencia', $detailColumns);

        $metadata = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'detalle_conteos_inventario')
            ->whereIn('column_name', [
                'cantidad_contada',
                'stock_teorico_snapshot',
                'movimiento_inventario_observado_id',
            ])
            ->get([
                'column_name',
                'data_type',
                'udt_name',
                'numeric_precision',
                'numeric_scale',
                'is_nullable',
            ])
            ->keyBy('column_name');

        foreach (['cantidad_contada', 'stock_teorico_snapshot'] as $column) {
            $this->assertSame('numeric', $metadata[$column]->data_type, $column);
            $this->assertSame('numeric', $metadata[$column]->udt_name, $column);
            $this->assertSame(14, (int) $metadata[$column]->numeric_precision, $column);
            $this->assertSame(3, (int) $metadata[$column]->numeric_scale, $column);
            $this->assertSame('YES', $metadata[$column]->is_nullable, $column);
        }

        $this->assertSame('bigint', $metadata['movimiento_inventario_observado_id']->data_type);
        $this->assertSame('YES', $metadata['movimiento_inventario_observado_id']->is_nullable);
    }

    private function createConfirmedCount(array $overrides = []): int
    {
        return $this->createInventoryCount($this->userId, $this->userId, array_merge([
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => '2026-09-20 10:00:00+00',
            'created_at' => '2026-09-20 09:00:00+00',
        ], $overrides));
    }

    private function indexMetadata(string $indexName): object
    {
        return DB::selectOne(<<<'SQL'
            SELECT tbl.relname AS table_name,
                   ind.indisunique AS is_unique,
                   ind.indisprimary AS is_primary,
                   ind.indnullsnotdistinct AS nulls_not_distinct,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(ind.indkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = tbl.oid AND att.attnum = keys.attnum
                        WHERE keys.ordinality <= ind.indnkeyatts
                   )::text AS key_columns,
                   pg_get_expr(ind.indpred, ind.indrelid) AS predicate
              FROM pg_catalog.pg_index AS ind
              JOIN pg_catalog.pg_class AS idx ON idx.oid = ind.indexrelid
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = ind.indrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND idx.relname = ?
            SQL, [$indexName]);
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function assertDraftPredicate(?string $predicate): void
    {
        $this->assertNotNull($predicate);

        $normalized = strtolower($predicate);
        $normalized = preg_replace('/::(?:text|character varying)/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[\s()]+/', '', $normalized) ?? $normalized;

        $this->assertSame("estado='borrador'", $normalized);
    }
}
