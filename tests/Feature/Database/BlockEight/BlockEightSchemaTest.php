<?php

namespace Tests\Feature\Database\BlockEight;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockEightSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'correlativos_venta_diarios',
        'ventas',
        'detalle_ventas',
        'pagos_venta',
    ];

    public function test_public_schema_contains_exactly_the_approved_tables(): void
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->whereIn('table_name', self::TABLES)
            ->orderBy('table_name')
            ->pluck('table_name')
            ->all();

        $expectedTables = self::TABLES;
        sort($expectedTables);

        $this->assertCount(count(self::TABLES), $tables);
        $this->assertSame($expectedTables, $tables);

        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
    }

    public function test_block_eight_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, ordinal_position, column_name, udt_name, character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY table_name, ordinal_position
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->table_name][$row->column_name] = [
                'position' => (int) $row->ordinal_position,
                'type' => $row->udt_name,
                'length' => $row->character_maximum_length === null ? null : (int) $row->character_maximum_length,
                'numericPrecision' => $row->numeric_precision === null ? null : (int) $row->numeric_precision,
                'scale' => $row->numeric_scale === null ? null : (int) $row->numeric_scale,
                'datetimePrecision' => $row->datetime_precision === null ? null : (int) $row->datetime_precision,
                'nullable' => $row->is_nullable === 'YES',
                'default' => $row->column_default,
            ];
        }

        foreach ($this->expectedColumns() as $table => $columns) {
            $this->assertArrayHasKey($table, $actual);
            $this->assertSame(array_keys($columns), array_keys($actual[$table]));

            foreach (array_values(array_keys($columns)) as $offset => $column) {
                $expected = $columns[$column];
                $metadata = $actual[$table][$column];

                $this->assertSame($offset + 1, $metadata['position'], "Unexpected position for {$table}.{$column}.");
                unset($metadata['position']);
                $this->assertColumnDefault($expected['default'], $metadata['default'], "{$table}.{$column}");
                unset($expected['default'], $metadata['default']);

                $this->assertSame($expected, $metadata, "Unexpected column metadata for {$table}.{$column}.");
            }
        }

        $this->assertCount(4, $actual['correlativos_venta_diarios']);
        $this->assertCount(18, $actual['ventas']);
        $this->assertCount(12, $actual['detalle_ventas']);
        $this->assertCount(13, $actual['pagos_venta']);
        $this->assertCount(47, $rows);

        foreach (['id', 'folio', 'deleted_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('correlativos_venta_diarios', $column));
        }

        foreach (['venta_id', 'folio', 'deleted_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('ventas', $column));
        }

        foreach (['updated_at', 'deleted_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('detalle_ventas', $column));
            $this->assertFalse(Schema::hasColumn('pagos_venta', $column));
        }
    }

    public function test_primary_keys_and_owned_sequences_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, con.conname AS constraint_name,
                   con.convalidated AS validated,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(con.conkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = tbl.oid AND att.attnum = keys.attnum
                   )::text AS columns
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'p'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY tbl.relname
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated));
            $actual[$row->table_name] = [
                'name' => $row->constraint_name,
                'columns' => json_decode($row->columns, true, flags: JSON_THROW_ON_ERROR),
            ];
        }

        $this->assertSame([
            'correlativos_venta_diarios' => [
                'name' => 'correlativos_venta_diarios_pkey',
                'columns' => ['fecha_comercial'],
            ],
            'detalle_ventas' => ['name' => 'detalle_ventas_pkey', 'columns' => ['id']],
            'pagos_venta' => ['name' => 'pagos_venta_pkey', 'columns' => ['id']],
            'ventas' => ['name' => 'ventas_pkey', 'columns' => ['id']],
        ], $actual);

        foreach (['detalle_ventas', 'pagos_venta', 'ventas'] as $table) {
            $sequence = DB::selectOne(<<<'SQL'
                SELECT seq.relname AS sequence_name, seq.relkind AS relation_kind,
                       seq_nsp.nspname AS sequence_schema,
                       format_type(att.atttypid, att.atttypmod) AS id_type,
                       pg_get_expr(def.adbin, def.adrelid) AS id_default,
                       EXISTS (
                           SELECT 1 FROM pg_catalog.pg_depend AS dep
                            WHERE dep.classid = 'pg_catalog.pg_attrdef'::regclass
                              AND dep.objid = def.oid
                              AND dep.refclassid = 'pg_catalog.pg_class'::regclass
                              AND dep.refobjid = seq.oid
                       ) AS default_uses_owned_sequence
                  FROM pg_catalog.pg_class AS tbl
                  JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
                  JOIN pg_catalog.pg_attribute AS att ON att.attrelid = tbl.oid AND att.attname = 'id'
                  JOIN pg_catalog.pg_attrdef AS def ON def.adrelid = tbl.oid AND def.adnum = att.attnum
                  JOIN pg_catalog.pg_class AS seq
                    ON seq.oid = pg_get_serial_sequence(format('%I.%I', nsp.nspname, tbl.relname), 'id')::regclass
                  JOIN pg_catalog.pg_namespace AS seq_nsp ON seq_nsp.oid = seq.relnamespace
                 WHERE nsp.nspname = 'public' AND tbl.relname = ?
                SQL, [$table]);

            $this->assertNotNull($sequence, "Missing owned sequence for {$table}.id.");
            $this->assertSame($table.'_id_seq', $sequence->sequence_name);
            $this->assertSame('public', $sequence->sequence_schema);
            $this->assertSame('S', $sequence->relation_kind);
            $this->assertSame('bigint', $sequence->id_type);
            $this->assertTrue($this->postgresBoolean($sequence->default_uses_owned_sequence));
            $this->assertMatchesRegularExpression(
                "/^nextval\('(?:public\.)?{$table}_id_seq'::regclass\)$/",
                $sequence->id_default,
            );
        }
    }

    public function test_named_check_constraints_match_exactly(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, con.conname AS constraint_name,
                   con.convalidated AS validated, pg_get_constraintdef(con.oid, true) AS definition
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'c'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY tbl.relname, con.conname
            SQL);

        $actual = array_fill_keys(self::TABLES, []);
        $definitions = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated), "Unvalidated CHECK {$row->constraint_name}.");
            $actual[$row->table_name][] = $row->constraint_name;
            $definitions[$row->constraint_name] = $row->definition;
        }

        $expected = $this->expectedCheckNames();

        foreach ($expected as $table => $names) {
            sort($names);
            sort($actual[$table]);
            $this->assertSame($names, $actual[$table], "Unexpected CHECK constraints for {$table}.");
        }

        $this->assertCount(25, $definitions);

        foreach ($this->expectedCheckFragments() as $constraint => $fragments) {
            $this->assertArrayHasKey($constraint, $definitions);
            $definition = $this->normalizeConstraintDefinition($definitions[$constraint]);

            foreach ($fragments as $fragment) {
                $this->assertStringContainsString(
                    $this->normalizeConstraintDefinition($fragment),
                    $definition,
                    $constraint,
                );
            }
        }

        $this->assertTrue($this->postgresBoolean(DB::scalar(<<<'SQL'
            SELECT 0::numeric >= 0::numeric AND 0::numeric <> 'NaN'::numeric
            SQL)));
    }

    public function test_unique_constraints_match_approved_definitions(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, con.conname AS constraint_name,
                   con.convalidated AS validated,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(con.conkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = tbl.oid AND att.attnum = keys.attnum
                   )::text AS columns
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'u'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY con.conname
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated));
            $actual[$row->constraint_name] = [
                'table' => $row->table_name,
                'columns' => json_decode($row->columns, true, flags: JSON_THROW_ON_ERROR),
            ];
        }

        $this->assertSame([
            'pagos_venta_clave_idempotencia_unique' => [
                'table' => 'pagos_venta', 'columns' => ['clave_idempotencia'],
            ],
            'ventas_clave_idempotencia_unique' => [
                'table' => 'ventas', 'columns' => ['clave_idempotencia'],
            ],
            'ventas_fecha_comercial_numero_diario_unique' => [
                'table' => 'ventas', 'columns' => ['fecha_comercial', 'numero_diario'],
            ],
            'ventas_pedido_id_unique' => [
                'table' => 'ventas', 'columns' => ['pedido_id'],
            ],
        ], $actual);
    }

    public function test_indexes_match_approved_semantics(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, idx.relname AS index_name,
                   ind.indisunique AS is_unique, ind.indisprimary AS is_primary,
                   ind.indisvalid AS is_valid, ind.indnkeyatts AS key_count, ind.indnatts AS attribute_count,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(ind.indkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         LEFT JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = tbl.oid AND att.attnum = keys.attnum
                        WHERE keys.ordinality <= ind.indnkeyatts
                   )::text AS key_columns,
                   pg_get_expr(ind.indexprs, ind.indrelid) AS expression,
                   pg_get_expr(ind.indpred, ind.indrelid) AS predicate
              FROM pg_catalog.pg_index AS ind
              JOIN pg_catalog.pg_class AS idx ON idx.oid = ind.indexrelid
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = ind.indrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY idx.relname
            SQL);

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[$row->index_name] = [
                'table' => $row->table_name,
                'unique' => $this->postgresBoolean($row->is_unique),
                'primary' => $this->postgresBoolean($row->is_primary),
                'valid' => $this->postgresBoolean($row->is_valid),
                'keyCount' => (int) $row->key_count,
                'attributeCount' => (int) $row->attribute_count,
                'columns' => json_decode($row->key_columns, true, flags: JSON_THROW_ON_ERROR),
                'expression' => $row->expression,
                'predicate' => $row->predicate,
            ];
        }

        $this->assertIndex($indexes, 'correlativos_venta_diarios_pkey', 'correlativos_venta_diarios', ['fecha_comercial'], unique: true, primary: true);

        $this->assertIndex($indexes, 'ventas_pkey', 'ventas', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'ventas_fecha_comercial_numero_diario_unique', 'ventas', ['fecha_comercial', 'numero_diario'], unique: true);
        $this->assertIndex($indexes, 'ventas_clave_idempotencia_unique', 'ventas', ['clave_idempotencia'], unique: true);
        $this->assertIndex($indexes, 'ventas_pedido_id_unique', 'ventas', ['pedido_id'], unique: true);
        $this->assertIndex($indexes, 'ventas_completada_en_id_index', 'ventas', ['completada_en', 'id']);
        $this->assertIndex($indexes, 'ventas_usuario_id_completada_en_id_index', 'ventas', ['usuario_id', 'completada_en', 'id']);
        $this->assertIndex($indexes, 'ventas_sesion_caja_id_completada_en_id_index', 'ventas', ['sesion_caja_id', 'completada_en', 'id']);
        $this->assertIndex(
            $indexes,
            'ventas_cliente_id_completada_en_id_index',
            'ventas',
            ['cliente_id', 'completada_en', 'id'],
            predicate: 'cliente_idisnotnull',
        );
        $this->assertIndex(
            $indexes,
            'ventas_usuario_anulador_id_index',
            'ventas',
            ['usuario_anulador_id'],
            predicate: 'usuario_anulador_idisnotnull',
        );

        $this->assertIndex($indexes, 'detalle_ventas_pkey', 'detalle_ventas', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'detalle_ventas_venta_id_id_index', 'detalle_ventas', ['venta_id', 'id']);
        $this->assertIndex($indexes, 'detalle_ventas_articulo_id_venta_id_id_index', 'detalle_ventas', ['articulo_id', 'venta_id', 'id']);

        $this->assertIndex($indexes, 'pagos_venta_pkey', 'pagos_venta', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'pagos_venta_clave_idempotencia_unique', 'pagos_venta', ['clave_idempotencia'], unique: true);
        $this->assertIndex($indexes, 'pagos_venta_venta_id_ocurrido_en_id_index', 'pagos_venta', ['venta_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'pagos_venta_sesion_caja_id_ocurrido_en_id_index', 'pagos_venta', ['sesion_caja_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'pagos_venta_metodo_pago_id_ocurrido_en_id_index', 'pagos_venta', ['metodo_pago_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'pagos_venta_usuario_id_ocurrido_en_id_index', 'pagos_venta', ['usuario_id', 'ocurrido_en', 'id']);

        $this->assertCount(19, $indexes);
        $this->assertCount(11, array_filter(
            $indexes,
            static fn (array $index): bool => ! $index['primary'] && ! $index['unique'],
        ));
    }

    public function test_foreign_keys_match_approved_definitions(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, con.conname AS constraint_name,
                   ref_nsp.nspname AS target_schema, ref.relname AS target_table,
                   con.confupdtype AS update_action, con.confdeltype AS delete_action,
                   con.convalidated AS validated,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(con.conkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = tbl.oid AND att.attnum = keys.attnum
                   )::text AS source_columns,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(con.confkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = ref.oid AND att.attnum = keys.attnum
                   )::text AS target_columns
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
              JOIN pg_catalog.pg_class AS ref ON ref.oid = con.confrelid
              JOIN pg_catalog.pg_namespace AS ref_nsp ON ref_nsp.oid = ref.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'f'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY con.conname
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated));
            $this->assertSame('public', $row->target_schema);
            $this->assertSame('a', $row->update_action, 'Expected ON UPDATE NO ACTION.');
            $this->assertSame('r', $row->delete_action, 'Expected ON DELETE RESTRICT.');
            $actual[$row->constraint_name] = [
                'table' => $row->table_name,
                'columns' => json_decode($row->source_columns, true, flags: JSON_THROW_ON_ERROR),
                'target' => $row->target_table,
                'targetColumns' => json_decode($row->target_columns, true, flags: JSON_THROW_ON_ERROR),
            ];
        }

        $this->assertSame($this->expectedForeignKeys(), $actual);
        $this->assertCount(11, $actual);
    }

    public function test_block_eight_tables_have_no_user_defined_triggers(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, trg.tgname AS trigger_name
              FROM pg_catalog.pg_trigger AS trg
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = trg.tgrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
               AND NOT trg.tgisinternal
            SQL);

        $this->assertSame([], $triggers);
    }

    public function test_block_eight_tables_have_no_user_defined_rules(): void
    {
        $rules = DB::select(<<<'SQL'
            SELECT schemaname, tablename, rulename
              FROM pg_catalog.pg_rules
             WHERE schemaname = 'public'
               AND tablename IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
            SQL);

        $this->assertSame([], $rules);
    }

    public function test_block_eight_tables_have_no_row_level_security_or_policies(): void
    {
        $policies = DB::select(<<<'SQL'
            SELECT schemaname, tablename, policyname
              FROM pg_catalog.pg_policies
             WHERE schemaname = 'public'
               AND tablename IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
            SQL);
        $tables = DB::select(<<<'SQL'
            SELECT relname AS table_name, relrowsecurity AS row_security,
                   relforcerowsecurity AS force_row_security
              FROM pg_catalog.pg_class AS tbl
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
             ORDER BY tbl.relname
            SQL);

        $this->assertSame([], $policies);
        $this->assertCount(4, $tables);

        foreach ($tables as $table) {
            $this->assertFalse($this->postgresBoolean($table->row_security), $table->table_name);
            $this->assertFalse($this->postgresBoolean($table->force_row_security), $table->table_name);
        }
    }

    public function test_block_eight_tables_have_no_generated_columns(): void
    {
        $generatedColumns = DB::select(<<<'SQL'
            SELECT table_name, column_name, is_generated
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'correlativos_venta_diarios', 'ventas', 'detalle_ventas', 'pagos_venta'
               )
               AND is_generated <> 'NEVER'
            SQL);

        $this->assertSame([], $generatedColumns);
    }

    private function assertIndex(
        array $indexes,
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        bool $primary = false,
        ?string $predicate = null,
    ): void {
        $this->assertArrayHasKey($name, $indexes);
        $index = $indexes[$name];

        $this->assertSame($table, $index['table'], $name);
        $this->assertSame($columns, $index['columns'], $name);
        $this->assertSame(count($columns), $index['keyCount'], $name);
        $this->assertSame(count($columns), $index['attributeCount'], $name);
        $this->assertSame($unique, $index['unique'], $name);
        $this->assertSame($primary, $index['primary'], $name);
        $this->assertTrue($index['valid'], $name);
        $this->assertNull($index['expression'], $name);

        if ($predicate === null) {
            $this->assertNull($index['predicate'], $name);
        } else {
            $this->assertNotNull($index['predicate'], $name);
            $actualPredicate = strtolower(preg_replace('/[\s()]+/', '', $index['predicate']));
            $this->assertSame($predicate, $actualPredicate, $name);
        }
    }

    private function assertColumnDefault(?string $expected, ?string $actual, string $label): void
    {
        if ($expected === 'sequence') {
            $this->assertMatchesRegularExpression("/^nextval\('.+'::regclass\)$/", $actual ?? '', $label);
        } elseif ($expected === 'zero') {
            $this->assertMatchesRegularExpression(
                "/^(?:0(?:\.0+)?|'0(?:\.0+)?')(?:::(?:bigint|numeric(?:\(\d+,\s*\d+\))?))?$/",
                $actual ?? '',
                $label,
            );
        } else {
            $this->assertSame($expected, $actual, "Unexpected default for {$label}.");
        }
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function normalizeConstraintDefinition(string $definition): string
    {
        $definition = strtolower($definition);
        $definition = preg_replace('/::(?:text|character varying|numeric(?:\(\d+,\s*\d+\))?)/', '', $definition);

        return preg_replace('/[\s()]+/', '', $definition);
    }

    private function expectedCheckNames(): array
    {
        return [
            'correlativos_venta_diarios' => [
                'correlativos_venta_diarios_ultimo_numero_valido_check',
            ],
            'ventas' => [
                'ventas_anulacion_consistente_check',
                'ventas_estado_valido_check',
                'ventas_fecha_comercial_consistente_check',
                'ventas_fechas_validas_check',
                'ventas_monto_anticipo_aplicado_valido_check',
                'ventas_motivo_anulacion_valido_check',
                'ventas_numero_diario_positivo_check',
                'ventas_observacion_valida_check',
                'ventas_origen_pedido_consistente_check',
                'ventas_total_valido_check',
            ],
            'detalle_ventas' => [
                'detalle_ventas_articulo_nombre_snapshot_valido_check',
                'detalle_ventas_articulo_sku_snapshot_valido_check',
                'detalle_ventas_cantidad_valida_check',
                'detalle_ventas_observacion_valida_check',
                'detalle_ventas_precio_unitario_valido_check',
                'detalle_ventas_subtotal_consistente_check',
                'detalle_ventas_unidad_codigo_snapshot_valido_check',
                'detalle_ventas_unidad_simbolo_snapshot_valido_check',
            ],
            'pagos_venta' => [
                'pagos_venta_efectivo_consistente_check',
                'pagos_venta_monto_recibido_valido_check',
                'pagos_venta_monto_valido_check',
                'pagos_venta_observacion_valida_check',
                'pagos_venta_tipo_valido_check',
                'pagos_venta_vuelto_valido_check',
            ],
        ];
    }

    private function expectedCheckFragments(): array
    {
        return [
            'correlativos_venta_diarios_ultimo_numero_valido_check' => ['ultimo_numero >= 0'],
            'ventas_numero_diario_positivo_check' => ['numero_diario > 0'],
            'ventas_fecha_comercial_consistente_check' => [
                'fecha_comercial', 'completada_en AT TIME ZONE', "'America/Santiago'", '::date',
            ],
            'ventas_estado_valido_check' => ['estado', "'completada'", "'anulada'"],
            'ventas_total_valido_check' => ['total >= 0', "total <> 'NaN'"],
            'ventas_monto_anticipo_aplicado_valido_check' => [
                'monto_anticipo_aplicado >= 0', "monto_anticipo_aplicado <> 'NaN'",
                'monto_anticipo_aplicado <= total', 'pedido_id IS NOT NULL', 'monto_anticipo_aplicado = 0',
            ],
            'ventas_origen_pedido_consistente_check' => ['pedido_id IS NULL', 'cliente_id IS NOT NULL'],
            'ventas_anulacion_consistente_check' => [
                "estado = 'completada'", 'anulada_en IS NULL', 'usuario_anulador_id IS NULL',
                'motivo_anulacion IS NULL',
                "estado = 'anulada'", 'anulada_en IS NOT NULL', 'usuario_anulador_id IS NOT NULL',
                'motivo_anulacion IS NOT NULL',
            ],
            'ventas_fechas_validas_check' => ['anulada_en IS NULL', 'anulada_en >= completada_en'],
            'ventas_motivo_anulacion_valido_check' => [
                'motivo_anulacion IS NULL', "btrim(motivo_anulacion) <> ''", 'motivo_anulacion = btrim(motivo_anulacion)',
            ],
            'ventas_observacion_valida_check' => [
                'observacion IS NULL', "btrim(observacion) <> ''", 'observacion = btrim(observacion)',
            ],
            'detalle_ventas_articulo_sku_snapshot_valido_check' => [
                "btrim(articulo_sku_snapshot) <> ''", 'articulo_sku_snapshot = btrim(articulo_sku_snapshot)',
                'articulo_sku_snapshot = lower(articulo_sku_snapshot)', "'^[a-z0-9][a-z0-9_-]*$'",
            ],
            'detalle_ventas_articulo_nombre_snapshot_valido_check' => [
                "btrim(articulo_nombre_snapshot) <> ''", 'articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)',
            ],
            'detalle_ventas_unidad_codigo_snapshot_valido_check' => [
                "btrim(unidad_codigo_snapshot) <> ''", 'unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)',
                'unidad_codigo_snapshot = lower(unidad_codigo_snapshot)', "'^[a-z][a-z0-9_-]*$'",
            ],
            'detalle_ventas_unidad_simbolo_snapshot_valido_check' => [
                "btrim(unidad_simbolo_snapshot) <> ''", 'unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)',
            ],
            'detalle_ventas_cantidad_valida_check' => ['cantidad > 0', "cantidad <> 'NaN'"],
            'detalle_ventas_precio_unitario_valido_check' => ['precio_unitario >= 0', "precio_unitario <> 'NaN'"],
            'detalle_ventas_subtotal_consistente_check' => [
                'subtotal >= 0', "subtotal <> 'NaN'", 'subtotal = round(cantidad * precio_unitario, 2)',
            ],
            'detalle_ventas_observacion_valida_check' => [
                'observacion IS NULL', "btrim(observacion) <> ''", 'observacion = btrim(observacion)',
            ],
            'pagos_venta_tipo_valido_check' => ['tipo', "'cobro'", "'devolucion'"],
            'pagos_venta_monto_valido_check' => ['monto > 0', "monto <> 'NaN'"],
            'pagos_venta_monto_recibido_valido_check' => [
                'monto_recibido IS NULL', 'monto_recibido >= 0', "monto_recibido <> 'NaN'",
            ],
            'pagos_venta_vuelto_valido_check' => [
                'vuelto IS NULL', 'vuelto >= 0', "vuelto <> 'NaN'",
            ],
            'pagos_venta_efectivo_consistente_check' => [
                "tipo = 'devolucion'", "tipo = 'cobro'", 'monto_recibido >= monto',
                'monto_recibido IS NULL', 'vuelto IS NULL', 'monto_recibido IS NOT NULL',
                'vuelto IS NOT NULL', 'vuelto = monto_recibido - monto',
            ],
            'pagos_venta_observacion_valida_check' => [
                'observacion IS NULL', "btrim(observacion) <> ''", 'observacion = btrim(observacion)',
            ],
        ];
    }

    private function expectedForeignKeys(): array
    {
        return [
            'detalle_ventas_articulo_id_foreign' => [
                'table' => 'detalle_ventas', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'detalle_ventas_venta_id_foreign' => [
                'table' => 'detalle_ventas', 'columns' => ['venta_id'],
                'target' => 'ventas', 'targetColumns' => ['id'],
            ],
            'pagos_venta_metodo_pago_id_foreign' => [
                'table' => 'pagos_venta', 'columns' => ['metodo_pago_id'],
                'target' => 'metodos_pago', 'targetColumns' => ['id'],
            ],
            'pagos_venta_sesion_caja_id_foreign' => [
                'table' => 'pagos_venta', 'columns' => ['sesion_caja_id'],
                'target' => 'sesiones_caja', 'targetColumns' => ['id'],
            ],
            'pagos_venta_usuario_id_foreign' => [
                'table' => 'pagos_venta', 'columns' => ['usuario_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'pagos_venta_venta_id_foreign' => [
                'table' => 'pagos_venta', 'columns' => ['venta_id'],
                'target' => 'ventas', 'targetColumns' => ['id'],
            ],
            'ventas_cliente_id_foreign' => [
                'table' => 'ventas', 'columns' => ['cliente_id'],
                'target' => 'clientes', 'targetColumns' => ['id'],
            ],
            'ventas_pedido_id_foreign' => [
                'table' => 'ventas', 'columns' => ['pedido_id'],
                'target' => 'pedidos', 'targetColumns' => ['id'],
            ],
            'ventas_sesion_caja_id_foreign' => [
                'table' => 'ventas', 'columns' => ['sesion_caja_id'],
                'target' => 'sesiones_caja', 'targetColumns' => ['id'],
            ],
            'ventas_usuario_anulador_id_foreign' => [
                'table' => 'ventas', 'columns' => ['usuario_anulador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'ventas_usuario_id_foreign' => [
                'table' => 'ventas', 'columns' => ['usuario_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
        ];
    }

    private function expectedColumns(): array
    {
        return [
            'correlativos_venta_diarios' => [
                'fecha_comercial' => $this->column('date', datetimePrecision: 0),
                'ultimo_numero' => $this->column('int8', default: 'zero'),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'detalle_ventas' => [
                'id' => $this->column('int8', default: 'sequence'),
                'venta_id' => $this->column('int8'),
                'articulo_id' => $this->column('int8'),
                'articulo_sku_snapshot' => $this->column('varchar', length: 50),
                'articulo_nombre_snapshot' => $this->column('varchar', length: 150),
                'unidad_codigo_snapshot' => $this->column('varchar', length: 50),
                'unidad_simbolo_snapshot' => $this->column('varchar', length: 20),
                'cantidad' => $this->column('numeric', numericPrecision: 14, scale: 3),
                'precio_unitario' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'subtotal' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'observacion' => $this->column('text', nullable: true),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'pagos_venta' => [
                'id' => $this->column('int8', default: 'sequence'),
                'venta_id' => $this->column('int8'),
                'metodo_pago_id' => $this->column('int8'),
                'sesion_caja_id' => $this->column('int8'),
                'usuario_id' => $this->column('int8'),
                'clave_idempotencia' => $this->column('uuid'),
                'tipo' => $this->column('varchar', length: 20),
                'monto' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'monto_recibido' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'vuelto' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'ocurrido_en' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'observacion' => $this->column('text', nullable: true),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'ventas' => [
                'id' => $this->column('int8', default: 'sequence'),
                'fecha_comercial' => $this->column('date', datetimePrecision: 0),
                'numero_diario' => $this->column('int8'),
                'clave_idempotencia' => $this->column('uuid'),
                'usuario_id' => $this->column('int8'),
                'sesion_caja_id' => $this->column('int8'),
                'cliente_id' => $this->column('int8', nullable: true),
                'pedido_id' => $this->column('int8', nullable: true),
                'estado' => $this->column('varchar', length: 20, default: "'completada'::character varying"),
                'total' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'monto_anticipo_aplicado' => $this->column('numeric', numericPrecision: 14, scale: 2, default: 'zero'),
                'completada_en' => $this->column('timestamptz', datetimePrecision: 6),
                'anulada_en' => $this->column('timestamptz', datetimePrecision: 6, nullable: true),
                'usuario_anulador_id' => $this->column('int8', nullable: true),
                'motivo_anulacion' => $this->column('text', nullable: true),
                'observacion' => $this->column('text', nullable: true),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
        ];
    }

    private function column(
        string $type,
        ?int $length = null,
        ?int $numericPrecision = null,
        ?int $scale = null,
        ?int $datetimePrecision = null,
        bool $nullable = false,
        ?string $default = null,
    ): array {
        return compact('type', 'length', 'numericPrecision', 'scale', 'datetimePrecision', 'nullable', 'default');
    }
}
