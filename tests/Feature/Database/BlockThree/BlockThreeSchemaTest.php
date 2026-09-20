<?php

namespace Tests\Feature\Database\BlockThree;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockThreeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_three_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('articulos'));
        $this->assertTrue(Schema::hasTable('historial_precios_venta'));
    }

    public function test_block_three_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name, udt_name, character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('articulos', 'historial_precios_venta')
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
               AND tbl.relname IN ('articulos', 'historial_precios_venta')
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
            'articulos' => ['name' => 'articulos_pkey', 'columns' => ['id']],
            'historial_precios_venta' => ['name' => 'historial_precios_venta_pkey', 'columns' => ['id']],
        ], $actual);

        foreach (['articulos', 'historial_precios_venta'] as $table) {
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
               AND tbl.relname IN ('articulos', 'historial_precios_venta')
             ORDER BY tbl.relname, con.conname
            SQL);

        $actual = ['articulos' => [], 'historial_precios_venta' => []];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated), "Unvalidated CHECK {$row->constraint_name}.");
            $actual[$row->table_name][] = $row->constraint_name;
        }

        $expected = [
            'articulos' => [
                'articulos_sku_no_vacio_check',
                'articulos_sku_sin_espacios_exteriores_check',
                'articulos_sku_minusculas_check',
                'articulos_sku_formato_check',
                'articulos_nombre_no_vacio_check',
                'articulos_nombre_sin_espacios_exteriores_check',
                'articulos_tipo_articulo_valido_check',
                'articulos_codigo_barras_valido_check',
                'articulos_precio_venta_actual_valido_check',
                'articulos_stock_actual_valido_check',
                'articulos_stock_minimo_valido_check',
                'articulos_imagen_ruta_valida_check',
            ],
            'historial_precios_venta' => [
                'historial_precios_venta_precio_anterior_valido_check',
                'historial_precios_venta_precio_nuevo_valido_check',
                'historial_precios_venta_cambio_valido_check',
                'historial_precios_venta_motivo_valido_check',
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
               AND tbl.relname IN ('articulos', 'historial_precios_venta')
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

        $this->assertIndex($indexes, 'articulos_pkey', 'articulos', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'articulos_sku_lower_unique', 'articulos', [null], unique: true, lowerSku: true);
        $this->assertIndex($indexes, 'articulos_codigo_barras_unique', 'articulos', ['codigo_barras'], unique: true, partialBarcode: true);
        $this->assertIndex($indexes, 'articulos_categoria_articulo_id_index', 'articulos', ['categoria_articulo_id']);
        $this->assertIndex($indexes, 'articulos_unidad_id_index', 'articulos', ['unidad_id']);
        $this->assertIndex($indexes, 'articulos_tipo_articulo_activo_index', 'articulos', ['tipo_articulo', 'activo']);
        $this->assertIndex($indexes, 'historial_precios_venta_pkey', 'historial_precios_venta', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'historial_precios_venta_articulo_vigencia_id_index', 'historial_precios_venta', ['articulo_id', 'vigente_desde', 'id']);
        $this->assertIndex($indexes, 'historial_precios_venta_usuario_id_index', 'historial_precios_venta', ['usuario_id']);

        // The composite index already covers lookups by articulo_id alone.
        $redundantIndexes = array_filter($indexes, static fn (array $index): bool =>
            $index['table'] === 'historial_precios_venta'
            && $index['columns'] === ['articulo_id']
            && $index['attributeCount'] === 1
            && $index['expression'] === null
            && $index['predicate'] === null
        );

        $this->assertSame([], $redundantIndexes);
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
               AND tbl.relname IN ('articulos', 'historial_precios_venta')
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
            'articulos_categoria_articulo_id_foreign' => [
                'table' => 'articulos', 'columns' => ['categoria_articulo_id'],
                'target' => 'categorias_articulo', 'targetColumns' => ['id'],
            ],
            'articulos_unidad_id_foreign' => [
                'table' => 'articulos', 'columns' => ['unidad_id'],
                'target' => 'unidades_medida', 'targetColumns' => ['id'],
            ],
            'historial_precios_venta_articulo_id_foreign' => [
                'table' => 'historial_precios_venta', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'historial_precios_venta_usuario_id_foreign' => [
                'table' => 'historial_precios_venta', 'columns' => ['usuario_id'],
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
        bool $lowerSku = false,
        bool $partialBarcode = false,
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

        if ($lowerSku) {
            $this->assertNotNull($index['expression'], $name);
            $expression = preg_replace('/\s+/', '', $index['expression']);
            $this->assertMatchesRegularExpression('/^lower\(\(*sku\)*(?:::text)?\)$/', $expression, $name);
        } else {
            $this->assertNull($index['expression'], $name);
        }

        if ($partialBarcode) {
            $this->assertNotNull($index['predicate'], $name);
            $predicate = preg_replace('/[()]/', '', $index['predicate']);
            $predicate = preg_replace('/\s+/', ' ', trim($predicate));
            $this->assertSame('codigo_barras IS NOT NULL', $predicate, $name);
        } else {
            $this->assertNull($index['predicate'], $name);
        }
    }

    private function assertColumnDefault(?string $expected, ?string $actual, string $label): void
    {
        if ($expected === 'sequence') {
            $this->assertMatchesRegularExpression("/^nextval\('.+'::regclass\)$/", $actual ?? '', $label);
        } elseif ($expected === 'zero') {
            $this->assertMatchesRegularExpression(
                "/^(?:0(?:\.0+)?|'0(?:\.0+)?')(?:::numeric(?:\(\d+,\s*\d+\))?)?$/",
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

    private function expectedColumns(): array
    {
        return [
            'articulos' => [
                'id' => $this->column('int8', default: 'sequence'),
                'sku' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 150),
                'descripcion' => $this->column('text', nullable: true),
                'tipo_articulo' => $this->column('varchar', length: 20),
                'categoria_articulo_id' => $this->column('int8'),
                'unidad_id' => $this->column('int8'),
                'codigo_barras' => $this->column('varchar', length: 64, nullable: true),
                'precio_venta_actual' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'stock_actual' => $this->column('numeric', numericPrecision: 14, scale: 3, default: 'zero'),
                'stock_minimo' => $this->column('numeric', numericPrecision: 14, scale: 3, default: 'zero'),
                'activo' => $this->column('bool', default: 'true'),
                'imagen_ruta' => $this->column('varchar', length: 255, nullable: true),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'historial_precios_venta' => [
                'id' => $this->column('int8', default: 'sequence'),
                'articulo_id' => $this->column('int8'),
                'precio_anterior' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
                'precio_nuevo' => $this->column('numeric', numericPrecision: 14, scale: 2, nullable: true),
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
