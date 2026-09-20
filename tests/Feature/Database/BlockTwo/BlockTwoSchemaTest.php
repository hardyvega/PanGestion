<?php

namespace Tests\Feature\Database\BlockTwo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockTwoSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_two_tables_exist(): void
    {
        foreach (['unidades_medida', 'categorias_articulo', 'metodos_pago', 'cajas'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] does not exist.");
        }
    }

    public function test_block_two_column_metadata_matches_the_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name,
                   column_name,
                   udt_name,
                   character_maximum_length,
                   datetime_precision,
                   is_nullable,
                   column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('unidades_medida', 'categorias_articulo', 'metodos_pago', 'cajas')
             ORDER BY table_name, ordinal_position
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->table_name][$row->column_name] = [
                'type' => $row->udt_name,
                'length' => $row->character_maximum_length === null
                    ? null
                    : (int) $row->character_maximum_length,
                'precision' => $row->datetime_precision === null
                    ? null
                    : (int) $row->datetime_precision,
                'nullable' => $row->is_nullable === 'YES',
                'default' => $row->column_default,
            ];
        }

        foreach ($this->expectedColumns() as $table => $expectedColumns) {
            $this->assertArrayHasKey($table, $actual);
            $this->assertSame(array_keys($expectedColumns), array_keys($actual[$table]));

            foreach ($expectedColumns as $column => $expected) {
                $metadata = $actual[$table][$column];
                $label = "{$table}.{$column}";

                $this->assertSame($expected['type'], $metadata['type'], "Unexpected type for {$label}.");
                $this->assertSame($expected['length'], $metadata['length'], "Unexpected length for {$label}.");
                $this->assertSame($expected['precision'], $metadata['precision'], "Unexpected precision for {$label}.");
                $this->assertSame($expected['nullable'], $metadata['nullable'], "Unexpected nullability for {$label}.");
                $this->assertColumnDefault($expected['default'], $metadata['default'], $label);
            }
        }
    }

    public function test_block_two_primary_keys_are_exactly_id(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tc.table_name,
                   tc.constraint_name,
                   kcu.column_name,
                   kcu.ordinal_position
              FROM information_schema.table_constraints AS tc
              JOIN information_schema.key_column_usage AS kcu
                ON kcu.constraint_catalog = tc.constraint_catalog
               AND kcu.constraint_schema = tc.constraint_schema
               AND kcu.constraint_name = tc.constraint_name
             WHERE tc.table_schema = 'public'
               AND tc.constraint_type = 'PRIMARY KEY'
               AND tc.table_name IN ('unidades_medida', 'categorias_articulo', 'metodos_pago', 'cajas')
             ORDER BY tc.table_name, kcu.ordinal_position
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->table_name]['name'] = $row->constraint_name;
            $actual[$row->table_name]['columns'][] = $row->column_name;
        }

        $this->assertSame([
            'cajas' => ['name' => 'cajas_pkey', 'columns' => ['id']],
            'categorias_articulo' => ['name' => 'categorias_articulo_pkey', 'columns' => ['id']],
            'metodos_pago' => ['name' => 'metodos_pago_pkey', 'columns' => ['id']],
            'unidades_medida' => ['name' => 'unidades_medida_pkey', 'columns' => ['id']],
        ], $actual);
    }

    public function test_block_two_named_check_constraints_match_exactly(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT rel.relname AS table_name,
                   con.conname AS constraint_name
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS rel ON rel.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = rel.relnamespace
             WHERE nsp.nspname = 'public'
               AND con.contype = 'c'
               AND rel.relname IN ('unidades_medida', 'categorias_articulo', 'metodos_pago', 'cajas')
             ORDER BY rel.relname, con.conname
            SQL);

        $actual = [
            'cajas' => [],
            'categorias_articulo' => [],
            'metodos_pago' => [],
            'unidades_medida' => [],
        ];

        foreach ($rows as $row) {
            $actual[$row->table_name][] = $row->constraint_name;
        }

        foreach ($this->expectedCheckConstraints() as $table => $expected) {
            sort($expected);
            sort($actual[$table]);

            $this->assertSame($expected, $actual[$table], "Unexpected CHECK constraints for {$table}.");
        }
    }

    public function test_block_two_indexes_match_the_approved_semantics(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name,
                   idx.relname AS index_name,
                   ind.indisunique AS is_unique,
                   ind.indisprimary AS is_primary,
                   COALESCE((
                       SELECT json_agg(att.attname ORDER BY keys.ordinality)
                         FROM unnest(ind.indkey) WITH ORDINALITY AS keys(attnum, ordinality)
                         JOIN pg_catalog.pg_attribute AS att
                           ON att.attrelid = tbl.oid
                          AND att.attnum = keys.attnum
                        WHERE keys.attnum > 0
                   ), '[]'::json)::text AS column_names,
                   pg_get_expr(ind.indexprs, ind.indrelid) AS expression,
                   pg_get_expr(ind.indpred, ind.indrelid) AS predicate
              FROM pg_catalog.pg_index AS ind
              JOIN pg_catalog.pg_class AS idx ON idx.oid = ind.indexrelid
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = ind.indrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN ('unidades_medida', 'categorias_articulo', 'metodos_pago', 'cajas')
             ORDER BY idx.relname
            SQL);

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[$row->index_name] = [
                'table' => $row->table_name,
                'unique' => $this->postgresBoolean($row->is_unique),
                'primary' => $this->postgresBoolean($row->is_primary),
                'columns' => json_decode($row->column_names, true, flags: JSON_THROW_ON_ERROR),
                'expression' => $row->expression,
                'predicate' => $row->predicate,
            ];
        }

        $expectedNames = [
            'cajas_codigo_lower_unique',
            'cajas_nombre_lower_unique',
            'cajas_pkey',
            'categorias_articulo_codigo_lower_unique',
            'categorias_articulo_nombre_lower_unique',
            'categorias_articulo_pkey',
            'metodos_pago_codigo_lower_unique',
            'metodos_pago_nombre_lower_unique',
            'metodos_pago_pkey',
            'unidades_medida_codigo_lower_unique',
            'unidades_medida_nombre_lower_unique',
            'unidades_medida_pkey',
            'unidades_medida_simbolo_lower_unique',
        ];
        $actualNames = array_keys($indexes);
        sort($expectedNames);
        sort($actualNames);

        $this->assertSame($expectedNames, $actualNames);

        $this->assertIndex($indexes, 'cajas_pkey', 'cajas', true, true, ['id']);
        $this->assertIndex($indexes, 'cajas_codigo_lower_unique', 'cajas', true, false, [], ['lower', 'codigo']);
        $this->assertIndex($indexes, 'cajas_nombre_lower_unique', 'cajas', true, false, [], ['lower', 'nombre']);
        $this->assertIndex($indexes, 'categorias_articulo_pkey', 'categorias_articulo', true, true, ['id']);
        $this->assertIndex($indexes, 'categorias_articulo_codigo_lower_unique', 'categorias_articulo', true, false, [], ['lower', 'codigo']);
        $this->assertIndex($indexes, 'categorias_articulo_nombre_lower_unique', 'categorias_articulo', true, false, [], ['lower', 'nombre']);
        $this->assertIndex($indexes, 'metodos_pago_pkey', 'metodos_pago', true, true, ['id']);
        $this->assertIndex($indexes, 'metodos_pago_codigo_lower_unique', 'metodos_pago', true, false, [], ['lower', 'codigo']);
        $this->assertIndex($indexes, 'metodos_pago_nombre_lower_unique', 'metodos_pago', true, false, [], ['lower', 'nombre']);
        $this->assertIndex($indexes, 'unidades_medida_pkey', 'unidades_medida', true, true, ['id']);
        $this->assertIndex($indexes, 'unidades_medida_codigo_lower_unique', 'unidades_medida', true, false, [], ['lower', 'codigo']);
        $this->assertIndex($indexes, 'unidades_medida_nombre_lower_unique', 'unidades_medida', true, false, [], ['lower', 'nombre']);
        $this->assertIndex($indexes, 'unidades_medida_simbolo_lower_unique', 'unidades_medida', true, false, [], ['lower', 'simbolo']);
    }

    public function test_block_two_tables_have_no_foreign_keys(): void
    {
        $foreignKeys = DB::select(<<<'SQL'
            SELECT con.conname
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS rel ON rel.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = rel.relnamespace
             WHERE nsp.nspname = 'public'
               AND con.contype = 'f'
               AND rel.relname IN ('unidades_medida', 'categorias_articulo', 'metodos_pago', 'cajas')
            SQL);

        $this->assertSame([], $foreignKeys);
    }

    private function assertIndex(
        array $indexes,
        string $name,
        string $table,
        bool $unique,
        bool $primary,
        array $columns,
        array $expressionParts = [],
    ): void {
        $this->assertArrayHasKey($name, $indexes);

        $index = $indexes[$name];

        $this->assertSame($table, $index['table'], "Unexpected table for index {$name}.");
        $this->assertSame($unique, $index['unique'], "Unexpected uniqueness for index {$name}.");
        $this->assertSame($primary, $index['primary'], "Unexpected primary flag for index {$name}.");
        $this->assertSame($columns, $index['columns'], "Unexpected columns for index {$name}.");

        if ($expressionParts === []) {
            $this->assertNull($index['expression'], "Unexpected expression for index {$name}.");
        } else {
            $this->assertNotNull($index['expression'], "Missing expression for index {$name}.");
            $expression = strtolower($index['expression']);

            foreach ($expressionParts as $part) {
                $this->assertStringContainsString($part, $expression);
            }
        }

        $this->assertNull($index['predicate'], "Unexpected predicate for index {$name}.");
    }

    private function assertColumnDefault(?string $expected, ?string $actual, string $label): void
    {
        if ($expected === null) {
            $this->assertNull($actual, "Unexpected default for {$label}.");

            return;
        }

        if ($expected === 'sequence') {
            $this->assertStringContainsString('nextval(', $actual ?? '', "Missing sequence default for {$label}.");

            return;
        }

        if ($expected === 'zero') {
            $this->assertContains($actual, ['0', '0::smallint', "'0'::smallint"], "Unexpected zero default for {$label}.");

            return;
        }

        if ($expected === 'current_timestamp') {
            $this->assertSame('CURRENT_TIMESTAMP', $actual, "Unexpected timestamp default for {$label}.");

            return;
        }

        $this->assertSame($expected, $actual, "Unexpected default for {$label}.");
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function expectedCheckConstraints(): array
    {
        return [
            'cajas' => [
                'cajas_codigo_no_vacio_check',
                'cajas_codigo_sin_espacios_exteriores_check',
                'cajas_codigo_minusculas_check',
                'cajas_codigo_formato_check',
                'cajas_nombre_no_vacio_check',
                'cajas_nombre_sin_espacios_exteriores_check',
            ],
            'categorias_articulo' => [
                'categorias_articulo_codigo_no_vacio_check',
                'categorias_articulo_codigo_sin_espacios_exteriores_check',
                'categorias_articulo_codigo_minusculas_check',
                'categorias_articulo_codigo_formato_check',
                'categorias_articulo_nombre_no_vacio_check',
                'categorias_articulo_nombre_sin_espacios_exteriores_check',
            ],
            'metodos_pago' => [
                'metodos_pago_codigo_no_vacio_check',
                'metodos_pago_codigo_sin_espacios_exteriores_check',
                'metodos_pago_codigo_minusculas_check',
                'metodos_pago_codigo_formato_check',
                'metodos_pago_nombre_no_vacio_check',
                'metodos_pago_nombre_sin_espacios_exteriores_check',
                'metodos_pago_orden_presentacion_no_negativo_check',
            ],
            'unidades_medida' => [
                'unidades_medida_codigo_no_vacio_check',
                'unidades_medida_codigo_sin_espacios_exteriores_check',
                'unidades_medida_codigo_minusculas_check',
                'unidades_medida_codigo_formato_check',
                'unidades_medida_nombre_no_vacio_check',
                'unidades_medida_nombre_sin_espacios_exteriores_check',
                'unidades_medida_simbolo_no_vacio_check',
                'unidades_medida_simbolo_sin_espacios_exteriores_check',
                'unidades_medida_magnitud_valida_check',
            ],
        ];
    }

    private function expectedColumns(): array
    {
        return [
            'cajas' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 100),
                'descripcion' => $this->column('text', nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
                'updated_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
            ],
            'categorias_articulo' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 100),
                'descripcion' => $this->column('text', nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
                'updated_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
            ],
            'metodos_pago' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 100),
                'afecta_efectivo' => $this->column('bool', default: 'false'),
                'orden_presentacion' => $this->column('int2', default: 'zero'),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
                'updated_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
            ],
            'unidades_medida' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 100),
                'simbolo' => $this->column('varchar', length: 20),
                'magnitud' => $this->column('varchar', length: 20),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
                'updated_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
            ],
        ];
    }

    private function column(
        string $type,
        ?int $length = null,
        ?int $precision = null,
        bool $nullable = false,
        ?string $default = null,
    ): array {
        return compact('type', 'length', 'precision', 'nullable', 'default');
    }
}
