<?php

namespace Tests\Feature\Database\BlockOne;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockOneSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_one_tables_exist_and_unapproved_tables_do_not_exist(): void
    {
        foreach (['migrations', 'roles', 'permisos', 'usuarios', 'rol_permiso'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table [{$table}] does not exist.");
        }

        foreach (['users', 'usuario_rol', 'cache', 'jobs', 'sessions'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected table [{$table}] exists.");
        }
    }

    public function test_role_permission_contains_only_expected_columns(): void
    {
        $columns = Schema::getColumnListing('rol_permiso');
        sort($columns);

        $this->assertSame(['permiso_id', 'rol_id'], $columns);
        $this->assertFalse(Schema::hasColumn('rol_permiso', 'id'));
        $this->assertFalse(Schema::hasColumn('rol_permiso', 'created_at'));
        $this->assertFalse(Schema::hasColumn('rol_permiso', 'updated_at'));
    }

    public function test_column_metadata_matches_the_migrations(): void
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
               AND table_name IN ('roles', 'permisos', 'usuarios', 'rol_permiso')
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

    public function test_named_check_constraints_are_present_without_extras(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT rel.relname AS table_name,
                   con.conname AS constraint_name
              FROM pg_catalog.pg_constraint AS con
              JOIN pg_catalog.pg_class AS rel ON rel.oid = con.conrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = rel.relnamespace
             WHERE nsp.nspname = 'public'
               AND con.contype = 'c'
               AND rel.relname IN ('roles', 'permisos', 'usuarios', 'rol_permiso')
             ORDER BY rel.relname, con.conname
            SQL);

        $actual = [
            'roles' => [],
            'permisos' => [],
            'usuarios' => [],
            'rol_permiso' => [],
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

    public function test_postgresql_indexes_match_the_approved_semantics(): void
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
               AND tbl.relname IN ('roles', 'permisos', 'usuarios', 'rol_permiso')
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
            'permisos_codigo_lower_unique',
            'permisos_modulo_activo_index',
            'permisos_pkey',
            'rol_permiso_permiso_id_index',
            'rol_permiso_pkey',
            'roles_codigo_lower_unique',
            'roles_pkey',
            'usuarios_correo_lower_unique',
            'usuarios_nombre_usuario_lower_unique',
            'usuarios_pkey',
            'usuarios_rol_id_index',
        ];
        $actualNames = array_keys($indexes);
        sort($expectedNames);
        sort($actualNames);

        $this->assertSame($expectedNames, $actualNames);

        $this->assertIndex($indexes, 'roles_pkey', 'roles', true, true, ['id']);
        $this->assertIndex($indexes, 'roles_codigo_lower_unique', 'roles', true, false, [], ['lower', 'codigo']);
        $this->assertIndex($indexes, 'permisos_pkey', 'permisos', true, true, ['id']);
        $this->assertIndex($indexes, 'permisos_codigo_lower_unique', 'permisos', true, false, [], ['lower', 'codigo']);
        $this->assertIndex($indexes, 'permisos_modulo_activo_index', 'permisos', false, false, ['modulo', 'activo']);
        $this->assertIndex($indexes, 'usuarios_pkey', 'usuarios', true, true, ['id']);
        $this->assertIndex($indexes, 'usuarios_rol_id_index', 'usuarios', false, false, ['rol_id']);
        $this->assertIndex($indexes, 'usuarios_nombre_usuario_lower_unique', 'usuarios', true, false, [], ['lower', 'nombre_usuario']);
        $this->assertIndex(
            $indexes,
            'usuarios_correo_lower_unique',
            'usuarios',
            true,
            false,
            [],
            ['lower', 'correo'],
            ['correo', 'is not null'],
        );
        $this->assertIndex($indexes, 'rol_permiso_pkey', 'rol_permiso', true, true, ['rol_id', 'permiso_id']);
        $this->assertIndex($indexes, 'rol_permiso_permiso_id_index', 'rol_permiso', false, false, ['permiso_id']);
    }

    public function test_foreign_key_rules_match_the_approved_definitions(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT tc.table_name,
                   tc.constraint_name,
                   kcu.column_name,
                   ccu.table_name AS referenced_table,
                   ccu.column_name AS referenced_column,
                   rc.update_rule,
                   rc.delete_rule
              FROM information_schema.table_constraints AS tc
              JOIN information_schema.key_column_usage AS kcu
                ON kcu.constraint_catalog = tc.constraint_catalog
               AND kcu.constraint_schema = tc.constraint_schema
               AND kcu.constraint_name = tc.constraint_name
              JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_catalog = tc.constraint_catalog
               AND ccu.constraint_schema = tc.constraint_schema
               AND ccu.constraint_name = tc.constraint_name
              JOIN information_schema.referential_constraints AS rc
                ON rc.constraint_catalog = tc.constraint_catalog
               AND rc.constraint_schema = tc.constraint_schema
               AND rc.constraint_name = tc.constraint_name
             WHERE tc.table_schema = 'public'
               AND tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_name IN ('usuarios', 'rol_permiso')
             ORDER BY tc.constraint_name
            SQL);

        $actual = [];

        foreach ($rows as $row) {
            $actual[$row->constraint_name] = [
                'table' => $row->table_name,
                'column' => $row->column_name,
                'references' => "{$row->referenced_table}.{$row->referenced_column}",
                'update' => $row->update_rule,
                'delete' => $row->delete_rule,
            ];
        }

        $expected = [
            'rol_permiso_permiso_id_foreign' => [
                'table' => 'rol_permiso',
                'column' => 'permiso_id',
                'references' => 'permisos.id',
                'update' => 'NO ACTION',
                'delete' => 'RESTRICT',
            ],
            'rol_permiso_rol_id_foreign' => [
                'table' => 'rol_permiso',
                'column' => 'rol_id',
                'references' => 'roles.id',
                'update' => 'NO ACTION',
                'delete' => 'RESTRICT',
            ],
            'usuarios_rol_id_foreign' => [
                'table' => 'usuarios',
                'column' => 'rol_id',
                'references' => 'roles.id',
                'update' => 'NO ACTION',
                'delete' => 'RESTRICT',
            ],
        ];

        $this->assertSame($expected, $actual);
    }

    private function assertIndex(
        array $indexes,
        string $name,
        string $table,
        bool $unique,
        bool $primary,
        array $columns,
        array $expressionParts = [],
        array $predicateParts = [],
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

        if ($predicateParts === []) {
            $this->assertNull($index['predicate'], "Unexpected predicate for index {$name}.");
        } else {
            $this->assertNotNull($index['predicate'], "Missing predicate for index {$name}.");
            $predicate = strtolower($index['predicate']);

            foreach ($predicateParts as $part) {
                $this->assertStringContainsString($part, $predicate);
            }
        }
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

        if ($expected === 'true') {
            $this->assertSame('true', $actual, "Unexpected boolean default for {$label}.");

            return;
        }

        $this->assertSame('CURRENT_TIMESTAMP', $actual, "Unexpected timestamp default for {$label}.");
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function expectedCheckConstraints(): array
    {
        return [
            'roles' => [
                'roles_codigo_no_vacio_check',
                'roles_codigo_sin_espacios_exteriores_check',
                'roles_codigo_minusculas_check',
                'roles_codigo_formato_check',
                'roles_nombre_no_vacio_check',
                'roles_nombre_sin_espacios_exteriores_check',
            ],
            'permisos' => [
                'permisos_codigo_no_vacio_check',
                'permisos_codigo_sin_espacios_exteriores_check',
                'permisos_codigo_minusculas_check',
                'permisos_codigo_formato_check',
                'permisos_nombre_no_vacio_check',
                'permisos_nombre_sin_espacios_exteriores_check',
                'permisos_modulo_no_vacio_check',
                'permisos_modulo_sin_espacios_exteriores_check',
                'permisos_modulo_minusculas_check',
                'permisos_modulo_formato_check',
            ],
            'usuarios' => [
                'usuarios_nombre_no_vacio_check',
                'usuarios_nombre_sin_espacios_exteriores_check',
                'usuarios_apellido_no_vacio_check',
                'usuarios_apellido_sin_espacios_exteriores_check',
                'usuarios_nombre_usuario_no_vacio_check',
                'usuarios_nombre_usuario_sin_espacios_check',
                'usuarios_nombre_usuario_formato_check',
                'usuarios_correo_valido_check',
            ],
            'rol_permiso' => [],
        ];
    }

    private function expectedColumns(): array
    {
        return [
            'permisos' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 100),
                'nombre' => $this->column('varchar', length: 150),
                'modulo' => $this->column('varchar', length: 50),
                'descripcion' => $this->column('text', nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
                'updated_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
            ],
            'rol_permiso' => [
                'rol_id' => $this->column('int8'),
                'permiso_id' => $this->column('int8'),
            ],
            'roles' => [
                'id' => $this->column('int8', default: 'sequence'),
                'codigo' => $this->column('varchar', length: 50),
                'nombre' => $this->column('varchar', length: 100),
                'descripcion' => $this->column('text', nullable: true),
                'activo' => $this->column('bool', default: 'true'),
                'created_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
                'updated_at' => $this->column('timestamptz', precision: 6, default: 'current_timestamp'),
            ],
            'usuarios' => [
                'id' => $this->column('int8', default: 'sequence'),
                'rol_id' => $this->column('int8'),
                'nombre' => $this->column('varchar', length: 100),
                'apellido' => $this->column('varchar', length: 100),
                'nombre_usuario' => $this->column('varchar', length: 50),
                'correo' => $this->column('varchar', length: 254, nullable: true),
                'password' => $this->column('varchar', length: 255),
                'activo' => $this->column('bool', default: 'true'),
                'ultimo_acceso' => $this->column('timestamptz', precision: 6, nullable: true),
                'remember_token' => $this->column('varchar', length: 100, nullable: true),
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
