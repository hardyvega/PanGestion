<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mermas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->foreignId('usuario_registrador_id');
            $table->foreignId('usuario_anulador_id')->nullable();
            $table->foreignId('merma_reemplazada_id')->nullable();
            $table->uuid('clave_idempotencia');
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->string('tipo_merma', 30);
            $table->text('motivo_detalle')->nullable();
            $table->decimal('cantidad', 14, 3);
            $table->timestampTz('ocurrido_en', 6)->useCurrent();
            $table->text('motivo_ajuste_ocurrido_en')->nullable();
            $table->timestampTz('anulada_en', 6)->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'mermas_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_registrador_id',
                'mermas_usuario_registrador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_anulador_id',
                'mermas_usuario_anulador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'merma_reemplazada_id',
                'mermas_merma_reemplazada_id_foreign'
            )
                ->references('id')
                ->on('mermas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'clave_idempotencia',
                'mermas_clave_idempotencia_unique'
            );

            $table->unique(
                'merma_reemplazada_id',
                'mermas_merma_reemplazada_id_unique'
            );

            $table->index(
                ['articulo_id', 'ocurrido_en', 'id'],
                'mermas_articulo_id_ocurrido_en_id_index'
            );

            $table->index(
                ['ocurrido_en', 'id'],
                'mermas_ocurrido_en_id_index'
            );

            $table->index(
                ['tipo_merma', 'ocurrido_en', 'id'],
                'mermas_tipo_merma_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_registrador_id', 'ocurrido_en', 'id'],
                'mermas_usuario_registrador_id_ocurrido_en_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mermas
                ADD CONSTRAINT mermas_articulo_sku_snapshot_valido_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT mermas_articulo_nombre_snapshot_valido_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT mermas_unidad_codigo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT mermas_unidad_simbolo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT mermas_tipo_valido_check
                    CHECK (
                        tipo_merma IN (
                            'vencimiento',
                            'danio',
                            'error_produccion',
                            'contaminacion',
                            'otro'
                        )
                    ),
                ADD CONSTRAINT mermas_cantidad_valida_check
                    CHECK (
                        cantidad > 0
                        AND cantidad <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT mermas_motivo_detalle_consistente_check
                    CHECK (
                        (
                            tipo_merma = 'otro'
                            AND motivo_detalle IS NOT NULL
                            AND btrim(motivo_detalle) <> ''
                            AND motivo_detalle = btrim(motivo_detalle)
                        )
                        OR (
                            tipo_merma <> 'otro'
                            AND (
                                motivo_detalle IS NULL
                                OR (
                                    btrim(motivo_detalle) <> ''
                                    AND motivo_detalle = btrim(motivo_detalle)
                                )
                            )
                        )
                    ),
                ADD CONSTRAINT mermas_ocurrido_en_consistente_check
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
                ADD CONSTRAINT mermas_anulacion_consistente_check
                    CHECK (
                        (
                            usuario_anulador_id IS NULL
                            AND anulada_en IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            usuario_anulador_id IS NOT NULL
                            AND anulada_en IS NOT NULL
                            AND motivo_anulacion IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT mermas_fecha_anulacion_valida_check
                    CHECK (
                        anulada_en IS NULL
                        OR anulada_en >= created_at
                    ),
                ADD CONSTRAINT mermas_motivo_anulacion_valido_check
                    CHECK (
                        motivo_anulacion IS NULL
                        OR (
                            btrim(motivo_anulacion) <> ''
                            AND motivo_anulacion = btrim(motivo_anulacion)
                        )
                    ),
                ADD CONSTRAINT mermas_reemplazo_distinto_check
                    CHECK (
                        merma_reemplazada_id IS NULL
                        OR merma_reemplazada_id <> id
                    )
            SQL);

        DB::statement(
            'CREATE INDEX mermas_usuario_anulador_id_index
             ON mermas (usuario_anulador_id)
             WHERE usuario_anulador_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mermas');
    }
};
