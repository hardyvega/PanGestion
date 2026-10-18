<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarifas_hora_trabajador', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trabajador_id');
            $table->decimal('valor_hora', 14, 2);
            $table->timestampTz('vigente_desde', 6);
            $table->foreignId('registrado_por_usuario_id');
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign('trabajador_id', 'tarifas_hora_trabajador_trabajador_id_foreign')
                ->references('id')->on('trabajadores')
                ->onUpdate('no action')->onDelete('restrict');
            $table->foreign('registrado_por_usuario_id', 'tarifas_hora_trabajador_registrado_por_usuario_id_foreign')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('restrict');
            $table->unique(['trabajador_id', 'vigente_desde'], 'tarifas_hora_trabajador_vigencia_unique');
            $table->index(['trabajador_id', 'vigente_desde', 'id'], 'tarifas_hora_trabajador_busqueda_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tarifas_hora_trabajador
                ADD CONSTRAINT tarifas_hora_trabajador_valor_hora_valido_check CHECK (
                    valor_hora > 0 AND valor_hora <> 'NaN'::numeric
                ),
                ADD CONSTRAINT tarifas_hora_trabajador_observacion_valida_check CHECK (
                    observacion IS NULL OR (
                        btrim(observacion) <> '' AND observacion = btrim(observacion)
                    )
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifas_hora_trabajador');
    }
};
