<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_conteos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conteo_inventario_id');
            $table->foreignId('articulo_id');
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->decimal('cantidad_contada', 14, 3)->nullable();
            $table->timestampTz('contada_en', 6)->nullable();
            $table->decimal('stock_teorico_snapshot', 14, 3)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'conteo_inventario_id',
                'detalle_conteos_inventario_conteo_inventario_id_foreign'
            )
                ->references('id')
                ->on('conteos_inventario')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_id',
                'detalle_conteos_inventario_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['conteo_inventario_id', 'articulo_id'],
                'detalle_conteos_inventario_conteo_articulo_unique'
            );

            $table->index(
                ['articulo_id', 'conteo_inventario_id', 'id'],
                'detalle_conteos_inventario_articulo_conteo_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_conteos_inventario
                ADD CONSTRAINT detalle_conteos_inventario_articulo_sku_snapshot_valido_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_conteos_inventario_nombre_snapshot_valido_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT detalle_conteos_inventario_unidad_codigo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_conteos_inventario_unidad_simbolo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT detalle_conteos_inventario_cantidad_contada_valida_check
                    CHECK (
                        cantidad_contada IS NULL
                        OR (
                            cantidad_contada >= 0
                            AND cantidad_contada <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT detalle_conteos_inventario_captura_consistente_check
                    CHECK (
                        (
                            cantidad_contada IS NULL
                            AND contada_en IS NULL
                        )
                        OR (
                            cantidad_contada IS NOT NULL
                            AND contada_en IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT detalle_conteos_inventario_stock_teorico_consistente_check
                    CHECK (
                        stock_teorico_snapshot IS NULL
                        OR (
                            cantidad_contada IS NOT NULL
                            AND stock_teorico_snapshot >= 0
                            AND stock_teorico_snapshot <> 'NaN'::numeric
                        )
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_conteos_inventario');
    }
};
