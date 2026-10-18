<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockElevenSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'recepciones_mercaderia',
        'detalle_recepciones_mercaderia',
        'movimientos_inventario',
        'stock_diario',
    ];

    public function test_public_schema_contains_exactly_the_block_eleven_tables(): void
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->whereIn('table_name', self::TABLES)
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();
        $expected = self::TABLES;
        sort($expected);

        $this->assertSame($expected, $tables);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_block_eleven_columns_match_the_approved_layout(): void
    {
        $expected = [
            'recepciones_mercaderia' => [
                'id', 'clave_idempotencia', 'tipo_origen', 'proveedor_id',
                'usuario_aportante_id', 'origen_detalle', 'usuario_creador_id',
                'usuario_confirmador_id', 'usuario_anulador_id', 'estado',
                'ocurrido_en', 'motivo_ajuste_ocurrido_en', 'confirmada_en',
                'anulada_en', 'motivo_anulacion', 'observacion', 'created_at', 'updated_at',
            ],
            'detalle_recepciones_mercaderia' => [
                'id', 'recepcion_mercaderia_id', 'articulo_id', 'articulo_sku_snapshot',
                'articulo_nombre_snapshot', 'unidad_codigo_snapshot', 'unidad_simbolo_snapshot',
                'cantidad', 'costo_unitario', 'motivo_costo_cero', 'created_at', 'updated_at',
            ],
            'movimientos_inventario' => [
                'id', 'articulo_id', 'usuario_id', 'usuario_autorizador_id', 'tipo_movimiento',
                'cantidad_delta', 'stock_anterior', 'stock_posterior', 'clave_idempotencia',
                'secuencia_idempotencia', 'ocurrido_en', 'motivo', 'articulo_sku_snapshot',
                'articulo_nombre_snapshot', 'unidad_codigo_snapshot', 'unidad_simbolo_snapshot',
                'detalle_venta_id', 'detalle_recepcion_mercaderia_id', 'detalle_produccion_id',
                'consumo_produccion_id', 'merma_id', 'detalle_conteo_inventario_id',
                'movimiento_revertido_id', 'created_at',
            ],
            'stock_diario' => [
                'id', 'articulo_id', 'fecha_comercial', 'stock_apertura', 'total_entradas',
                'total_salidas', 'stock_cierre', 'calculado_en', 'ultimo_movimiento_id',
                'created_at', 'updated_at',
            ],
        ];

        foreach ($expected as $table => $columns) {
            $actual = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->orderBy('ordinal_position')
                ->pluck('column_name')
                ->all();

            $this->assertSame($columns, $actual, $table);
        }

        $this->assertTrue(Schema::hasColumn('historial_costos_articulo', 'detalle_recepcion_mercaderia_id'));
        $this->assertTrue(Schema::hasColumn('detalle_conteos_inventario', 'movimiento_inventario_observado_id'));
        $this->assertFalse(Schema::hasColumn('movimientos_inventario', 'updated_at'));
    }

    public function test_important_column_types_nullability_and_defaults_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name, data_type, udt_name,
                   character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   CASE WHEN data_type LIKE 'timestamp%' THEN datetime_precision END AS datetime_precision,
                   is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND (
                    table_name IN (
                        'recepciones_mercaderia', 'detalle_recepciones_mercaderia',
                        'movimientos_inventario', 'stock_diario'
                    )
                    OR (table_name = 'historial_costos_articulo'
                        AND column_name = 'detalle_recepcion_mercaderia_id')
                    OR (table_name = 'detalle_conteos_inventario'
                        AND column_name = 'movimiento_inventario_observado_id')
               )
             ORDER BY table_name, ordinal_position
            SQL);
        $columns = [];

        foreach ($rows as $row) {
            $columns[$row->table_name][$row->column_name] = $row;
        }

        $this->assertColumn($columns, 'recepciones_mercaderia', 'clave_idempotencia', 'uuid', false);
        $this->assertColumn($columns, 'recepciones_mercaderia', 'tipo_origen', 'character varying', false, length: 20);
        $this->assertColumn($columns, 'recepciones_mercaderia', 'estado', 'character varying', false, length: 20);
        $this->assertSame("'borrador'::character varying", $columns['recepciones_mercaderia']['estado']->column_default);

        foreach (['proveedor_id', 'usuario_aportante_id', 'usuario_confirmador_id', 'usuario_anulador_id'] as $column) {
            $this->assertColumn($columns, 'recepciones_mercaderia', $column, 'bigint', true);
        }
        $this->assertColumn($columns, 'recepciones_mercaderia', 'usuario_creador_id', 'bigint', false);

        foreach (['ocurrido_en', 'created_at', 'updated_at'] as $column) {
            $this->assertColumn($columns, 'recepciones_mercaderia', $column, 'timestamp with time zone', false, datetimePrecision: 6);
            $this->assertSame('CURRENT_TIMESTAMP', $columns['recepciones_mercaderia'][$column]->column_default);
        }

        $this->assertColumn($columns, 'detalle_recepciones_mercaderia', 'cantidad', 'numeric', true, precision: 14, scale: 3);
        $this->assertColumn($columns, 'detalle_recepciones_mercaderia', 'costo_unitario', 'numeric', true, precision: 14, scale: 2);
        $this->assertColumn($columns, 'detalle_recepciones_mercaderia', 'motivo_costo_cero', 'text', true);

        foreach (['cantidad_delta', 'stock_anterior', 'stock_posterior'] as $column) {
            $this->assertColumn($columns, 'movimientos_inventario', $column, 'numeric', false, precision: 14, scale: 3);
        }
        $this->assertColumn($columns, 'movimientos_inventario', 'tipo_movimiento', 'character varying', false, length: 30);
        $this->assertColumn($columns, 'movimientos_inventario', 'secuencia_idempotencia', 'integer', false);
        $this->assertColumn($columns, 'movimientos_inventario', 'created_at', 'timestamp with time zone', false, datetimePrecision: 6);
        $this->assertSame('CURRENT_TIMESTAMP', $columns['movimientos_inventario']['created_at']->column_default);

        foreach (['stock_apertura', 'total_entradas', 'total_salidas', 'stock_cierre'] as $column) {
            $this->assertColumn($columns, 'stock_diario', $column, 'numeric', false, precision: 14, scale: 3);
            $this->assertNull($columns['stock_diario'][$column]->column_default);
        }
        $this->assertColumn($columns, 'stock_diario', 'fecha_comercial', 'date', false);
        $this->assertColumn($columns, 'stock_diario', 'calculado_en', 'timestamp with time zone', false, datetimePrecision: 6);
        $this->assertSame('CURRENT_TIMESTAMP', $columns['stock_diario']['calculado_en']->column_default);
        $this->assertColumn($columns, 'stock_diario', 'ultimo_movimiento_id', 'bigint', true);
        $this->assertColumn($columns, 'historial_costos_articulo', 'detalle_recepcion_mercaderia_id', 'bigint', true);
        $this->assertColumn($columns, 'detalle_conteos_inventario', 'movimiento_inventario_observado_id', 'bigint', true);
    }

    public function test_named_check_constraints_match_exactly(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT con.conname AS constraint_name
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'c'
               AND (
                    tbl.relname IN (
                        'recepciones_mercaderia', 'detalle_recepciones_mercaderia',
                        'movimientos_inventario', 'stock_diario'
                    )
                    OR con.conname = 'detalle_conteos_mov_inv_observado_consistente_check'
               )
             ORDER BY con.conname
            SQL);
        $actual = array_map(static fn (object $row): string => $row->constraint_name, $rows);
        $expected = $this->expectedCheckNames();
        sort($expected);

        $this->assertSame($expected, $actual);
        $this->assertCount(37, $actual);
    }

    public function test_foreign_keys_match_approved_definitions(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, con.conname AS constraint_name,
                   ref.relname AS target_table, con.confupdtype AS update_action,
                   con.confdeltype AS delete_action, con.convalidated AS validated,
                   src.attname AS source_column, dst.attname AS target_column
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
              JOIN pg_catalog.pg_class AS ref ON ref.oid = con.confrelid
              JOIN pg_catalog.pg_attribute AS src
                ON src.attrelid = tbl.oid AND src.attnum = con.conkey[1]
              JOIN pg_catalog.pg_attribute AS dst
                ON dst.attrelid = ref.oid AND dst.attnum = con.confkey[1]
             WHERE nsp.nspname = 'public' AND con.contype = 'f'
               AND (
                    tbl.relname IN (
                        'recepciones_mercaderia', 'detalle_recepciones_mercaderia',
                        'movimientos_inventario', 'stock_diario'
                    )
                    OR con.conname IN (
                        'hist_costos_detalle_recepcion_id_foreign',
                        'detalle_conteos_mov_inv_observado_id_foreign'
                    )
               )
             ORDER BY con.conname
            SQL);
        $actual = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated));
            $this->assertSame('a', $row->update_action);
            $this->assertSame('r', $row->delete_action);
            $actual[$row->constraint_name] = [
                $row->table_name,
                $row->source_column,
                $row->target_table,
                $row->target_column,
            ];
        }
        $expected = $this->expectedForeignKeys();
        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual);
        $this->assertCount(21, $actual);
    }

    public function test_unique_constraints_and_partial_unique_indexes_match_design(): void
    {
        $constraints = DB::select(<<<'SQL'
            SELECT con.conname AS name
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'u'
               AND (
                    tbl.relname IN (
                        'recepciones_mercaderia', 'detalle_recepciones_mercaderia',
                        'movimientos_inventario', 'stock_diario'
                    )
                    OR con.conname = 'hist_costos_detalle_recepcion_id_unique'
               )
             ORDER BY con.conname
            SQL);
        $partialIndexes = DB::select(<<<'SQL'
            SELECT idx.relname AS name
              FROM pg_catalog.pg_index AS ind
              JOIN pg_catalog.pg_class AS idx ON idx.oid = ind.indexrelid
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = ind.indrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname = 'movimientos_inventario'
               AND ind.indisunique AND NOT ind.indisprimary AND ind.indpred IS NOT NULL
             ORDER BY idx.relname
            SQL);
        $constraintNames = array_map(static fn (object $row): string => $row->name, $constraints);
        $partialNames = array_map(static fn (object $row): string => $row->name, $partialIndexes);
        $expectedConstraints = [
            'detalle_recepciones_mercaderia_recepcion_articulo_unique',
            'hist_costos_detalle_recepcion_id_unique',
            'mov_inv_idempotencia_secuencia_unique',
            'mov_inv_movimiento_revertido_id_unique',
            'recepciones_mercaderia_clave_idempotencia_unique',
            'stock_diario_articulo_fecha_unique',
        ];
        $expectedPartial = [
            'mov_inv_articulo_saldo_inicial_unique',
            'mov_inv_consumo_produccion_original_unique',
            'mov_inv_detalle_conteo_ajuste_unique',
            'mov_inv_detalle_produccion_original_unique',
            'mov_inv_detalle_recepcion_original_unique',
            'mov_inv_detalle_venta_original_unique',
            'mov_inv_merma_original_unique',
        ];

        $this->assertSame($expectedConstraints, $constraintNames);
        $this->assertSame($expectedPartial, $partialNames);
        $this->assertSame(13, count($constraintNames) + count($partialNames));
    }

    public function test_all_block_eleven_indexes_match_approved_names_and_semantics(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, idx.relname AS index_name,
                   ind.indisunique AS is_unique, ind.indisprimary AS is_primary,
                   ind.indisvalid AS is_valid, ind.indnullsnotdistinct AS nulls_not_distinct,
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
             WHERE nsp.nspname = 'public'
               AND (
                    tbl.relname IN (
                        'recepciones_mercaderia', 'detalle_recepciones_mercaderia',
                        'movimientos_inventario', 'stock_diario'
                    )
                    OR idx.relname IN (
                        'hist_costos_detalle_recepcion_id_unique',
                        'detalle_conteos_mov_inv_observado_id_index'
                    )
               )
             ORDER BY idx.relname
            SQL);
        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->index_name] = [
                'table' => $row->table_name,
                'columns' => json_decode($row->key_columns, true, flags: JSON_THROW_ON_ERROR),
                'unique' => $this->postgresBoolean($row->is_unique),
                'primary' => $this->postgresBoolean($row->is_primary),
                'valid' => $this->postgresBoolean($row->is_valid),
                'nullsNotDistinct' => $this->postgresBoolean($row->nulls_not_distinct),
                'predicate' => $row->predicate,
            ];
        }
        $expected = $this->expectedIndexes();
        $this->assertSame(array_keys($expected), array_keys($actual));

        foreach ($expected as $name => $definition) {
            $index = $actual[$name];
            $this->assertSame($definition['table'], $index['table'], $name);
            $this->assertSame($definition['columns'], $index['columns'], $name);
            $this->assertSame($definition['unique'], $index['unique'], $name);
            $this->assertSame($definition['primary'], $index['primary'], $name);
            $this->assertTrue($index['valid'], $name);
            $this->assertFalse($index['nullsNotDistinct'], $name);

            if ($definition['predicateFragments'] === []) {
                $this->assertNull($index['predicate'], $name);
            } else {
                $this->assertNotNull($index['predicate'], $name);
                $predicate = strtolower($index['predicate']);

                foreach ($definition['predicateFragments'] as $fragment) {
                    $this->assertStringContainsString($fragment, $predicate, $name);
                }
            }
        }

        $this->assertCount(33, $actual);
        $this->assertCount(16, array_filter(
            $actual,
            static fn (array $index): bool => ! $index['primary'] && ! $index['unique'],
        ));
    }

    public function test_block_eleven_has_no_unapproved_database_objects(): void
    {
        $tableList = "'recepciones_mercaderia','detalle_recepciones_mercaderia','movimientos_inventario','stock_diario'";
        $triggers = DB::select("SELECT 1 FROM pg_catalog.pg_trigger trg JOIN pg_catalog.pg_class tbl ON tbl.oid = trg.tgrelid JOIN pg_catalog.pg_namespace nsp ON nsp.oid = tbl.relnamespace WHERE nsp.nspname = 'public' AND tbl.relname IN ({$tableList}) AND NOT trg.tgisinternal");
        $rules = DB::select("SELECT 1 FROM pg_catalog.pg_rules WHERE schemaname = 'public' AND tablename IN ({$tableList})");
        $policies = DB::select("SELECT 1 FROM pg_catalog.pg_policies WHERE schemaname = 'public' AND tablename IN ({$tableList})");
        $generated = DB::select("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name IN ({$tableList}) AND is_generated <> 'NEVER'");

        $this->assertSame([], $triggers);
        $this->assertSame([], $rules);
        $this->assertSame([], $policies);
        $this->assertSame([], $generated);
    }

    private function assertColumn(
        array $columns,
        string $table,
        string $column,
        string $dataType,
        bool $nullable,
        ?int $length = null,
        ?int $precision = null,
        ?int $scale = null,
        ?int $datetimePrecision = null,
    ): void {
        $this->assertArrayHasKey($table, $columns);
        $this->assertArrayHasKey($column, $columns[$table]);
        $metadata = $columns[$table][$column];
        $this->assertSame($dataType, $metadata->data_type, "{$table}.{$column}");
        $this->assertSame($nullable ? 'YES' : 'NO', $metadata->is_nullable, "{$table}.{$column}");
        $this->assertSame($length, $metadata->character_maximum_length === null ? null : (int) $metadata->character_maximum_length, "{$table}.{$column}");
        $this->assertSame($precision, $metadata->numeric_precision === null ? null : (int) $metadata->numeric_precision, "{$table}.{$column}");
        $this->assertSame($scale, $metadata->numeric_scale === null ? null : (int) $metadata->numeric_scale, "{$table}.{$column}");
        $this->assertSame($datetimePrecision, $metadata->datetime_precision === null ? null : (int) $metadata->datetime_precision, "{$table}.{$column}");
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function expectedCheckNames(): array
    {
        return [
            'recepciones_mercaderia_tipo_origen_valido_check',
            'recepciones_mercaderia_origen_consistente_check',
            'recepciones_mercaderia_estado_valido_check',
            'recepciones_mercaderia_ciclo_vida_consistente_check',
            'recepciones_mercaderia_ocurrido_en_consistente_check',
            'recepciones_mercaderia_fechas_validas_check',
            'recepciones_mercaderia_motivo_anulacion_valido_check',
            'recepciones_mercaderia_observacion_valida_check',
            'detalle_recepciones_mercaderia_sku_snapshot_check',
            'detalle_recepciones_mercaderia_nombre_snapshot_check',
            'detalle_recepciones_mercaderia_unidad_codigo_check',
            'detalle_recepciones_mercaderia_unidad_simbolo_check',
            'detalle_recepciones_mercaderia_cantidad_valida_check',
            'detalle_recepciones_mercaderia_costo_valido_check',
            'detalle_recepciones_mercaderia_motivo_costo_cero_check',
            'mov_inv_sku_snapshot_valido_check',
            'mov_inv_nombre_snapshot_valido_check',
            'mov_inv_unidad_codigo_snapshot_valido_check',
            'mov_inv_unidad_simbolo_snapshot_valido_check',
            'mov_inv_tipo_valido_check',
            'mov_inv_delta_valido_check',
            'mov_inv_signo_consistente_check',
            'mov_inv_stock_anterior_valido_check',
            'mov_inv_stock_posterior_valido_check',
            'mov_inv_stock_consistente_check',
            'mov_inv_secuencia_idempotencia_valida_check',
            'mov_inv_ocurrido_en_valido_check',
            'mov_inv_motivo_consistente_check',
            'mov_inv_origen_causal_consistente_check',
            'mov_inv_reverso_distinto_check',
            'mov_inv_saldo_inicial_consistente_check',
            'detalle_conteos_mov_inv_observado_consistente_check',
            'stock_diario_apertura_valida_check',
            'stock_diario_entradas_validas_check',
            'stock_diario_salidas_validas_check',
            'stock_diario_cierre_valido_check',
            'stock_diario_cierre_consistente_check',
        ];
    }

    private function expectedForeignKeys(): array
    {
        return [
            'recepciones_mercaderia_proveedor_id_foreign' => ['recepciones_mercaderia', 'proveedor_id', 'proveedores', 'id'],
            'recepciones_mercaderia_usuario_aportante_id_foreign' => ['recepciones_mercaderia', 'usuario_aportante_id', 'usuarios', 'id'],
            'recepciones_mercaderia_usuario_creador_id_foreign' => ['recepciones_mercaderia', 'usuario_creador_id', 'usuarios', 'id'],
            'recepciones_mercaderia_usuario_confirmador_id_foreign' => ['recepciones_mercaderia', 'usuario_confirmador_id', 'usuarios', 'id'],
            'recepciones_mercaderia_usuario_anulador_id_foreign' => ['recepciones_mercaderia', 'usuario_anulador_id', 'usuarios', 'id'],
            'detalle_recepciones_mercaderia_recepcion_id_foreign' => ['detalle_recepciones_mercaderia', 'recepcion_mercaderia_id', 'recepciones_mercaderia', 'id'],
            'detalle_recepciones_mercaderia_articulo_id_foreign' => ['detalle_recepciones_mercaderia', 'articulo_id', 'articulos', 'id'],
            'hist_costos_detalle_recepcion_id_foreign' => ['historial_costos_articulo', 'detalle_recepcion_mercaderia_id', 'detalle_recepciones_mercaderia', 'id'],
            'mov_inv_articulo_id_foreign' => ['movimientos_inventario', 'articulo_id', 'articulos', 'id'],
            'mov_inv_usuario_id_foreign' => ['movimientos_inventario', 'usuario_id', 'usuarios', 'id'],
            'mov_inv_usuario_autorizador_id_foreign' => ['movimientos_inventario', 'usuario_autorizador_id', 'usuarios', 'id'],
            'mov_inv_detalle_venta_id_foreign' => ['movimientos_inventario', 'detalle_venta_id', 'detalle_ventas', 'id'],
            'mov_inv_detalle_recepcion_id_foreign' => ['movimientos_inventario', 'detalle_recepcion_mercaderia_id', 'detalle_recepciones_mercaderia', 'id'],
            'mov_inv_detalle_produccion_id_foreign' => ['movimientos_inventario', 'detalle_produccion_id', 'detalle_producciones', 'id'],
            'mov_inv_consumo_produccion_id_foreign' => ['movimientos_inventario', 'consumo_produccion_id', 'consumos_produccion', 'id'],
            'mov_inv_merma_id_foreign' => ['movimientos_inventario', 'merma_id', 'mermas', 'id'],
            'mov_inv_detalle_conteo_id_foreign' => ['movimientos_inventario', 'detalle_conteo_inventario_id', 'detalle_conteos_inventario', 'id'],
            'mov_inv_movimiento_revertido_id_foreign' => ['movimientos_inventario', 'movimiento_revertido_id', 'movimientos_inventario', 'id'],
            'detalle_conteos_mov_inv_observado_id_foreign' => ['detalle_conteos_inventario', 'movimiento_inventario_observado_id', 'movimientos_inventario', 'id'],
            'stock_diario_articulo_id_foreign' => ['stock_diario', 'articulo_id', 'articulos', 'id'],
            'stock_diario_ultimo_movimiento_id_foreign' => ['stock_diario', 'ultimo_movimiento_id', 'movimientos_inventario', 'id'],
        ];
    }

    private function expectedIndexes(): array
    {
        $indexes = [
            'detalle_conteos_mov_inv_observado_id_index' => $this->index('detalle_conteos_inventario', ['movimiento_inventario_observado_id'], fragments: ['is not null']),
            'detalle_recepciones_mercaderia_articulo_recepcion_id_index' => $this->index('detalle_recepciones_mercaderia', ['articulo_id', 'recepcion_mercaderia_id', 'id']),
            'detalle_recepciones_mercaderia_pkey' => $this->index('detalle_recepciones_mercaderia', ['id'], true, true),
            'detalle_recepciones_mercaderia_recepcion_articulo_unique' => $this->index('detalle_recepciones_mercaderia', ['recepcion_mercaderia_id', 'articulo_id'], true),
            'hist_costos_detalle_recepcion_id_unique' => $this->index('historial_costos_articulo', ['detalle_recepcion_mercaderia_id'], true),
            'mov_inv_articulo_id_id_index' => $this->index('movimientos_inventario', ['articulo_id', 'id']),
            'mov_inv_articulo_ocurrido_en_id_index' => $this->index('movimientos_inventario', ['articulo_id', 'ocurrido_en', 'id']),
            'mov_inv_articulo_saldo_inicial_unique' => $this->index('movimientos_inventario', ['articulo_id'], true, fragments: ['saldo_inicial']),
            'mov_inv_autorizador_created_at_id_index' => $this->index('movimientos_inventario', ['usuario_autorizador_id', 'created_at', 'id'], fragments: ['is not null']),
            'mov_inv_consumo_produccion_original_unique' => $this->index('movimientos_inventario', ['consumo_produccion_id'], true, fragments: ['produccion_consumo']),
            'mov_inv_detalle_conteo_ajuste_unique' => $this->index('movimientos_inventario', ['detalle_conteo_inventario_id'], true, fragments: ['ajuste_conteo_positivo', 'ajuste_conteo_negativo']),
            'mov_inv_detalle_produccion_original_unique' => $this->index('movimientos_inventario', ['detalle_produccion_id'], true, fragments: ['produccion_entrada']),
            'mov_inv_detalle_recepcion_original_unique' => $this->index('movimientos_inventario', ['detalle_recepcion_mercaderia_id'], true, fragments: ['recepcion']),
            'mov_inv_detalle_venta_original_unique' => $this->index('movimientos_inventario', ['detalle_venta_id'], true, fragments: ["'venta'"]),
            'mov_inv_idempotencia_secuencia_unique' => $this->index('movimientos_inventario', ['clave_idempotencia', 'secuencia_idempotencia'], true),
            'mov_inv_merma_original_unique' => $this->index('movimientos_inventario', ['merma_id'], true, fragments: ["'merma'"]),
            'mov_inv_movimiento_revertido_id_unique' => $this->index('movimientos_inventario', ['movimiento_revertido_id'], true),
            'mov_inv_tipo_ocurrido_en_id_index' => $this->index('movimientos_inventario', ['tipo_movimiento', 'ocurrido_en', 'id']),
            'mov_inv_usuario_created_at_id_index' => $this->index('movimientos_inventario', ['usuario_id', 'created_at', 'id']),
            'movimientos_inventario_pkey' => $this->index('movimientos_inventario', ['id'], true, true),
            'recepciones_mercaderia_anulada_en_id_index' => $this->index('recepciones_mercaderia', ['anulada_en', 'id'], fragments: ['is not null']),
            'recepciones_mercaderia_aportante_ocurrido_id_index' => $this->index('recepciones_mercaderia', ['usuario_aportante_id', 'ocurrido_en', 'id'], fragments: ['is not null']),
            'recepciones_mercaderia_clave_idempotencia_unique' => $this->index('recepciones_mercaderia', ['clave_idempotencia'], true),
            'recepciones_mercaderia_confirmada_en_id_index' => $this->index('recepciones_mercaderia', ['confirmada_en', 'id'], fragments: ['is not null']),
            'recepciones_mercaderia_creador_created_at_id_index' => $this->index('recepciones_mercaderia', ['usuario_creador_id', 'created_at', 'id']),
            'recepciones_mercaderia_estado_created_at_id_index' => $this->index('recepciones_mercaderia', ['estado', 'created_at', 'id']),
            'recepciones_mercaderia_ocurrido_en_id_index' => $this->index('recepciones_mercaderia', ['ocurrido_en', 'id']),
            'recepciones_mercaderia_pkey' => $this->index('recepciones_mercaderia', ['id'], true, true),
            'recepciones_mercaderia_proveedor_ocurrido_id_index' => $this->index('recepciones_mercaderia', ['proveedor_id', 'ocurrido_en', 'id'], fragments: ['is not null']),
            'stock_diario_articulo_fecha_unique' => $this->index('stock_diario', ['articulo_id', 'fecha_comercial'], true),
            'stock_diario_fecha_articulo_index' => $this->index('stock_diario', ['fecha_comercial', 'articulo_id']),
            'stock_diario_pkey' => $this->index('stock_diario', ['id'], true, true),
            'stock_diario_ultimo_movimiento_id_index' => $this->index('stock_diario', ['ultimo_movimiento_id'], fragments: ['is not null']),
        ];
        ksort($indexes);

        return $indexes;
    }

    private function index(
        string $table,
        array $columns,
        bool $unique = false,
        bool $primary = false,
        array $fragments = [],
    ): array {
        return [
            'table' => $table,
            'columns' => $columns,
            'unique' => $unique,
            'primary' => $primary,
            'predicateFragments' => $fragments,
        ];
    }
}
