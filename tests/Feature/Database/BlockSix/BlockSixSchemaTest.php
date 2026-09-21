<?php

namespace Tests\Feature\Database\BlockSix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockSixSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_six_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('sesiones_caja'));
        $this->assertTrue(Schema::hasTable('movimientos_caja'));
    }

    public function test_block_six_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name, udt_name, character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('sesiones_caja', 'movimientos_caja')
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
               AND tbl.relname IN ('sesiones_caja', 'movimientos_caja')
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
            'movimientos_caja' => ['name' => 'movimientos_caja_pkey', 'columns' => ['id']],
            'sesiones_caja' => ['name' => 'sesiones_caja_pkey', 'columns' => ['id']],
        ], $actual);

        foreach (['movimientos_caja', 'sesiones_caja'] as $table) {
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
               AND tbl.relname IN ('sesiones_caja', 'movimientos_caja')
             ORDER BY tbl.relname, con.conname
            SQL);

        $actual = ['movimientos_caja' => [], 'sesiones_caja' => []];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated), "Unvalidated CHECK {$row->constraint_name}.");
            $actual[$row->table_name][] = $row->constraint_name;
        }

        $expected = [
            'movimientos_caja' => [
                'movimientos_caja_concepto_valido_check',
                'movimientos_caja_monto_valido_check',
                'movimientos_caja_tipo_movimiento_valido_check',
            ],
            'sesiones_caja' => [
                'sesiones_caja_cierre_consistente_check',
                'sesiones_caja_fechas_validas_check',
                'sesiones_caja_monto_cierre_declarado_valido_check',
                'sesiones_caja_monto_inicial_valido_check',
                'sesiones_caja_monto_teorico_cierre_valido_check',
                'sesiones_caja_observacion_cierre_valida_check',
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
               AND tbl.relname IN ('sesiones_caja', 'movimientos_caja')
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

        $this->assertIndex($indexes, 'movimientos_caja_pkey', 'movimientos_caja', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'movimientos_caja_sesion_caja_id_ocurrido_en_id_index', 'movimientos_caja', ['sesion_caja_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'movimientos_caja_tipo_movimiento_ocurrido_en_id_index', 'movimientos_caja', ['tipo_movimiento', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'movimientos_caja_usuario_id_ocurrido_en_id_index', 'movimientos_caja', ['usuario_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'sesiones_caja_pkey', 'sesiones_caja', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'sesiones_caja_caja_id_abierta_unique', 'sesiones_caja', ['caja_id'], unique: true, predicate: 'cerrada_enisnull');
        $this->assertIndex($indexes, 'sesiones_caja_caja_id_abierta_en_id_index', 'sesiones_caja', ['caja_id', 'abierta_en', 'id']);
        $this->assertIndex($indexes, 'sesiones_caja_usuario_apertura_id_index', 'sesiones_caja', ['usuario_apertura_id']);
        $this->assertIndex($indexes, 'sesiones_caja_usuario_cierre_id_index', 'sesiones_caja', ['usuario_cierre_id'], predicate: 'usuario_cierre_idisnotnull');

        $expectedNames = [
            'movimientos_caja_pkey',
            'movimientos_caja_sesion_caja_id_ocurrido_en_id_index',
            'movimientos_caja_tipo_movimiento_ocurrido_en_id_index',
            'movimientos_caja_usuario_id_ocurrido_en_id_index',
            'sesiones_caja_caja_id_abierta_en_id_index',
            'sesiones_caja_caja_id_abierta_unique',
            'sesiones_caja_pkey',
            'sesiones_caja_usuario_apertura_id_index',
            'sesiones_caja_usuario_cierre_id_index',
        ];
        $actualNames = array_keys($indexes);
        sort($expectedNames);
        sort($actualNames);
        $this->assertSame($expectedNames, $actualNames);

        foreach ([
            ['sesiones_caja', 'caja_id'],
            ['sesiones_caja', 'cerrada_en'],
            ['movimientos_caja', 'sesion_caja_id'],
            ['movimientos_caja', 'tipo_movimiento'],
            ['movimientos_caja', 'usuario_id'],
        ] as [$table, $column]) {
            $redundant = array_filter($indexes, static fn (array $index): bool =>
                $index['table'] === $table
                && $index['columns'] === [$column]
                && $index['attributeCount'] === 1
                && ! $index['primary']
                && $index['expression'] === null
                && $index['predicate'] === null
            );
            $this->assertSame([], $redundant, "Unexpected standalone index on {$table}.{$column}.");
        }
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
               AND tbl.relname IN ('sesiones_caja', 'movimientos_caja')
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
            'movimientos_caja_sesion_caja_id_foreign' => [
                'table' => 'movimientos_caja', 'columns' => ['sesion_caja_id'],
                'target' => 'sesiones_caja', 'targetColumns' => ['id'],
            ],
            'movimientos_caja_usuario_id_foreign' => [
                'table' => 'movimientos_caja', 'columns' => ['usuario_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'sesiones_caja_caja_id_foreign' => [
                'table' => 'sesiones_caja', 'columns' => ['caja_id'],
                'target' => 'cajas', 'targetColumns' => ['id'],
            ],
            'sesiones_caja_usuario_apertura_id_foreign' => [
                'table' => 'sesiones_caja', 'columns' => ['usuario_apertura_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'sesiones_caja_usuario_cierre_id_foreign' => [
                'table' => 'sesiones_caja', 'columns' => ['usuario_cierre_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
        ], $actual);
    }

    public function test_block_six_tables_have_no_user_defined_triggers(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, trg.tgname AS trigger_name
              FROM pg_catalog.pg_trigger AS trg
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = trg.tgrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN ('sesiones_caja', 'movimientos_caja')
               AND NOT trg.tgisinternal
            SQL);

        $this->assertSame([], $triggers);
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
            'movimientos_caja' => [
                'id' => $this->column('int8', default: 'sequence'),
                'sesion_caja_id' => $this->column('int8'),
                'usuario_id' => $this->column('int8'),
                'tipo_movimiento' => $this->column('varchar', length: 20),
                'monto' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'concepto' => $this->column('text'),
                'ocurrido_en' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'sesiones_caja' => [
                'id' => $this->column('int8', default: 'sequence'),
                'caja_id' => $this->column('int8'),
                'usuario_apertura_id' => $this->column('int8'),
                'usuario_cierre_id' => $this->column('int8', nullable: true),
                'monto_inicial' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'abierta_en' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'cerrada_en' => $this->column('timestamptz', datetimePrecision: 6, nullable: true),
                'monto_cierre_declarado' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'monto_teorico_cierre' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'observacion_cierre' => $this->column('text', nullable: true),
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
