<?php

namespace Tests\Feature\Database\BlockSeven;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockSevenSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_block_seven_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('pedidos'));
        $this->assertTrue(Schema::hasTable('detalle_pedidos'));
        $this->assertTrue(Schema::hasTable('pagos_pedido'));
    }

    public function test_block_seven_columns_match_migrations(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT table_name, column_name, udt_name, character_maximum_length,
                   CASE WHEN udt_name = 'numeric' THEN numeric_precision END AS numeric_precision,
                   CASE WHEN udt_name = 'numeric' THEN numeric_scale END AS numeric_scale,
                   datetime_precision, is_nullable, column_default
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
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

        foreach (['total', 'saldo', 'estado_pago', 'total_pagado', 'venta_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('pedidos', $column));
        }

        foreach (['subtotal', 'stock_reservado', 'cantidad_reservada'] as $column) {
            $this->assertFalse(Schema::hasColumn('detalle_pedidos', $column));
        }

        $this->assertFalse(Schema::hasColumn('pagos_pedido', 'updated_at'));
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
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
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
            'detalle_pedidos' => ['name' => 'detalle_pedidos_pkey', 'columns' => ['id']],
            'pagos_pedido' => ['name' => 'pagos_pedido_pkey', 'columns' => ['id']],
            'pedidos' => ['name' => 'pedidos_pkey', 'columns' => ['id']],
        ], $actual);

        foreach (['detalle_pedidos', 'pagos_pedido', 'pedidos'] as $table) {
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
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
             ORDER BY tbl.relname, con.conname
            SQL);

        $actual = ['detalle_pedidos' => [], 'pagos_pedido' => [], 'pedidos' => []];
        $definitions = [];

        foreach ($rows as $row) {
            $this->assertTrue($this->postgresBoolean($row->validated), "Unvalidated CHECK {$row->constraint_name}.");
            $actual[$row->table_name][] = $row->constraint_name;
            $definitions[$row->constraint_name] = $row->definition;
        }

        $expected = [
            'detalle_pedidos' => [
                'detalle_pedidos_cantidad_valida_check',
                'detalle_pedidos_observacion_valida_check',
                'detalle_pedidos_precio_unitario_valido_check',
            ],
            'pagos_pedido' => [
                'pagos_pedido_efectivo_consistente_check',
                'pagos_pedido_monto_recibido_valido_check',
                'pagos_pedido_monto_valido_check',
                'pagos_pedido_observacion_valida_check',
                'pagos_pedido_tipo_valido_check',
                'pagos_pedido_vuelto_valido_check',
            ],
            'pedidos' => [
                'pedidos_estado_valido_check',
                'pedidos_eventos_finales_consistentes_check',
                'pedidos_observacion_valida_check',
            ],
        ];

        foreach ($expected as $table => $names) {
            sort($names);
            sort($actual[$table]);

            $this->assertSame($names, $actual[$table], "Unexpected CHECK constraints for {$table}.");
        }

        $receivedAmountDefinition = $definitions['pagos_pedido_monto_recibido_valido_check'];
        $this->assertStringContainsString('monto_recibido >= 0::numeric', $receivedAmountDefinition);
        $this->assertStringContainsString("monto_recibido <> 'NaN'::numeric", $receivedAmountDefinition);
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
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
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
            'pagos_pedido_clave_idempotencia_unique' => [
                'table' => 'pagos_pedido',
                'columns' => ['clave_idempotencia'],
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
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
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

        $this->assertIndex($indexes, 'detalle_pedidos_pkey', 'detalle_pedidos', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'detalle_pedidos_pedido_id_id_index', 'detalle_pedidos', ['pedido_id', 'id']);
        $this->assertIndex($indexes, 'detalle_pedidos_articulo_id_pedido_id_id_index', 'detalle_pedidos', ['articulo_id', 'pedido_id', 'id']);

        $this->assertIndex($indexes, 'pagos_pedido_pkey', 'pagos_pedido', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'pagos_pedido_clave_idempotencia_unique', 'pagos_pedido', ['clave_idempotencia'], unique: true);
        $this->assertIndex($indexes, 'pagos_pedido_pedido_id_ocurrido_en_id_index', 'pagos_pedido', ['pedido_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'pagos_pedido_sesion_caja_id_ocurrido_en_id_index', 'pagos_pedido', ['sesion_caja_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'pagos_pedido_metodo_pago_id_ocurrido_en_id_index', 'pagos_pedido', ['metodo_pago_id', 'ocurrido_en', 'id']);
        $this->assertIndex($indexes, 'pagos_pedido_usuario_id_ocurrido_en_id_index', 'pagos_pedido', ['usuario_id', 'ocurrido_en', 'id']);

        $this->assertIndex($indexes, 'pedidos_pkey', 'pedidos', ['id'], unique: true, primary: true);
        $this->assertIndex($indexes, 'pedidos_estado_created_at_id_index', 'pedidos', ['estado', 'created_at', 'id']);
        $this->assertIndex($indexes, 'pedidos_cliente_id_created_at_id_index', 'pedidos', ['cliente_id', 'created_at', 'id']);
        $this->assertIndex($indexes, 'pedidos_usuario_creador_id_created_at_id_index', 'pedidos', ['usuario_creador_id', 'created_at', 'id']);
        $this->assertIndex(
            $indexes,
            'pedidos_entrega_programada_en_id_index',
            'pedidos',
            ['entrega_programada_en', 'id'],
            predicate: 'entrega_programada_enisnotnull',
        );

        $expectedNames = [
            'detalle_pedidos_articulo_id_pedido_id_id_index',
            'detalle_pedidos_pedido_id_id_index',
            'detalle_pedidos_pkey',
            'pagos_pedido_clave_idempotencia_unique',
            'pagos_pedido_metodo_pago_id_ocurrido_en_id_index',
            'pagos_pedido_pedido_id_ocurrido_en_id_index',
            'pagos_pedido_pkey',
            'pagos_pedido_sesion_caja_id_ocurrido_en_id_index',
            'pagos_pedido_usuario_id_ocurrido_en_id_index',
            'pedidos_cliente_id_created_at_id_index',
            'pedidos_entrega_programada_en_id_index',
            'pedidos_estado_created_at_id_index',
            'pedidos_pkey',
            'pedidos_usuario_creador_id_created_at_id_index',
        ];
        $actualNames = array_keys($indexes);
        sort($expectedNames);
        sort($actualNames);
        $this->assertSame($expectedNames, $actualNames);

        $secondaryIndexes = array_filter(
            $indexes,
            static fn (array $index): bool => ! $index['primary'] && ! $index['unique'],
        );
        $this->assertCount(10, $secondaryIndexes);
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
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
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
            'detalle_pedidos_articulo_id_foreign' => [
                'table' => 'detalle_pedidos', 'columns' => ['articulo_id'],
                'target' => 'articulos', 'targetColumns' => ['id'],
            ],
            'detalle_pedidos_pedido_id_foreign' => [
                'table' => 'detalle_pedidos', 'columns' => ['pedido_id'],
                'target' => 'pedidos', 'targetColumns' => ['id'],
            ],
            'pagos_pedido_metodo_pago_id_foreign' => [
                'table' => 'pagos_pedido', 'columns' => ['metodo_pago_id'],
                'target' => 'metodos_pago', 'targetColumns' => ['id'],
            ],
            'pagos_pedido_pedido_id_foreign' => [
                'table' => 'pagos_pedido', 'columns' => ['pedido_id'],
                'target' => 'pedidos', 'targetColumns' => ['id'],
            ],
            'pagos_pedido_sesion_caja_id_foreign' => [
                'table' => 'pagos_pedido', 'columns' => ['sesion_caja_id'],
                'target' => 'sesiones_caja', 'targetColumns' => ['id'],
            ],
            'pagos_pedido_usuario_id_foreign' => [
                'table' => 'pagos_pedido', 'columns' => ['usuario_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
            'pedidos_cliente_id_foreign' => [
                'table' => 'pedidos', 'columns' => ['cliente_id'],
                'target' => 'clientes', 'targetColumns' => ['id'],
            ],
            'pedidos_usuario_creador_id_foreign' => [
                'table' => 'pedidos', 'columns' => ['usuario_creador_id'],
                'target' => 'usuarios', 'targetColumns' => ['id'],
            ],
        ], $actual);
    }

    public function test_block_seven_tables_have_no_user_defined_triggers(): void
    {
        $triggers = DB::select(<<<'SQL'
            SELECT tbl.relname AS table_name, trg.tgname AS trigger_name
              FROM pg_catalog.pg_trigger AS trg
              JOIN pg_catalog.pg_class AS tbl ON tbl.oid = trg.tgrelid
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
               AND NOT trg.tgisinternal
            SQL);

        $this->assertSame([], $triggers);
    }

    public function test_block_seven_tables_have_no_user_defined_rules(): void
    {
        $rules = DB::select(<<<'SQL'
            SELECT schemaname, tablename, rulename
              FROM pg_catalog.pg_rules
             WHERE schemaname = 'public'
               AND tablename IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
            SQL);

        $this->assertSame([], $rules);
    }

    public function test_block_seven_tables_have_no_row_level_security_policies(): void
    {
        $policies = DB::select(<<<'SQL'
            SELECT schemaname, tablename, policyname
              FROM pg_catalog.pg_policies
             WHERE schemaname = 'public'
               AND tablename IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
            SQL);
        $tables = DB::select(<<<'SQL'
            SELECT relname AS table_name, relrowsecurity AS row_security,
                   relforcerowsecurity AS force_row_security
              FROM pg_catalog.pg_class AS tbl
              JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = tbl.relnamespace
             WHERE nsp.nspname = 'public'
               AND tbl.relname IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
             ORDER BY tbl.relname
            SQL);

        $this->assertSame([], $policies);
        $this->assertCount(3, $tables);

        foreach ($tables as $table) {
            $this->assertFalse($this->postgresBoolean($table->row_security), $table->table_name);
            $this->assertFalse($this->postgresBoolean($table->force_row_security), $table->table_name);
        }
    }

    public function test_block_seven_tables_have_no_generated_columns(): void
    {
        $generatedColumns = DB::select(<<<'SQL'
            SELECT table_name, column_name, is_generated
              FROM information_schema.columns
             WHERE table_schema = 'public'
               AND table_name IN ('pedidos', 'detalle_pedidos', 'pagos_pedido')
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
            'detalle_pedidos' => [
                'id' => $this->column('int8', default: 'sequence'),
                'pedido_id' => $this->column('int8'),
                'articulo_id' => $this->column('int8'),
                'cantidad' => $this->column('numeric', numericPrecision: 14, scale: 3),
                'precio_unitario' => $this->column('numeric', numericPrecision: 14, scale: 2),
                'observacion' => $this->column('text', nullable: true),
                'created_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
                'updated_at' => $this->column('timestamptz', datetimePrecision: 6, default: 'CURRENT_TIMESTAMP'),
            ],
            'pagos_pedido' => [
                'id' => $this->column('int8', default: 'sequence'),
                'pedido_id' => $this->column('int8'),
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
            'pedidos' => [
                'id' => $this->column('int8', default: 'sequence'),
                'cliente_id' => $this->column('int8'),
                'usuario_creador_id' => $this->column('int8'),
                'estado' => $this->column('varchar', length: 20, default: "'pendiente'::character varying"),
                'entrega_programada_en' => $this->column('timestamptz', datetimePrecision: 6, nullable: true),
                'entregado_en' => $this->column('timestamptz', datetimePrecision: 6, nullable: true),
                'cancelado_en' => $this->column('timestamptz', datetimePrecision: 6, nullable: true),
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
