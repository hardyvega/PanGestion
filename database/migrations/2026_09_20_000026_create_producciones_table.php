<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receta_id');
            $table->foreignId('usuario_responsable_id');
            $table->foreignId('usuario_creador_id');
            $table->foreignId('usuario_confirmador_id')->nullable();
            $table->foreignId('usuario_anulador_id')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->timestampTz('confirmada_en', 6)->nullable();
            $table->timestampTz('anulada_en', 6)->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'receta_id',
                'producciones_receta_id_foreign'
            )
                ->references('id')
                ->on('recetas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_responsable_id',
                'producciones_usuario_responsable_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_creador_id',
                'producciones_usuario_creador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_confirmador_id',
                'producciones_usuario_confirmador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_anulador_id',
                'producciones_usuario_anulador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['created_at', 'id'],
                'producciones_created_at_id_index'
            );

            $table->index(
                ['estado', 'created_at', 'id'],
                'producciones_estado_created_at_id_index'
            );

            $table->index(
                ['receta_id', 'created_at', 'id'],
                'producciones_receta_id_created_at_id_index'
            );

            $table->index(
                ['usuario_responsable_id', 'created_at', 'id'],
                'producciones_usuario_responsable_id_created_at_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE producciones
                ADD CONSTRAINT producciones_estado_valido_check
                    CHECK (
                        estado IN ('borrador', 'confirmada', 'anulada')
                    ),
                ADD CONSTRAINT producciones_ciclo_vida_consistente_check
                    CHECK (
                        (
                            estado = 'borrador'
                            AND confirmada_en IS NULL
                            AND usuario_confirmador_id IS NULL
                            AND anulada_en IS NULL
                            AND usuario_anulador_id IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            estado = 'confirmada'
                            AND confirmada_en IS NOT NULL
                            AND usuario_confirmador_id IS NOT NULL
                            AND anulada_en IS NULL
                            AND usuario_anulador_id IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            estado = 'anulada'
                            AND confirmada_en IS NOT NULL
                            AND usuario_confirmador_id IS NOT NULL
                            AND anulada_en IS NOT NULL
                            AND usuario_anulador_id IS NOT NULL
                            AND motivo_anulacion IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT producciones_fechas_validas_check
                    CHECK (
                        (
                            confirmada_en IS NULL
                            OR confirmada_en >= created_at
                        )
                        AND (
                            anulada_en IS NULL
                            OR (
                                confirmada_en IS NOT NULL
                                AND anulada_en >= confirmada_en
                            )
                        )
                    ),
                ADD CONSTRAINT producciones_motivo_anulacion_valido_check
                    CHECK (
                        motivo_anulacion IS NULL
                        OR (
                            btrim(motivo_anulacion) <> ''
                            AND motivo_anulacion = btrim(motivo_anulacion)
                        )
                    ),
                ADD CONSTRAINT producciones_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX producciones_confirmada_en_id_index
             ON producciones (confirmada_en, id)
             WHERE confirmada_en IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('producciones');
    }
};
