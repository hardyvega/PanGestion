<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salidas_meson', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detalle_produccion_id');
            $table->foreignId('usuario_responsable_id');
            $table->foreignId('usuario_registrador_id');
            $table->foreignId('usuario_anulador_id')->nullable();
            $table->foreignId('salida_reemplazada_id')->nullable();
            $table->uuid('clave_idempotencia');
            $table->decimal('cantidad', 14, 3);
            $table->timestampTz('ocurrido_en', 6)->useCurrent();
            $table->text('motivo_ajuste_ocurrido_en')->nullable();
            $table->timestampTz('anulada_en', 6)->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'detalle_produccion_id',
                'salidas_meson_detalle_produccion_id_foreign'
            )
                ->references('id')
                ->on('detalle_producciones')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_responsable_id',
                'salidas_meson_usuario_responsable_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_registrador_id',
                'salidas_meson_usuario_registrador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_anulador_id',
                'salidas_meson_usuario_anulador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'salida_reemplazada_id',
                'salidas_meson_salida_reemplazada_id_foreign'
            )
                ->references('id')
                ->on('salidas_meson')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'clave_idempotencia',
                'salidas_meson_clave_idempotencia_unique'
            );

            $table->unique(
                'salida_reemplazada_id',
                'salidas_meson_salida_reemplazada_id_unique'
            );

            $table->index(
                ['detalle_produccion_id', 'ocurrido_en', 'id'],
                'salidas_meson_detalle_produccion_id_ocurrido_en_id_index'
            );

            $table->index(
                ['ocurrido_en', 'id'],
                'salidas_meson_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_responsable_id', 'ocurrido_en', 'id'],
                'salidas_meson_usuario_responsable_id_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_registrador_id', 'ocurrido_en', 'id'],
                'salidas_meson_usuario_registrador_id_ocurrido_en_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE salidas_meson
                ADD CONSTRAINT salidas_meson_cantidad_valida_check
                    CHECK (
                        cantidad > 0
                        AND cantidad <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT salidas_meson_ocurrido_en_consistente_check
                    CHECK (
                        (
                            motivo_ajuste_ocurrido_en IS NULL
                            AND ocurrido_en = created_at
                        )
                        OR (
                            motivo_ajuste_ocurrido_en IS NOT NULL
                            AND btrim(motivo_ajuste_ocurrido_en) <> ''
                            AND motivo_ajuste_ocurrido_en = btrim(motivo_ajuste_ocurrido_en)
                            AND ocurrido_en < created_at
                        )
                    ),
                ADD CONSTRAINT salidas_meson_anulacion_consistente_check
                    CHECK (
                        (
                            anulada_en IS NULL
                            AND usuario_anulador_id IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            anulada_en IS NOT NULL
                            AND usuario_anulador_id IS NOT NULL
                            AND motivo_anulacion IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT salidas_meson_fechas_validas_check
                    CHECK (
                        anulada_en IS NULL
                        OR anulada_en >= created_at
                    ),
                ADD CONSTRAINT salidas_meson_motivo_anulacion_valido_check
                    CHECK (
                        motivo_anulacion IS NULL
                        OR (
                            btrim(motivo_anulacion) <> ''
                            AND motivo_anulacion = btrim(motivo_anulacion)
                        )
                    ),
                ADD CONSTRAINT salidas_meson_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    ),
                ADD CONSTRAINT salidas_meson_reemplazo_distinto_check
                    CHECK (
                        salida_reemplazada_id IS NULL
                        OR salida_reemplazada_id <> id
                    )
            SQL);

        DB::statement(
            'CREATE INDEX salidas_meson_usuario_anulador_id_index
             ON salidas_meson (usuario_anulador_id)
             WHERE usuario_anulador_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_meson');
    }
};
