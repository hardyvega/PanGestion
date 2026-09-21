<?php

namespace Tests\Feature\Database\BlockFour;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockFourSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_four_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('clientes'));
        $this->assertTrue(Schema::hasTable('proveedores'));
        $this->assertTrue(Schema::hasTable('articulo_proveedor'));
        $this->assertTrue(Schema::hasTable('historial_costos_articulo'));
    }

    public function test_block_four_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name, udt_name, character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN (
                   'clientes',
                   'proveedores',
                   'articulo_proveedor',
                   'historial_costos_articulo'
               )
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
        $tables = ['articulo_proveedor', 'clientes', 'historial_costos_articulo', 'proveedores'];
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
                   'clientes',
                   'proveedores',
                   'articulo_proveedor',
                   'historial_costos_articulo'
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
            'articulo_proveedor' => ['name' => 'articulo_proveedor_pkey', 'columns' => ['id']],
            'clientes' => ['name' => 'clientes_pkey', 'columns' => ['id']],
            'historial_costos_articulo' => ['name' => 'historial_costos_articulo_pkey', 'columns' => ['id']],
            'proveedores' => ['name' => 'proveedores_pkey', 'columns' => ['id']],
        ], $actual);

        foreach ($tables as $table) {
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
               AND tbl.relname IN (
                   'clientes',
                   'proveedores',
                   'articulo_proveedor',
                   'historial_costos_articulo'
               )
             ORDER BY tbl.relname, con.conname
            SQL);

        $actual = [
            'articulo_proveedor' => [],
            'clientes' => [],
            'historial_costos_articulo' => [],
            'proveedores' => [],
        ];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated), "Unvalidated CHECK {$row->constraint_name}.");
            $actual[$row->table_name][] = $row->constraint_name;
        }

        $expected = [
            'articulo_proveedor' => [
                'articulo_proveedor_codigo_articulo_proveedor_valido_check',
            ],
            'clientes' => [
                'clientes_correo_valido_check',
                'clientes_nombre_no_vacio_check',
                'clientes_nombre_sin_espacios_exteriores_check',
                'clientes_observacion_valida_check',
                'clientes_telefono_busqueda_hash_formato_check',
                'clientes_telefono_cifrado_valido_check',
                'clientes_telefono_par_consistente_check',
            ],
            'historial_costos_articulo' => [
                'historial_costos_articulo_cambio_valido_check',
                'historial_costos_articulo_costo_anterior_valido_check',
                'historial_costos_articulo_costo_nuevo_valido_check',
                'historial_costos_articulo_motivo_valido_check',
            ],
            'proveedores' => [
                'proveedores_codigo_formato_check',
                'proveedores_codigo_minusculas_check',
                'proveedores_codigo_no_vacio_check',
                'proveedores_codigo_sin_espacios_exteriores_check',
                'proveedores_correo_valido_check',
                'proveedores_nombre_contacto_valido_check',
                'proveedores_nombre_no_vacio_check',
                'proveedores_nombre_sin_espacios_exteriores_check',
                'proveedores_observacion_valida_check',
                'proveedores_telefono_valido_check',
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
            SELECT tbl.relname AS table_name, idx.relname AS index_name, am.amname AS access_method,
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
              JOIN pg_catalog.pg_am AS am ON am.oid = idx.relam
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = ind.indrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN (
                   'clientes',
                   'proveedores',
                   'articulo_proveedor',
                   'historial_costos_articulo'
               )
             ORDER BY idx.relname
            SQL);

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[$row->index_name] = [
                'table' => $row->table_name,
                'method' => $row->access_method,
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

        $this->assertIndex($indexes, 'clientes_pkey', 'clientes', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'clientes_telefono_busqueda_hash_index',
            'clientes',
            ['telefono_busqueda_hash'],
            predicateColumn: 'telefono_busqueda_hash',
        );
        $this->assertIndex($indexes, 'proveedores_pkey', 'proveedores', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'proveedores_codigo_lower_unique',
            'proveedores',
            [null],
            unique: true,
            lowerColumn: 'codigo',
        );
        $this->assertIndex($indexes, 'articulo_proveedor_pkey', 'articulo_proveedor', ['id'], unique: true, primary: true);
        $this->assertIndex(
            $indexes,
            'articulo_proveedor_articulo_id_proveedor_id_unique',
            'articulo_proveedor',
            ['articulo_id', 'proveedor_id'],
            unique: true,
        );
        $this->assertIndex(
            $indexes,
            'articulo_proveedor_proveedor_id_index',
            'articulo_proveedor',
            ['proveedor_id'],
        );
        $this->assertIndex(
            $indexes,
            'historial_costos_articulo_pkey',
            'historial_costos_articulo',
            ['id'],
            unique: true,
            primary: true,
        );
        $this->assertIndex(
            $indexes,
            'historial_costos_articulo_articulo_vigencia_id_index',
            'historial_costos_articulo',
            ['articulo_id', 'vigente_desde', 'id'],
        );
        $this->assertIndex(
            $indexes,
            'historial_costos_articulo_proveedor_articulo_vigencia_id_index',
            'historial_costos_articulo',
            ['proveedor_id', 'articulo_id', 'vigente_desde', 'id'],
            predicateColumn: 'proveedor_id',
        );
        $this->assertIndex(
            $indexes,
            'historial_costos_articulo_usuario_id_index',
            'historial_costos_articulo',
            ['usuario_id'],
        );

        $uniqueConstraint = DB::selectOne(<<<'SQL'
            SELECT con.conname AS constraint_name, con.convalidated AS validated,
                   (
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(con.conkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = con.conrelid AND att.attnum = keys.attnum
                   )::text AS columns
              FROM pg_catalog.pg_constraint AS con
             WHERE con.connamespace = 'public'::regnamespace
               AND con.contype = 'u'
               AND con.conname = 'articulo_proveedor_articulo_id_proveedor_id_unique'
            SQL);

        $this->assertNotNull($uniqueConstraint);
        $this->assertTrue($this->postgresBoolean($uniqueConstraint->validated));
        $this->assertSame(
            ['articulo_id', 'proveedor_id'],
            json_decode($uniqueConstraint->columns, true, flags: JSON_THROW_ON_ERROR),
        );

        $clientEmailIndexes = array_filter($indexes, static fn (array $index): bool =>
            $index['table'] === 'clientes'
            && (
                in_array('correo', $index['columns'], true)
                || ($index['expression'] !== null && str_contains($index['expression'], 'correo'))
            )
        );
        $this->assertSame([], $clientEmailIndexes);

        foreach (['articulo_proveedor', 'historial_costos_articulo'] as $table) {
            $redundantIndexes = array_filter($indexes, static fn (array $index): bool =>
                $index['table'] === $table
                && $index['columns'] === ['articulo_id']
                && $index['attributeCount'] === 1
                && $index['expression'] === null
                && $index['predicate'] === null
            );

            $this->assertSame([], $redundantIndexes);
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
               AND tbl.relname IN ('articulo_proveedor', 'historial_costos_articulo')
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
            'articulo_proveedor_articulo_id_foreign' => [
                'table' => 'articulo_proveedor', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'articulo_proveedor_proveedor_id_foreign' => [
                'table' => 'articulo_proveedor', 'columns' => ['proveedor_id'],
                'target' => 'proveedores', 'targetColumns' => ['id'],
            ],
            'historial_costos_articulo_articulo_id_foreign' => [
                'table' => 'historial_costos_articulo', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'historial_costos_articulo_proveedor_id_foreign' => [
                'table' => 'historial_costos_articulo', 'columns' => ['proveedor_id'],
                'target' => 'proveedores', 'targetColumns' => ['id'],
            ],
            'historial_costos_articulo_usuario_id_foreign' => [
                'table' => 'historial_costos_articulo', 'columns' => ['usuario_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
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
        ?string $lowerColumn = null,
        ?string $predicateColumn = null,
    ): void {
        $this->assertArrayHasKey($name, $indexes);
        $index = $indexes[$name];

        $this->assertSame($table, $index['table'], $name);
        $this->assertSame('btree', $index['method'], $name);
        $this->assertSame($columns, $index['columns'], $name);
        $this->assertSame(count($columns), $index['keyCount'], $name);
        $this->assertSame(count($columns), $index['attributeCount'], $name);
        $this->assertSame($unique, $index['unique'], $name);
        $this->assertSame($primary, $index['primary'], $name);
        $this->assertTrue($index['valid'], $name);

        if ($lowerColumn !== null) {
            $this->assertNotNull($index['expression'], $name);
            $expression = preg_replace('/\s+/', '', $index['expression']);
            $column = preg_quote($lowerColumn, '/');
            $this->assertMatchesRegularExpression("/^lower\(\(*{$column}\)*(?:::text)?\)$/", $expression, $name);
        } else {
            $this->assertNull($index['expression'], $name);
        }

        if ($predicateColumn !== null) {
            $this->assertNotNull($index['predicate'], $name);
            $predicate = preg_replace('/[()]/', '', $index['predicate']);
            $predicate = preg_replace('/\s+/', ' ', trim($predicate));
            $this->assertSame("{$predicateColumn} IS NOT NULL", $predicate, $name);
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
            'clientes' => [
                'id' => $this->column('int8', default: 'sequence'),
                'nombre' => $this->column('varchar', length: 150),
                'telefono_cifrado' => $this->column('text', nullable: true),
                'telefono_busqueda_hash' => $this->column('varchar', length: 64, nullable: true),
                'correo' => $this->column('varchar', length: 254, nullable: true),
                'observacion' => $this->column('text', nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'proveedores' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 150),
                'nombre_contacto' => $this->column('varchar', length: 150, nullable: true),
                'telefono' => $this->column('varchar', length: 50, nullable: true),
                'correo' => $this->column('varchar', length: 254, nullable: true),
                'observacion' => $this->column('text', nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'articulo_proveedor' => [
                'id' => $this->column('int8', default: 'sequence'),
                'articulo_id' => $this->column('int8'),
                'proveedor_id' => $this->column('int8'),
                'codigo_articulo_proveedor' => $this->column('varchar', length: 100, nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'historial_costos_articulo' => [
                'id' => $this->column('int8', default: 'sequence'),
                'articulo_id' => $this->column('int8'),
                'proveedor_id' => $this->column('int8', nullable: true),
                'costo_anterior' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'costo_nuevo' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'vigente_desde' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'usuario_id' => $this->column('int8'),
                'motivo' => $this->column('text', nullable: true),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
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
