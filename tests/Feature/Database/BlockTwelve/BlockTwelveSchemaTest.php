<?php

declare(strict_types=1);

namespace Tests\Feature\Database\BlockTwelve;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlockTwelveSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_b12_creates_exactly_the_three_append_only_tables_and_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('trabajadores'));
        $this->assertTrue(Schema::hasTable('tarifas_hora_trabajador'));
        $this->assertTrue(Schema::hasTable('jornadas_trabajo'));
        $this->assertTrue(Schema::hasColumns('trabajadores', [
            'usuario_id', 'codigo', 'nombres', 'apellidos', 'activo', 'fecha_ingreso', 'fecha_termino',
        ]));
        $this->assertTrue(Schema::hasColumns('tarifas_hora_trabajador', [
            'trabajador_id', 'valor_hora', 'vigente_desde', 'registrado_por_usuario_id', 'observacion',
        ]));
        $this->assertTrue(Schema::hasColumns('jornadas_trabajo', [
            'trabajador_id', 'tarifa_hora_trabajador_id', 'estado', 'iniciada_en', 'finalizada_en',
            'valor_hora_snapshot', 'minutos_trabajados', 'monto_calculado',
            'clave_idempotencia_inicio', 'clave_idempotencia_cierre', 'clave_idempotencia_anulacion',
        ]));
    }

    public function test_open_journey_partial_unique_index_and_enum_checks_exist(): void
    {
        $index = DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'jornadas_trabajo' AND indexname = 'jornadas_trabajo_trabajador_abierta_unique'");
        $this->assertCount(1, $index);
        $this->assertStringContainsString("'abierta'", $index[0]->indexdef);

        $checks = DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'jornadas_trabajo'::regclass AND contype = 'c'");
        $names = array_map(static fn (object $row): string => $row->conname, $checks);
        $this->assertContains('jornadas_trabajo_estado_valido_check', $names);
        $this->assertContains('jornadas_trabajo_duracion_maxima_check', $names);
    }
}
