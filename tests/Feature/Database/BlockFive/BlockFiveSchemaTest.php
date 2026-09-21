<?php

namespace Tests\Feature\Database\BlockFive;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockFiveSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_five_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('recetas'));
        $this->assertTrue(Schema::hasTable('detalle_recetas'));
    }

    public function test_block_five_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name, udt_name, character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('recetas', 'detalle_recetas')
             ORDER BY table_name, ordinal_position
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->table_name][$row->column_name] = [
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

            foreach ($columns as $column => $expected) {
                $metadata = $actual[$table][$column];
                $this->assertColumnDefault($expected['default'], $metadata['default'], "{$table}.{$column}");
                unset($expected['default'], $metadata['default']);

                $this->assertSame($expected, $metadata, "Unexpected column metadata for {$table}.{$column}.");
            }
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
               AND tbl.relname IN ('recetas', 'detalle_recetas')
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
            'detalle_recetas' => ['name' => 'detalle_recetas_pkey', 'columns' => ['id']],
            'recetas' => ['name' => 'recetas_pkey', 'columns' => ['id']],
        ], $actual);

        foreach (['detalle_recetas', 'recetas'] as $table) {
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
            SELECT tbl.relname AS table_name, con.conname AS constraint_name, con.convalidated AS validated
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public' AND con.contype = 'c'
               AND tbl.relname IN ('recetas', 'detalle_recetas')
             ORDER BY tbl.relname, con.conname
            SQL);

        $actual = ['detalle_recetas' => [], 'recetas' => []];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated), "Unvalidated CHECK {$row->constraint_name}.");
            $actual[$row->table_name][] = $row->constraint_name;
        }

        $expected = [
            'detalle_recetas' => [
                'detalle_recetas_cantidad_valida_check',
            ],
            'recetas' => [
                'recetas_observacion_valida_check',
                'recetas_rendimiento_cantidad_valida_check',
                'recetas_version_valida_check',
            ],
        ];

        foreach ($expected as $table => $names) {
            sort($names);
            sort($actual[$table]);

            $this->assertSame($names, $actual[$table], "Unexpected CHECK constraints for {$table}.");
        }
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
               AND tbl.relname IN ('recetas', 'detalle_recetas')
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

        $this->assertIndex($indexes, 'detalle_recetas_pkey', 'detalle_recetas', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'detalle_recetas_receta_id_articulo_componente_id_unique',
            'detalle_recetas',
            ['receta_id', 'articulo_componente_id'],
            unique: true,
        );
        $this->assertIndex(
            $indexes,
            'detalle_recetas_articulo_componente_id_index',
            'detalle_recetas',
            ['articulo_componente_id'],
        );
        $this->assertIndex($indexes, 'recetas_pkey', 'recetas', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'recetas_articulo_id_version_unique',
            'recetas',
            ['articulo_id', 'version'],
            unique: true,
        );
        $this->assertIndex(
            $indexes,
            'recetas_articulo_id_activa_unique',
            'recetas',
            ['articulo_id'],
            unique: true,
            partialActive: true,
        );

        $expectedNames = [
            'detalle_recetas_articulo_componente_id_index',
            'detalle_recetas_pkey',
            'detalle_recetas_receta_id_articulo_componente_id_unique',
            'recetas_articulo_id_activa_unique',
            'recetas_articulo_id_version_unique',
            'recetas_pkey',
        ];
        $actualNames = array_keys($indexes);
        sort($expectedNames);
        sort($actualNames);
        $this->assertSame($expectedNames, $actualNames);

        $redundantRecipeArticleIndexes = array_filter($indexes, static fn (array $index): bool =>
            $index['table'] === 'recetas'
            && $index['columns'] === ['articulo_id']
            && $index['attributeCount'] === 1
            && ! $index['unique']
            && $index['expression'] === null
            && $index['predicate'] === null
        );
        $this->assertSame([], $redundantRecipeArticleIndexes);

        $redundantDetailRecipeIndexes = array_filter($indexes, static fn (array $index): bool =>
            $index['table'] === 'detalle_recetas'
            && $index['columns'] === ['receta_id']
            && $index['attributeCount'] === 1
            && $index['expression'] === null
            && $index['predicate'] === null
        );
        $this->assertSame([], $redundantDetailRecipeIndexes);
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
               AND tbl.relname IN ('recetas', 'detalle_recetas')
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

        $this->assertSame([
            'detalle_recetas_articulo_componente_id_foreign' => [
                'table' => 'detalle_recetas', 'columns' => ['articulo_componente_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'detalle_recetas_receta_id_foreign' => [
                'table' => 'detalle_recetas', 'columns' => ['receta_id'],
                'target' => 'recetas', 'targetColumns' => ['id'],
            ],
            'recetas_articulo_id_foreign' => [
                'table' => 'recetas', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
        ], $actual);
    }

    private function assertIndex(
        array $indexes,
        string $name,
        string $table,
        array $columns,
        bool $unique = false,
        bool $primary = false,
        bool $partialActive = false,
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

        if ($partialActive) {
            $this->assertNotNull($index['predicate'], $name);
            $predicate = strtolower(preg_replace('/[\s()]+/', '', $index['predicate']));
            $this->assertContains($predicate, ['activa', 'activa=true'], $name);
        } else {
            $this->assertNull($index['predicate'], $name);
        }
    }

    private function assertColumnDefault(?string $expected, ?string $actual, string $label): void
    {
        if ($expected === 'sequence') {
            $this->assertMatchesRegularExpression("/^nextval\('.+'::regclass\)$/", $actual ?? '', $label);
        } else {
            $this->assertSame($expected, $actual, "Unexpected default for {$label}.");
        }
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function expectedColumns(): array
    {
        return [
            'detalle_recetas' => [
                'id' => $this->column('int8', default: 'sequence'),
                'receta_id' => $this->column('int8'),
                'articulo_componente_id' => $this->column('int8'),
                'cantidad' => $this->column('numeric', numericPrecision: 14, scale: 3),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'recetas' => [
                'id' => $this->column('int8', default: 'sequence'),
                'articulo_id' => $this->column('int8'),
                'version' => $this->column('int4'),
                'rendimiento_cantidad' => $this->column('numeric', numericPrecision: 14, scale: 3),
                'activa' => $this->column('bool', default: 'false'),
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
