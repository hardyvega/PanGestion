<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepciones_mercaderia', function (Blueprint $table) {
            $table->id();
            $table->uuid('clave_idempotencia');
            $table->string('tipo_origen', 20);
            $table->foreignId('proveedor_id')->nullable();
            $table->foreignId('usuario_aportante_id')->nullable();
            $table->text('origen_detalle')->nullable();
            $table->foreignId('usuario_creador_id');
            $table->foreignId('usuario_confirmador_id')->nullable();
            $table->foreignId('usuario_anulador_id')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->timestampTz('ocurrido_en', 6)->useCurrent();
            $table->text('motivo_ajuste_ocurrido_en')->nullable();
            $table->timestampTz('confirmada_en', 6)->nullable();
            $table->timestampTz('anulada_en', 6)->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'proveedor_id',
                'recepciones_mercaderia_proveedor_id_foreign'
            )
                ->references('id')
                ->on('proveedores')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_aportante_id',
                'recepciones_mercaderia_usuario_aportante_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_creador_id',
                'recepciones_mercaderia_usuario_creador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_confirmador_id',
                'recepciones_mercaderia_usuario_confirmador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_anulador_id',
                'recepciones_mercaderia_usuario_anulador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'clave_idempotencia',
                'recepciones_mercaderia_clave_idempotencia_unique'
            );

            $table->index(
                ['estado', 'created_at', 'id'],
                'recepciones_mercaderia_estado_created_at_id_index'
            );

            $table->index(
                ['ocurrido_en', 'id'],
                'recepciones_mercaderia_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_creador_id', 'created_at', 'id'],
                'recepciones_mercaderia_creador_created_at_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recepciones_mercaderia
                ADD CONSTRAINT recepciones_mercaderia_tipo_origen_valido_check
                    CHECK (
                        tipo_origen IN ('proveedor', 'interno', 'otro_autorizado')
                    ),
                ADD CONSTRAINT recepciones_mercaderia_origen_consistente_check
                    CHECK (
                        (
                            tipo_origen = 'proveedor'
                            AND proveedor_id IS NOT NULL
                            AND usuario_aportante_id IS NULL
                            AND origen_detalle IS NULL
                        )
                        OR (
                            tipo_origen = 'interno'
                            AND proveedor_id IS NULL
                            AND usuario_aportante_id IS NOT NULL
                            AND origen_detalle IS NULL
                        )
                        OR (
                            tipo_origen = 'otro_autorizado'
                            AND proveedor_id IS NULL
                            AND usuario_aportante_id IS NULL
                            AND origen_detalle IS NOT NULL
                            AND btrim(origen_detalle) <> ''
                            AND origen_detalle = btrim(origen_detalle)
                        )
                    ),
                ADD CONSTRAINT recepciones_mercaderia_estado_valido_check
                    CHECK (
                        estado IN ('borrador', 'confirmada', 'anulada')
                    ),
                ADD CONSTRAINT recepciones_mercaderia_ciclo_vida_consistente_check
                    CHECK (
                        (
                            estado = 'borrador'
                            AND usuario_confirmador_id IS NULL
                            AND confirmada_en IS NULL
                            AND usuario_anulador_id IS NULL
                            AND anulada_en IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            estado = 'confirmada'
                            AND usuario_confirmador_id IS NOT NULL
                            AND confirmada_en IS NOT NULL
                            AND usuario_anulador_id IS NULL
                            AND anulada_en IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            estado = 'anulada'
                            AND usuario_confirmador_id IS NOT NULL
                            AND confirmada_en IS NOT NULL
                            AND usuario_anulador_id IS NOT NULL
                            AND anulada_en IS NOT NULL
                            AND motivo_anulacion IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT recepciones_mercaderia_ocurrido_en_consistente_check
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
                ADD CONSTRAINT recepciones_mercaderia_fechas_validas_check
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
                ADD CONSTRAINT recepciones_mercaderia_motivo_anulacion_valido_check
                    CHECK (
                        motivo_anulacion IS NULL
                        OR (
                            btrim(motivo_anulacion) <> ''
                            AND motivo_anulacion = btrim(motivo_anulacion)
                        )
                    ),
                ADD CONSTRAINT recepciones_mercaderia_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX recepciones_mercaderia_proveedor_ocurrido_id_index
             ON recepciones_mercaderia (proveedor_id, ocurrido_en, id)
             WHERE proveedor_id IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX recepciones_mercaderia_aportante_ocurrido_id_index
             ON recepciones_mercaderia (usuario_aportante_id, ocurrido_en, id)
             WHERE usuario_aportante_id IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX recepciones_mercaderia_confirmada_en_id_index
             ON recepciones_mercaderia (confirmada_en, id)
             WHERE confirmada_en IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX recepciones_mercaderia_anulada_en_id_index
             ON recepciones_mercaderia (anulada_en, id)
             WHERE anulada_en IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recepciones_mercaderia');
    }
};
