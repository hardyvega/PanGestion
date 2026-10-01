<?php

namespace Tests\Feature\Database\BlockNine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockNineSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'producciones',
        'detalle_producciones',
        'consumos_produccion',
        'salidas_meson',
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

    public function test_block_nine_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, ordinal_position, column_name, data_type, udt_name,
                   character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
             ORDER BY table_name, ordinal_position
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->table_name][$row->column_name] = [
                'position' => (int) $row->ordinal_position,
                'dataType' => $row->data_type,
                'udtName' => $row->udt_name,
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

        $this->assertCount(13, $actual['producciones']);
        $this->assertCount(11, $actual['detalle_producciones']);
        $this->assertCount(12, $actual['consumos_produccion']);
        $this->assertCount(14, $actual['salidas_meson']);
        $this->assertCount(50, $rows);
    }

    public function test_block_nine_contains_no_date_columns(): void
    {
        $dateColumns = DB::select(<<<'SQL'
            SELECT table_name, column_name, data_type, udt_name
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
               AND (data_type = 'date' OR udt_name = 'date')
             ORDER BY table_name, ordinal_position
            SQL);

        $this->assertSame([], $dateColumns);
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
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
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
            'consumos_produccion' => ['name' => 'consumos_produccion_pkey', 'columns' => ['id']],
            'detalle_producciones' => ['name' => 'detalle_producciones_pkey', 'columns' => ['id']],
            'producciones' => ['name' => 'producciones_pkey', 'columns' => ['id']],
            'salidas_meson' => ['name' => 'salidas_meson_pkey', 'columns' => ['id']],
        ], $actual);

        foreach (self::TABLES as $table) {
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
                "/^nextval\\('(?:public\\.)?{$table}_id_seq'::regclass\\)$/",
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
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
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

        foreach ($this->expectedCheckNames() as $table => $names) {
            sort($names);
            sort($actual[$table]);
            $this->assertSame($names, $actual[$table], "Unexpected CHECK constraints for {$table}.");
        }

        $this->assertCount(24, $definitions);

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
    }

    public function test_unique_constraints_and_partial_unique_indexes_total_five(): void
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
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
             ORDER BY con.conname
            SQL);

        $constraints = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated));
            $constraints[$row->constraint_name] = [
                'table' => $row->table_name,
                'columns' => json_decode($row->columns, true, flags: JSON_THROW_ON_ERROR),
            ];
        }

        $this->assertSame([
            'detalle_producciones_produccion_id_unique' => [
                'table' => 'detalle_producciones', 'columns' => ['produccion_id'],
            ],
            'salidas_meson_clave_idempotencia_unique' => [
                'table' => 'salidas_meson', 'columns' => ['clave_idempotencia'],
            ],
            'salidas_meson_salida_reemplazada_id_unique' => [
                'table' => 'salidas_meson', 'columns' => ['salida_reemplazada_id'],
            ],
        ], $constraints);

        $partialUniqueIndexes = DB::select(<<<'SQL'
            SELECT idx.relname AS index_name, tbl.relname AS table_name,
                   pg_get_expr(ind.indpred, ind.indrelid) AS predicate
              FROM pg_catalog.pg_index AS ind
              JOIN pg_catalog.pg_class AS idx ON idx.oid = ind.indexrelid
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = ind.indrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
               AND ind.indisunique
               AND NOT ind.indisprimary
               AND ind.indpred IS NOT NULL
             ORDER BY idx.relname
            SQL);

        $this->assertCount(2, $partialUniqueIndexes);
        $this->assertSame(
            'consumos_produccion_detalle_receta_id_produccion_id_unique',
            $partialUniqueIndexes[0]->index_name,
        );
        $this->assertSame('detalle_receta_idisnotnull', $this->normalizePredicate($partialUniqueIndexes[0]->predicate));
        $this->assertSame(
            'consumos_produccion_produccion_articulo_extra_unique',
            $partialUniqueIndexes[1]->index_name,
        );
        $this->assertSame('detalle_receta_idisnull', $this->normalizePredicate($partialUniqueIndexes[1]->predicate));
        $this->assertSame(5, count($constraints) + count($partialUniqueIndexes));
    }

    public function test_indexes_match_approved_semantics(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, idx.relname AS index_name,
                   ind.indisunique AS is_unique, ind.indisprimary AS is_primary,
                   ind.indisvalid AS is_valid, ind.indnullsnotdistinct AS nulls_not_distinct,
                   ind.indnkeyatts AS key_count, ind.indnatts AS attribute_count,
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
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
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
                'nullsNotDistinct' => $this->postgresBoolean($row->nulls_not_distinct),
                'keyCount' => (int) $row->key_count,
                'attributeCount' => (int) $row->attribute_count,
                'columns' => json_decode($row->key_columns, true, flags: JSON_THROW_ON_ERROR),
                'expression' => $row->expression,
                'predicate' => $row->predicate,
            ];
        }

        $this->assertIndex($indexes, 'producciones_pkey', 'producciones', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'producciones_created_at_id_index', 'producciones', ['created_at', 'id']);
        $this->assertIndex($indexes, 'producciones_estado_created_at_id_index', 'producciones', ['estado', 'created_at', 'id']);
        $this->assertIndex($indexes, 'producciones_receta_id_created_at_id_index', 'producciones', ['receta_id', 'created_at', 'id']);
        $this->assertIndex($indexes, 'producciones_usuario_responsable_id_created_at_id_index', 'producciones', ['usuario_responsable_id', 'created_at', 'id']);
        $this->assertIndex(
            $indexes,
            'producciones_confirmada_en_id_index',
            'producciones',
            ['confirmada_en', 'id'],
            predicate: 'confirmada_enisnotnull',
        );

        $this->assertIndex($indexes, 'detalle_producciones_pkey', 'detalle_producciones', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'detalle_producciones_produccion_id_unique', 'detalle_producciones', ['produccion_id'], unique: true);
        $this->assertIndex($indexes, 'detalle_producciones_articulo_id_produccion_id_id_index', 'detalle_producciones', ['articulo_id', 'produccion_id', 'id']);

        $this->assertIndex($indexes, 'consumos_produccion_pkey', 'consumos_produccion', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'consumos_produccion_produccion_id_id_index', 'consumos_produccion', ['produccion_id', 'id']);
        $this->assertIndex($indexes, 'consumos_produccion_componente_produccion_id_index', 'consumos_produccion', ['articulo_componente_id', 'produccion_id', 'id']);
        $this->assertIndex(
            $indexes,
            'consumos_produccion_detalle_receta_id_produccion_id_unique',
            'consumos_produccion',
            ['detalle_receta_id', 'produccion_id'],
            unique: true,
            predicate: 'detalle_receta_idisnotnull',
        );
        $this->assertIndex(
            $indexes,
            'consumos_produccion_produccion_articulo_extra_unique',
            'consumos_produccion',
            ['produccion_id', 'articulo_componente_id'],
            unique: true,
            predicate: 'detalle_receta_idisnull',
        );

        $this->assertIndex($indexes, 'salidas_meson_pkey', 'salidas_meson', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'salidas_meson_clave_idempotencia_unique', 'salidas_meson', ['clave_idempotencia'], unique: true);
        $this->assertIndex($indexes, 'salidas_meson_salida_reemplazada_id_unique', 'salidas_meson', ['salida_reemplazada_id'], unique: true);
        $this->assertIndex($indexes, 'salidas_meson_detalle_produccion_id_ocurrido_en_id_index', 'salidas_meson', ['detalle_produccion_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'salidas_meson_ocurrido_en_id_index', 'salidas_meson', ['ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'salidas_meson_usuario_responsable_id_ocurrido_en_id_index', 'salidas_meson', ['usuario_responsable_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'salidas_meson_usuario_registrador_id_ocurrido_en_id_index', 'salidas_meson', ['usuario_registrador_id', 'ocurrido_en', 'id']);
        $this->assertIndex(
            $indexes,
            'salidas_meson_usuario_anulador_id_index',
            'salidas_meson',
            ['usuario_anulador_id'],
            predicate: 'usuario_anulador_idisnotnull',
        );

        $this->assertCount(22, $indexes);
        $this->assertCount(13, array_filter(
            $indexes,
            static fn (array $index): bool => ! $index['primary'] && ! $index['unique'],
        ));
        $this->assertFalse($indexes['salidas_meson_salida_reemplazada_id_unique']['nullsNotDistinct']);
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
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
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

        $expected = $this->expectedForeignKeys();
        ksort($actual);
        ksort($expected);

        $this->assertSame($expected, $actual);
        $this->assertCount(15, $actual);
    }

    public function test_block_nine_tables_have_no_user_defined_triggers(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, trg.tgname AS trigger_name
              FROM pg_catalog.pg_trigger AS trg
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = trg.tgrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
               AND NOT trg.tgisinternal
            SQL);

        $this->assertSame([], $triggers);
    }

    public function test_block_nine_tables_have_no_user_defined_rules(): void
    {
        $rules = DB::select(<<<'SQL'
            SELECT schemaname, tablename, rulename
              FROM pg_catalog.pg_rules
             WHERE schemaname = 'public'
               AND tablename IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
            SQL);

        $this->assertSame([], $rules);
    }

    public function test_block_nine_tables_have_no_row_level_security_or_policies(): void
    {
        $policies = DB::select(<<<'SQL'
            SELECT schemaname, tablename, policyname
              FROM pg_catalog.pg_policies
             WHERE schemaname = 'public'
               AND tablename IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
            SQL);
        $tables = DB::select(<<<'SQL'
            SELECT relname AS table_name, relrowsecurity AS row_security,
                   relforcerowsecurity AS force_row_security
              FROM pg_catalog.pg_class AS tbl
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
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

    public function test_block_nine_tables_have_no_generated_columns(): void
    {
        $generatedColumns = DB::select(<<<'SQL'
            SELECT table_name, column_name, is_generated
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'producciones', 'detalle_producciones', 'consumos_produccion', 'salidas_meson'
               )
               AND is_generated <> 'NEVER'
            SQL);

        $this->assertSame([], $generatedColumns);
    }

    public function test_block_nine_tables_exclude_unapproved_columns(): void
    {
        $forbidden = [
            'producciones' => [
                'fecha', 'folio', 'cantidad_planificada', 'cantidad_producida',
                'movimiento_inventario_id', 'deleted_at',
            ],
            'detalle_producciones' => [
                'receta_id', 'detalle_receta_id', 'observacion', 'deleted_at',
            ],
            'consumos_produccion' => [
                'stock_anterior', 'stock_nuevo', 'movimiento_inventario_id', 'observacion', 'deleted_at',
            ],
            'salidas_meson' => [
                'produccion_id', 'articulo_id', 'movimiento_inventario_id', 'updated_at', 'deleted_at',
            ],
        ];

        foreach ($forbidden as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "Unexpected column {$table}.{$column}.");
            }
        }
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
        $this->assertFalse($index['nullsNotDistinct'], $name);
        $this->assertNull($index['expression'], $name);

        if ($predicate === null) {
            $this->assertNull($index['predicate'], $name);
        } else {
            $this->assertNotNull($index['predicate'], $name);
            $this->assertSame($predicate, $this->normalizePredicate($index['predicate']), $name);
        }
    }

    private function assertColumnDefault(?string $expected, ?string $actual, string $label): void
    {
        if ($expected === 'sequence') {
            $this->assertMatchesRegularExpression("/^nextval\\('.+'::regclass\\)$/", $actual ?? '', $label);
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

    private function normalizePredicate(string $predicate): string
    {
        return strtolower(preg_replace('/[\s()]+/', '', $predicate));
    }

    private function expectedCheckNames(): array
    {
        return [
            'producciones' => [
                'producciones_estado_valido_check',
                'producciones_ciclo_vida_consistente_check',
                'producciones_fechas_validas_check',
                'producciones_motivo_anulacion_valido_check',
                'producciones_observacion_valida_check',
            ],
            'detalle_producciones' => [
                'detalle_producciones_articulo_sku_snapshot_valido_check',
                'detalle_producciones_articulo_nombre_snapshot_valido_check',
                'detalle_producciones_unidad_codigo_snapshot_valido_check',
                'detalle_producciones_unidad_simbolo_snapshot_valido_check',
                'detalle_producciones_cantidad_teorica_valida_check',
                'detalle_producciones_cantidad_real_valida_check',
            ],
            'consumos_produccion' => [
                'consumos_produccion_articulo_sku_snapshot_valido_check',
                'consumos_produccion_articulo_nombre_snapshot_valido_check',
                'consumos_produccion_unidad_codigo_snapshot_valido_check',
                'consumos_produccion_unidad_simbolo_snapshot_valido_check',
                'consumos_produccion_origen_cantidad_teorica_consistente_check',
                'consumos_produccion_cantidad_real_valida_check',
            ],
            'salidas_meson' => [
                'salidas_meson_cantidad_valida_check',
                'salidas_meson_ocurrido_en_consistente_check',
                'salidas_meson_anulacion_consistente_check',
                'salidas_meson_fechas_validas_check',
                'salidas_meson_motivo_anulacion_valido_check',
                'salidas_meson_observacion_valida_check',
                'salidas_meson_reemplazo_distinto_check',
            ],
        ];
    }

    private function expectedCheckFragments(): array
    {
        return [
            'producciones_estado_valido_check' => [
                'estado', "'borrador'", "'confirmada'", "'anulada'",
            ],
            'producciones_ciclo_vida_consistente_check' => [
                "estado = 'borrador'", 'confirmada_en IS NULL', 'usuario_confirmador_id IS NULL',
                'anulada_en IS NULL', 'usuario_anulador_id IS NULL', 'motivo_anulacion IS NULL',
                "estado = 'confirmada'", 'confirmada_en IS NOT NULL', 'usuario_confirmador_id IS NOT NULL',
                "estado = 'anulada'", 'anulada_en IS NOT NULL', 'usuario_anulador_id IS NOT NULL',
                'motivo_anulacion IS NOT NULL',
            ],
            'producciones_fechas_validas_check' => [
                'confirmada_en IS NULL', 'confirmada_en >= created_at',
                'anulada_en IS NULL', 'confirmada_en IS NOT NULL', 'anulada_en >= confirmada_en',
            ],
            'producciones_motivo_anulacion_valido_check' => [
                'motivo_anulacion IS NULL', "btrim(motivo_anulacion) <> ''",
                'motivo_anulacion = btrim(motivo_anulacion)',
            ],
            'producciones_observacion_valida_check' => [
                'observacion IS NULL', "btrim(observacion) <> ''", 'observacion = btrim(observacion)',
            ],
            'detalle_producciones_articulo_sku_snapshot_valido_check' => [
                "btrim(articulo_sku_snapshot) <> ''", 'articulo_sku_snapshot = btrim(articulo_sku_snapshot)',
                'articulo_sku_snapshot = lower(articulo_sku_snapshot)', "'^[a-z0-9][a-z0-9_-]*$'",
            ],
            'detalle_producciones_articulo_nombre_snapshot_valido_check' => [
                "btrim(articulo_nombre_snapshot) <> ''", 'articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)',
            ],
            'detalle_producciones_unidad_codigo_snapshot_valido_check' => [
                "btrim(unidad_codigo_snapshot) <> ''", 'unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)',
                'unidad_codigo_snapshot = lower(unidad_codigo_snapshot)', "'^[a-z][a-z0-9_-]*$'",
            ],
            'detalle_producciones_unidad_simbolo_snapshot_valido_check' => [
                "btrim(unidad_simbolo_snapshot) <> ''", 'unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)',
            ],
            'detalle_producciones_cantidad_teorica_valida_check' => [
                'cantidad_teorica > 0', "cantidad_teorica <> 'NaN'",
            ],
            'detalle_producciones_cantidad_real_valida_check' => [
                'cantidad_real IS NULL', 'cantidad_real >= 0', "cantidad_real <> 'NaN'",
            ],
            'consumos_produccion_articulo_sku_snapshot_valido_check' => [
                "btrim(articulo_sku_snapshot) <> ''", 'articulo_sku_snapshot = btrim(articulo_sku_snapshot)',
                'articulo_sku_snapshot = lower(articulo_sku_snapshot)', "'^[a-z0-9][a-z0-9_-]*$'",
            ],
            'consumos_produccion_articulo_nombre_snapshot_valido_check' => [
                "btrim(articulo_nombre_snapshot) <> ''", 'articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)',
            ],
            'consumos_produccion_unidad_codigo_snapshot_valido_check' => [
                "btrim(unidad_codigo_snapshot) <> ''", 'unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)',
                'unidad_codigo_snapshot = lower(unidad_codigo_snapshot)', "'^[a-z][a-z0-9_-]*$'",
            ],
            'consumos_produccion_unidad_simbolo_snapshot_valido_check' => [
                "btrim(unidad_simbolo_snapshot) <> ''", 'unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)',
            ],
            'consumos_produccion_origen_cantidad_teorica_consistente_check' => [
                'detalle_receta_id IS NOT NULL', 'cantidad_teorica IS NOT NULL', 'cantidad_teorica > 0',
                "cantidad_teorica <> 'NaN'", 'detalle_receta_id IS NULL', 'cantidad_teorica IS NULL',
            ],
            'consumos_produccion_cantidad_real_valida_check' => [
                'cantidad_real IS NULL', 'cantidad_real >= 0', "cantidad_real <> 'NaN'",
            ],
            'salidas_meson_cantidad_valida_check' => ['cantidad > 0', "cantidad <> 'NaN'"],
            'salidas_meson_ocurrido_en_consistente_check' => [
                'motivo_ajuste_ocurrido_en IS NULL', 'ocurrido_en = created_at',
                'motivo_ajuste_ocurrido_en IS NOT NULL', "btrim(motivo_ajuste_ocurrido_en) <> ''",
                'motivo_ajuste_ocurrido_en = btrim(motivo_ajuste_ocurrido_en)', 'ocurrido_en < created_at',
            ],
            'salidas_meson_anulacion_consistente_check' => [
                'anulada_en IS NULL', 'usuario_anulador_id IS NULL', 'motivo_anulacion IS NULL',
                'anulada_en IS NOT NULL', 'usuario_anulador_id IS NOT NULL', 'motivo_anulacion IS NOT NULL',
            ],
            'salidas_meson_fechas_validas_check' => ['anulada_en IS NULL', 'anulada_en >= created_at'],
            'salidas_meson_motivo_anulacion_valido_check' => [
                'motivo_anulacion IS NULL', "btrim(motivo_anulacion) <> ''",
                'motivo_anulacion = btrim(motivo_anulacion)',
            ],
            'salidas_meson_observacion_valida_check' => [
                'observacion IS NULL', "btrim(observacion) <> ''", 'observacion = btrim(observacion)',
            ],
            'salidas_meson_reemplazo_distinto_check' => [
                'salida_reemplazada_id IS NULL', 'salida_reemplazada_id <> id',
            ],
        ];
    }

    private function expectedForeignKeys(): array
    {
        return [
            'producciones_receta_id_foreign' => [
                'table' => 'producciones', 'columns' => ['receta_id'],
                'target' => 'recetas', 'targetColumns' => ['id'],
            ],
            'producciones_usuario_responsable_id_foreign' => [
                'table' => 'producciones', 'columns' => ['usuario_responsable_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'producciones_usuario_creador_id_foreign' => [
                'table' => 'producciones', 'columns' => ['usuario_creador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'producciones_usuario_confirmador_id_foreign' => [
                'table' => 'producciones', 'columns' => ['usuario_confirmador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'producciones_usuario_anulador_id_foreign' => [
                'table' => 'producciones', 'columns' => ['usuario_anulador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'detalle_producciones_produccion_id_foreign' => [
                'table' => 'detalle_producciones', 'columns' => ['produccion_id'],
                'target' => 'producciones', 'targetColumns' => ['id'],
            ],
            'detalle_producciones_articulo_id_foreign' => [
                'table' => 'detalle_producciones', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'consumos_produccion_produccion_id_foreign' => [
                'table' => 'consumos_produccion', 'columns' => ['produccion_id'],
                'target' => 'producciones', 'targetColumns' => ['id'],
            ],
            'consumos_produccion_detalle_receta_id_foreign' => [
                'table' => 'consumos_produccion', 'columns' => ['detalle_receta_id'],
                'target' => 'detalle_recetas', 'targetColumns' => ['id'],
            ],
            'consumos_produccion_articulo_componente_id_foreign' => [
                'table' => 'consumos_produccion', 'columns' => ['articulo_componente_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'salidas_meson_detalle_produccion_id_foreign' => [
                'table' => 'salidas_meson', 'columns' => ['detalle_produccion_id'],
                'target' => 'detalle_producciones', 'targetColumns' => ['id'],
            ],
            'salidas_meson_usuario_responsable_id_foreign' => [
                'table' => 'salidas_meson', 'columns' => ['usuario_responsable_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'salidas_meson_usuario_registrador_id_foreign' => [
                'table' => 'salidas_meson', 'columns' => ['usuario_registrador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'salidas_meson_usuario_anulador_id_foreign' => [
                'table' => 'salidas_meson', 'columns' => ['usuario_anulador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'salidas_meson_salida_reemplazada_id_foreign' => [
                'table' => 'salidas_meson', 'columns' => ['salida_reemplazada_id'],
                'target' => 'salidas_meson', 'targetColumns' => ['id'],
            ],
        ];
    }

    private function expectedColumns(): array
    {
        return [
            'producciones' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'receta_id' => $this->column('bigint', 'int8'),
                'usuario_responsable_id' => $this->column('bigint', 'int8'),
                'usuario_creador_id' => $this->column('bigint', 'int8'),
                'usuario_confirmador_id' => $this->column('bigint', 'int8', nullable: true),
                'usuario_anulador_id' => $this->column('bigint', 'int8', nullable: true),
                'estado' => $this->column('character varying', 'varchar', length: 20, default: "'borrador'::character varying"),
                'confirmada_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, nullable: true),
                'anulada_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, nullable: true),
                'motivo_anulacion' => $this->column('text', 'text', nullable: true),
                'observacion' => $this->column('text', 'text', nullable: true),
                'created_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'detalle_producciones' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'produccion_id' => $this->column('bigint', 'int8'),
                'articulo_id' => $this->column('bigint', 'int8'),
                'articulo_sku_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'articulo_nombre_snapshot' => $this->column('character varying', 'varchar', length: 150),
                'unidad_codigo_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'unidad_simbolo_snapshot' => $this->column('character varying', 'varchar', length: 20),
                'cantidad_teorica' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3),
                'cantidad_real' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3, nullable: true),
                'created_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'consumos_produccion' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'produccion_id' => $this->column('bigint', 'int8'),
                'detalle_receta_id' => $this->column('bigint', 'int8', nullable: true),
                'articulo_componente_id' => $this->column('bigint', 'int8'),
                'articulo_sku_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'articulo_nombre_snapshot' => $this->column('character varying', 'varchar', length: 150),
                'unidad_codigo_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'unidad_simbolo_snapshot' => $this->column('character varying', 'varchar', length: 20),
                'cantidad_teorica' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3, nullable: true),
                'cantidad_real' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3, nullable: true),
                'created_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'salidas_meson' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'detalle_produccion_id' => $this->column('bigint', 'int8'),
                'usuario_responsable_id' => $this->column('bigint', 'int8'),
                'usuario_registrador_id' => $this->column('bigint', 'int8'),
                'usuario_anulador_id' => $this->column('bigint', 'int8', nullable: true),
                'salida_reemplazada_id' => $this->column('bigint', 'int8', nullable: true),
                'clave_idempotencia' => $this->column('uuid', 'uuid'),
                'cantidad' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3),
                'ocurrido_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'motivo_ajuste_ocurrido_en' => $this->column('text', 'text', nullable: true),
                'anulada_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, nullable: true),
                'motivo_anulacion' => $this->column('text', 'text', nullable: true),
                'observacion' => $this->column('text', 'text', nullable: true),
                'created_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
        ];
    }

    private function column(
        string $dataType,
        string $udtName,
        ?int $length = null,
        ?int $numericPrecision = null,
        ?int $scale = null,
        ?int $datetimePrecision = null,
        bool $nullable = false,
        ?string $default = null,
    ): array {
        return compact(
            'dataType',
            'udtName',
            'length',
            'numericPrecision',
            'scale',
            'datetimePrecision',
            'nullable',
            'default',
        );
    }
}
