<?php

namespace Tests\Feature\Database\BlockTen;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockTenSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'conteos_inventario',
        'detalle_conteos_inventario',
        'mermas',
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

    public function test_block_ten_columns_match_migrations(): void
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
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

        $this->assertCount(10, $actual['conteos_inventario']);
        $this->assertCount(12, $actual['detalle_conteos_inventario']);
        $this->assertCount(18, $actual['mermas']);
        $this->assertCount(40, $rows);
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
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
            'conteos_inventario' => [
                'name' => 'conteos_inventario_pkey', 'columns' => ['id'],
            ],
            'detalle_conteos_inventario' => [
                'name' => 'detalle_conteos_inventario_pkey', 'columns' => ['id'],
            ],
            'mermas' => [
                'name' => 'mermas_pkey', 'columns' => ['id'],
            ],
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
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

        $this->assertCount(23, $definitions);

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

    public function test_unique_constraints_and_partial_unique_index_total_four(): void
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
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
            'detalle_conteos_inventario_conteo_articulo_unique' => [
                'table' => 'detalle_conteos_inventario',
                'columns' => ['conteo_inventario_id', 'articulo_id'],
            ],
            'mermas_clave_idempotencia_unique' => [
                'table' => 'mermas', 'columns' => ['clave_idempotencia'],
            ],
            'mermas_merma_reemplazada_id_unique' => [
                'table' => 'mermas', 'columns' => ['merma_reemplazada_id'],
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
               )
               AND ind.indisunique
               AND NOT ind.indisprimary
               AND ind.indpred IS NOT NULL
             ORDER BY idx.relname
            SQL);

        $this->assertCount(1, $partialUniqueIndexes);
        $this->assertSame('conteos_inventario_estado_borrador_unique', $partialUniqueIndexes[0]->index_name);
        $this->assertSame('conteos_inventario', $partialUniqueIndexes[0]->table_name);
        $this->assertSame("estado='borrador'", $this->normalizePredicate($partialUniqueIndexes[0]->predicate));
        $this->assertSame(4, count($constraints) + count($partialUniqueIndexes));
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
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

        $this->assertIndex($indexes, 'conteos_inventario_pkey', 'conteos_inventario', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'conteos_inventario_estado_borrador_unique',
            'conteos_inventario',
            ['estado'],
            unique: true,
            predicate: "estado='borrador'",
        );
        $this->assertIndex($indexes, 'conteos_inventario_created_at_id_index', 'conteos_inventario', ['created_at', 'id']);
        $this->assertIndex($indexes, 'conteos_inventario_estado_created_at_id_index', 'conteos_inventario', ['estado', 'created_at', 'id']);
        $this->assertIndex($indexes, 'conteos_inventario_usuario_creador_id_created_at_id_index', 'conteos_inventario', ['usuario_creador_id', 'created_at', 'id']);
        $this->assertIndex($indexes, 'conteos_inventario_usuario_responsable_id_created_at_id_index', 'conteos_inventario', ['usuario_responsable_id', 'created_at', 'id']);
        $this->assertIndex(
            $indexes,
            'conteos_inventario_sesion_caja_id_created_at_id_index',
            'conteos_inventario',
            ['sesion_caja_id', 'created_at', 'id'],
            predicate: 'sesion_caja_idisnotnull',
        );
        $this->assertIndex(
            $indexes,
            'conteos_inventario_confirmada_en_id_index',
            'conteos_inventario',
            ['confirmada_en', 'id'],
            predicate: 'confirmada_enisnotnull',
        );

        $this->assertIndex($indexes, 'detalle_conteos_inventario_pkey', 'detalle_conteos_inventario', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'detalle_conteos_inventario_conteo_articulo_unique',
            'detalle_conteos_inventario',
            ['conteo_inventario_id', 'articulo_id'],
            unique: true,
        );
        $this->assertIndex(
            $indexes,
            'detalle_conteos_inventario_articulo_conteo_id_index',
            'detalle_conteos_inventario',
            ['articulo_id', 'conteo_inventario_id', 'id'],
        );

        $this->assertIndex($indexes, 'mermas_pkey', 'mermas', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'mermas_clave_idempotencia_unique', 'mermas', ['clave_idempotencia'], unique: true);
        $this->assertIndex($indexes, 'mermas_merma_reemplazada_id_unique', 'mermas', ['merma_reemplazada_id'], unique: true);
        $this->assertIndex($indexes, 'mermas_articulo_id_ocurrido_en_id_index', 'mermas', ['articulo_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'mermas_ocurrido_en_id_index', 'mermas', ['ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'mermas_tipo_merma_ocurrido_en_id_index', 'mermas', ['tipo_merma', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'mermas_usuario_registrador_id_ocurrido_en_id_index', 'mermas', ['usuario_registrador_id', 'ocurrido_en', 'id']);
        $this->assertIndex(
            $indexes,
            'mermas_usuario_anulador_id_index',
            'mermas',
            ['usuario_anulador_id'],
            predicate: 'usuario_anulador_idisnotnull',
        );

        $this->assertCount(19, $indexes);
        $this->assertCount(12, array_filter(
            $indexes,
            static fn (array $index): bool => ! $index['primary'] && ! $index['unique'],
        ));
        $this->assertFalse($indexes['mermas_merma_reemplazada_id_unique']['nullsNotDistinct']);
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
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
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
        $this->assertCount(10, $actual);
    }

    public function test_block_ten_tables_have_no_user_defined_triggers(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, trg.tgname AS trigger_name
              FROM pg_catalog.pg_trigger AS trg
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = trg.tgrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
               )
               AND NOT trg.tgisinternal
            SQL);

        $this->assertSame([], $triggers);
    }

    public function test_block_ten_tables_have_no_user_defined_rules(): void
    {
        $rules = DB::select(<<<'SQL'
            SELECT schemaname, tablename, rulename
              FROM pg_catalog.pg_rules
             WHERE schemaname = 'public'
               AND tablename IN (
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
               )
            SQL);

        $this->assertSame([], $rules);
    }

    public function test_block_ten_tables_have_no_row_level_security_or_policies(): void
    {
        $policies = DB::select(<<<'SQL'
            SELECT schemaname, tablename, policyname
              FROM pg_catalog.pg_policies
             WHERE schemaname = 'public'
               AND tablename IN (
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
               )
            SQL);
        $tables = DB::select(<<<'SQL'
            SELECT relname AS table_name, relrowsecurity AS row_security,
                   relforcerowsecurity AS force_row_security
              FROM pg_catalog.pg_class AS tbl
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
               )
             ORDER BY tbl.relname
            SQL);

        $this->assertSame([], $policies);
        $this->assertCount(3, $tables);

        foreach ($tables as $table) {
            $this->assertFalse($this->postgresBoolean($table->row_security), $table->table_name);
            $this->assertFalse($this->postgresBoolean($table->force_row_security), $table->table_name);
        }
    }

    public function test_block_ten_tables_have_no_generated_columns(): void
    {
        $generatedColumns = DB::select(<<<'SQL'
            SELECT table_name, column_name, is_generated
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'conteos_inventario', 'detalle_conteos_inventario', 'mermas'
               )
               AND is_generated <> 'NEVER'
            SQL);

        $this->assertSame([], $generatedColumns);
    }

    public function test_block_ten_tables_exclude_unapproved_columns(): void
    {
        $forbidden = [
            'detalle_conteos_inventario' => ['diferencia'],
            'mermas' => [
                'produccion_id', 'estado', 'usuario_responsable_id',
                'observacion', 'updated_at',
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
        $predicate = strtolower($predicate);
        $predicate = preg_replace('/::(?:text|character varying)/', '', $predicate);

        return preg_replace('/[\s()]+/', '', $predicate);
    }

    private function expectedCheckNames(): array
    {
        return [
            'conteos_inventario' => [
                'conteos_inventario_estado_valido_check',
                'conteos_inventario_confirmacion_consistente_check',
                'conteos_inventario_fecha_confirmacion_valida_check',
                'conteos_inventario_observacion_valida_check',
            ],
            'detalle_conteos_inventario' => [
                'detalle_conteos_inventario_articulo_sku_snapshot_valido_check',
                'detalle_conteos_inventario_nombre_snapshot_valido_check',
                'detalle_conteos_inventario_unidad_codigo_snapshot_valido_check',
                'detalle_conteos_inventario_unidad_simbolo_snapshot_valido_check',
                'detalle_conteos_inventario_cantidad_contada_valida_check',
                'detalle_conteos_inventario_captura_consistente_check',
                'detalle_conteos_inventario_stock_teorico_consistente_check',
            ],
            'mermas' => [
                'mermas_articulo_sku_snapshot_valido_check',
                'mermas_articulo_nombre_snapshot_valido_check',
                'mermas_unidad_codigo_snapshot_valido_check',
                'mermas_unidad_simbolo_snapshot_valido_check',
                'mermas_tipo_valido_check',
                'mermas_cantidad_valida_check',
                'mermas_motivo_detalle_consistente_check',
                'mermas_ocurrido_en_consistente_check',
                'mermas_anulacion_consistente_check',
                'mermas_fecha_anulacion_valida_check',
                'mermas_motivo_anulacion_valido_check',
                'mermas_reemplazo_distinto_check',
            ],
        ];
    }

    private function expectedCheckFragments(): array
    {
        return [
            'conteos_inventario_estado_valido_check' => [
                'estado', "'borrador'", "'confirmado'",
            ],
            'conteos_inventario_confirmacion_consistente_check' => [
                "estado = 'borrador'", 'usuario_confirmador_id IS NULL', 'confirmada_en IS NULL',
                "estado = 'confirmado'", 'usuario_confirmador_id IS NOT NULL', 'confirmada_en IS NOT NULL',
            ],
            'conteos_inventario_fecha_confirmacion_valida_check' => [
                'confirmada_en IS NULL', 'confirmada_en >= created_at',
            ],
            'conteos_inventario_observacion_valida_check' => [
                'observacion IS NULL', "btrim(observacion) <> ''", 'observacion = btrim(observacion)',
            ],
            'detalle_conteos_inventario_articulo_sku_snapshot_valido_check' => [
                "btrim(articulo_sku_snapshot) <> ''", 'articulo_sku_snapshot = btrim(articulo_sku_snapshot)',
                'articulo_sku_snapshot = lower(articulo_sku_snapshot)', "'^[a-z0-9][a-z0-9_-]*$'",
            ],
            'detalle_conteos_inventario_nombre_snapshot_valido_check' => [
                "btrim(articulo_nombre_snapshot) <> ''", 'articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)',
            ],
            'detalle_conteos_inventario_unidad_codigo_snapshot_valido_check' => [
                "btrim(unidad_codigo_snapshot) <> ''", 'unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)',
                'unidad_codigo_snapshot = lower(unidad_codigo_snapshot)', "'^[a-z][a-z0-9_-]*$'",
            ],
            'detalle_conteos_inventario_unidad_simbolo_snapshot_valido_check' => [
                "btrim(unidad_simbolo_snapshot) <> ''", 'unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)',
            ],
            'detalle_conteos_inventario_cantidad_contada_valida_check' => [
                'cantidad_contada IS NULL', 'cantidad_contada >= 0', "cantidad_contada <> 'NaN'",
            ],
            'detalle_conteos_inventario_captura_consistente_check' => [
                'cantidad_contada IS NULL', 'contada_en IS NULL',
                'cantidad_contada IS NOT NULL', 'contada_en IS NOT NULL',
            ],
            'detalle_conteos_inventario_stock_teorico_consistente_check' => [
                'stock_teorico_snapshot IS NULL', 'cantidad_contada IS NOT NULL',
                'stock_teorico_snapshot >= 0', "stock_teorico_snapshot <> 'NaN'",
            ],
            'mermas_articulo_sku_snapshot_valido_check' => [
                "btrim(articulo_sku_snapshot) <> ''", 'articulo_sku_snapshot = btrim(articulo_sku_snapshot)',
                'articulo_sku_snapshot = lower(articulo_sku_snapshot)', "'^[a-z0-9][a-z0-9_-]*$'",
            ],
            'mermas_articulo_nombre_snapshot_valido_check' => [
                "btrim(articulo_nombre_snapshot) <> ''", 'articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)',
            ],
            'mermas_unidad_codigo_snapshot_valido_check' => [
                "btrim(unidad_codigo_snapshot) <> ''", 'unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)',
                'unidad_codigo_snapshot = lower(unidad_codigo_snapshot)', "'^[a-z][a-z0-9_-]*$'",
            ],
            'mermas_unidad_simbolo_snapshot_valido_check' => [
                "btrim(unidad_simbolo_snapshot) <> ''", 'unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)',
            ],
            'mermas_tipo_valido_check' => [
                'tipo_merma', "'vencimiento'", "'danio'", "'error_produccion'", "'contaminacion'", "'otro'",
            ],
            'mermas_cantidad_valida_check' => ['cantidad > 0', "cantidad <> 'NaN'"],
            'mermas_motivo_detalle_consistente_check' => [
                "tipo_merma = 'otro'", 'motivo_detalle IS NOT NULL', "btrim(motivo_detalle) <> ''",
                'motivo_detalle = btrim(motivo_detalle)', "tipo_merma <> 'otro'", 'motivo_detalle IS NULL',
            ],
            'mermas_ocurrido_en_consistente_check' => [
                'motivo_ajuste_ocurrido_en IS NULL', 'ocurrido_en = created_at',
                'motivo_ajuste_ocurrido_en IS NOT NULL', "btrim(motivo_ajuste_ocurrido_en) <> ''",
                'motivo_ajuste_ocurrido_en = btrim(motivo_ajuste_ocurrido_en)', 'ocurrido_en < created_at',
            ],
            'mermas_anulacion_consistente_check' => [
                'usuario_anulador_id IS NULL', 'anulada_en IS NULL', 'motivo_anulacion IS NULL',
                'usuario_anulador_id IS NOT NULL', 'anulada_en IS NOT NULL', 'motivo_anulacion IS NOT NULL',
            ],
            'mermas_fecha_anulacion_valida_check' => ['anulada_en IS NULL', 'anulada_en >= created_at'],
            'mermas_motivo_anulacion_valido_check' => [
                'motivo_anulacion IS NULL', "btrim(motivo_anulacion) <> ''",
                'motivo_anulacion = btrim(motivo_anulacion)',
            ],
            'mermas_reemplazo_distinto_check' => [
                'merma_reemplazada_id IS NULL', 'merma_reemplazada_id <> id',
            ],
        ];
    }

    private function expectedForeignKeys(): array
    {
        return [
            'conteos_inventario_sesion_caja_id_foreign' => [
                'table' => 'conteos_inventario', 'columns' => ['sesion_caja_id'],
                'target' => 'sesiones_caja', 'targetColumns' => ['id'],
            ],
            'conteos_inventario_usuario_creador_id_foreign' => [
                'table' => 'conteos_inventario', 'columns' => ['usuario_creador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'conteos_inventario_usuario_responsable_id_foreign' => [
                'table' => 'conteos_inventario', 'columns' => ['usuario_responsable_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'conteos_inventario_usuario_confirmador_id_foreign' => [
                'table' => 'conteos_inventario', 'columns' => ['usuario_confirmador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'detalle_conteos_inventario_conteo_inventario_id_foreign' => [
                'table' => 'detalle_conteos_inventario', 'columns' => ['conteo_inventario_id'],
                'target' => 'conteos_inventario', 'targetColumns' => ['id'],
            ],
            'detalle_conteos_inventario_articulo_id_foreign' => [
                'table' => 'detalle_conteos_inventario', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'mermas_articulo_id_foreign' => [
                'table' => 'mermas', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'mermas_usuario_registrador_id_foreign' => [
                'table' => 'mermas', 'columns' => ['usuario_registrador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'mermas_usuario_anulador_id_foreign' => [
                'table' => 'mermas', 'columns' => ['usuario_anulador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'mermas_merma_reemplazada_id_foreign' => [
                'table' => 'mermas', 'columns' => ['merma_reemplazada_id'],
                'target' => 'mermas', 'targetColumns' => ['id'],
            ],
        ];
    }

    private function expectedColumns(): array
    {
        return [
            'conteos_inventario' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'sesion_caja_id' => $this->column('bigint', 'int8', nullable: true),
                'usuario_creador_id' => $this->column('bigint', 'int8'),
                'usuario_responsable_id' => $this->column('bigint', 'int8'),
                'usuario_confirmador_id' => $this->column('bigint', 'int8', nullable: true),
                'estado' => $this->column('character varying', 'varchar', length: 20, default: "'borrador'::character varying"),
                'confirmada_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, nullable: true),
                'observacion' => $this->column('text', 'text', nullable: true),
                'created_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'detalle_conteos_inventario' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'conteo_inventario_id' => $this->column('bigint', 'int8'),
                'articulo_id' => $this->column('bigint', 'int8'),
                'articulo_sku_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'articulo_nombre_snapshot' => $this->column('character varying', 'varchar', length: 150),
                'unidad_codigo_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'unidad_simbolo_snapshot' => $this->column('character varying', 'varchar', length: 20),
                'cantidad_contada' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3, nullable: true),
                'contada_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, nullable: true),
                'stock_teorico_snapshot' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3, nullable: true),
                'created_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'mermas' => [
                'id' => $this->column('bigint', 'int8', default: 'sequence'),
                'articulo_id' => $this->column('bigint', 'int8'),
                'usuario_registrador_id' => $this->column('bigint', 'int8'),
                'usuario_anulador_id' => $this->column('bigint', 'int8', nullable: true),
                'merma_reemplazada_id' => $this->column('bigint', 'int8', nullable: true),
                'clave_idempotencia' => $this->column('uuid', 'uuid'),
                'articulo_sku_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'articulo_nombre_snapshot' => $this->column('character varying', 'varchar', length: 150),
                'unidad_codigo_snapshot' => $this->column('character varying', 'varchar', length: 50),
                'unidad_simbolo_snapshot' => $this->column('character varying', 'varchar', length: 20),
                'tipo_merma' => $this->column('character varying', 'varchar', length: 30),
                'motivo_detalle' => $this->column('text', 'text', nullable: true),
                'cantidad' => $this->column('numeric', 'numeric', numericPrecision: 14, scale: 3),
                'ocurrido_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'motivo_ajuste_ocurrido_en' => $this->column('text', 'text', nullable: true),
                'anulada_en' => $this->column('timestamp with time zone', 'timestamptz', datetimePrecision: 6, nullable: true),
                'motivo_anulacion' => $this->column('text', 'text', nullable: true),
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
