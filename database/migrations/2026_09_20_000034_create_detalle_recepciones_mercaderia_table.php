<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_recepciones_mercaderia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recepcion_mercaderia_id');
            $table->foreignId('articulo_id');
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->decimal('cantidad', 14, 3)->nullable();
            $table->decimal('costo_unitario', 14, 2)->nullable();
            $table->text('motivo_costo_cero')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'recepcion_mercaderia_id',
                'detalle_recepciones_mercaderia_recepcion_id_foreign'
            )
                ->references('id')
                ->on('recepciones_mercaderia')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_id',
                'detalle_recepciones_mercaderia_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['recepcion_mercaderia_id', 'articulo_id'],
                'detalle_recepciones_mercaderia_recepcion_articulo_unique'
            );

            $table->index(
                ['articulo_id', 'recepcion_mercaderia_id', 'id'],
                'detalle_recepciones_mercaderia_articulo_recepcion_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_recepciones_mercaderia
                ADD CONSTRAINT detalle_recepciones_mercaderia_sku_snapshot_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_recepciones_mercaderia_nombre_snapshot_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT detalle_recepciones_mercaderia_unidad_codigo_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_recepciones_mercaderia_unidad_simbolo_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT detalle_recepciones_mercaderia_cantidad_valida_check
                    CHECK (
                        cantidad IS NULL
                        OR (
                            cantidad > 0
                            AND cantidad <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT detalle_recepciones_mercaderia_costo_valido_check
                    CHECK (
                        costo_unitario IS NULL
                        OR (
                            costo_unitario >= 0
                            AND costo_unitario <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT detalle_recepciones_mercaderia_motivo_costo_cero_check
                    CHECK (
                        (
                            (
                                costo_unitario IS NULL
                                AND motivo_costo_cero IS NULL
                            )
                            OR (
                                costo_unitario = 0
                                AND motivo_costo_cero IS NOT NULL
                                AND btrim(motivo_costo_cero) <> ''
                                AND motivo_costo_cero = btrim(motivo_costo_cero)
                            )
                            OR (
                                costo_unitario > 0
                                AND motivo_costo_cero IS NULL
                            )
                        ) IS TRUE
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_recepciones_mercaderia');
    }
};
