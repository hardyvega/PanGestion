<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumos_produccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id');
            $table->foreignId('detalle_receta_id')->nullable();
            $table->foreignId('articulo_componente_id');
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->decimal('cantidad_teorica', 14, 3)->nullable();
            $table->decimal('cantidad_real', 14, 3)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'produccion_id',
                'consumos_produccion_produccion_id_foreign'
            )
                ->references('id')
                ->on('producciones')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'detalle_receta_id',
                'consumos_produccion_detalle_receta_id_foreign'
            )
                ->references('id')
                ->on('detalle_recetas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_componente_id',
                'consumos_produccion_articulo_componente_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['produccion_id', 'id'],
                'consumos_produccion_produccion_id_id_index'
            );

            $table->index(
                ['articulo_componente_id', 'produccion_id', 'id'],
                'consumos_produccion_componente_produccion_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE consumos_produccion
                ADD CONSTRAINT consumos_produccion_articulo_sku_snapshot_valido_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT consumos_produccion_articulo_nombre_snapshot_valido_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT consumos_produccion_unidad_codigo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT consumos_produccion_unidad_simbolo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT consumos_produccion_origen_cantidad_teorica_consistente_check
                    CHECK (
                        (
                            detalle_receta_id IS NOT NULL
                            AND cantidad_teorica IS NOT NULL
                            AND cantidad_teorica > 0
                            AND cantidad_teorica <> 'NaN'::numeric
                        )
                        OR (
                            detalle_receta_id IS NULL
                            AND cantidad_teorica IS NULL
                        )
                    ),
                ADD CONSTRAINT consumos_produccion_cantidad_real_valida_check
                    CHECK (
                        cantidad_real IS NULL
                        OR (
                            cantidad_real >= 0
                            AND cantidad_real <> 'NaN'::numeric
                        )
                    )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX consumos_produccion_detalle_receta_id_produccion_id_unique
             ON consumos_produccion (detalle_receta_id, produccion_id)
             WHERE detalle_receta_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX consumos_produccion_produccion_articulo_extra_unique
             ON consumos_produccion (produccion_id, articulo_componente_id)
             WHERE detalle_receta_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('consumos_produccion');
    }
};
