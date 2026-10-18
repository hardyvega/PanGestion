<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jornadas_trabajo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trabajador_id');
            $table->foreignId('tarifa_hora_trabajador_id');
            $table->string('estado', 20)->default('abierta');
            $table->timestampTz('iniciada_en', 6);
            $table->timestampTz('finalizada_en', 6)->nullable();
            $table->decimal('valor_hora_snapshot', 14, 2);
            $table->unsignedInteger('minutos_trabajados')->nullable();
            $table->decimal('monto_calculado', 14, 2)->nullable();
            $table->foreignId('usuario_inicio_id');
            $table->foreignId('usuario_cierre_id')->nullable();
            $table->timestampTz('anulada_en', 6)->nullable();
            $table->foreignId('usuario_anulador_id')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->uuid('clave_idempotencia_inicio');
            $table->uuid('clave_idempotencia_cierre')->nullable();
            $table->uuid('clave_idempotencia_anulacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign('trabajador_id', 'jornadas_trabajo_trabajador_id_foreign')
                ->references('id')->on('trabajadores')->onUpdate('no action')->onDelete('restrict');
            $table->foreign('tarifa_hora_trabajador_id', 'jornadas_trabajo_tarifa_hora_trabajador_id_foreign')
                ->references('id')->on('tarifas_hora_trabajador')->onUpdate('no action')->onDelete('restrict');
            $table->foreign('usuario_inicio_id', 'jornadas_trabajo_usuario_inicio_id_foreign')
                ->references('id')->on('usuarios')->onUpdate('no action')->onDelete('restrict');
            $table->foreign('usuario_cierre_id', 'jornadas_trabajo_usuario_cierre_id_foreign')
                ->references('id')->on('usuarios')->onUpdate('no action')->onDelete('restrict');
            $table->foreign('usuario_anulador_id', 'jornadas_trabajo_usuario_anulador_id_foreign')
                ->references('id')->on('usuarios')->onUpdate('no action')->onDelete('restrict');
            $table->unique('clave_idempotencia_inicio', 'jornadas_trabajo_clave_inicio_unique');
            $table->unique('clave_idempotencia_cierre', 'jornadas_trabajo_clave_cierre_unique');
            $table->unique('clave_idempotencia_anulacion', 'jornadas_trabajo_clave_anulacion_unique');
            $table->index(['trabajador_id', 'iniciada_en', 'id'], 'jornadas_trabajo_trabajador_inicio_index');
            $table->index(['estado', 'iniciada_en', 'id'], 'jornadas_trabajo_estado_inicio_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE jornadas_trabajo
                ADD CONSTRAINT jornadas_trabajo_estado_valido_check CHECK (
                    estado IN ('abierta', 'cerrada', 'anulada')
                ),
                ADD CONSTRAINT jornadas_trabajo_datos_estado_check CHECK (
                    (
                        estado = 'abierta'
                        AND finalizada_en IS NULL
                        AND minutos_trabajados IS NULL
                        AND monto_calculado IS NULL
                        AND usuario_cierre_id IS NULL
                        AND anulada_en IS NULL
                        AND usuario_anulador_id IS NULL
                        AND motivo_anulacion IS NULL
                    ) OR (
                        estado = 'cerrada'
                        AND finalizada_en IS NOT NULL
                        AND minutos_trabajados IS NOT NULL
                        AND monto_calculado IS NOT NULL
                        AND usuario_cierre_id IS NOT NULL
                        AND anulada_en IS NULL
                        AND usuario_anulador_id IS NULL
                        AND motivo_anulacion IS NULL
                    ) OR (
                        estado = 'anulada'
                        AND anulada_en IS NOT NULL
                        AND usuario_anulador_id IS NOT NULL
                        AND motivo_anulacion IS NOT NULL
                    )
                ),
                ADD CONSTRAINT jornadas_trabajo_fechas_validas_check CHECK (
                    finalizada_en IS NULL OR finalizada_en >= iniciada_en
                ),
                ADD CONSTRAINT jornadas_trabajo_duracion_maxima_check CHECK (
                    finalizada_en IS NULL OR finalizada_en <= iniciada_en + INTERVAL '24 hours'
                ),
                ADD CONSTRAINT jornadas_trabajo_minutos_validos_check CHECK (
                    minutos_trabajados IS NULL OR minutos_trabajados BETWEEN 0 AND 1440
                ),
                ADD CONSTRAINT jornadas_trabajo_valor_hora_valido_check CHECK (
                    valor_hora_snapshot > 0 AND valor_hora_snapshot <> 'NaN'::numeric
                ),
                ADD CONSTRAINT jornadas_trabajo_monto_valido_check CHECK (
                    monto_calculado IS NULL OR (
                        monto_calculado >= 0 AND monto_calculado <> 'NaN'::numeric
                    )
                ),
                ADD CONSTRAINT jornadas_trabajo_anulacion_valida_check CHECK (
                    (
                        estado = 'anulada'
                        AND btrim(motivo_anulacion) <> ''
                        AND motivo_anulacion = btrim(motivo_anulacion)
                    ) OR estado <> 'anulada'
                )
            SQL);

        DB::statement("CREATE UNIQUE INDEX jornadas_trabajo_trabajador_abierta_unique ON jornadas_trabajo (trabajador_id) WHERE estado = 'abierta'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS jornadas_trabajo_trabajador_abierta_unique');
        Schema::dropIfExists('jornadas_trabajo');
    }
};
