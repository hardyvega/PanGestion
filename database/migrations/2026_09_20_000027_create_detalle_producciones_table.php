<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_producciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produccion_id');
            $table->foreignId('articulo_id');
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->decimal('cantidad_teorica', 14, 3);
            $table->decimal('cantidad_real', 14, 3)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'produccion_id',
                'detalle_producciones_produccion_id_foreign'
            )
                ->references('id')
                ->on('producciones')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_id',
                'detalle_producciones_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'produccion_id',
                'detalle_producciones_produccion_id_unique'
            );

            $table->index(
                ['articulo_id', 'produccion_id', 'id'],
                'detalle_producciones_articulo_id_produccion_id_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_producciones
                ADD CONSTRAINT detalle_producciones_articulo_sku_snapshot_valido_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_producciones_articulo_nombre_snapshot_valido_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT detalle_producciones_unidad_codigo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_producciones_unidad_simbolo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT detalle_producciones_cantidad_teorica_valida_check
                    CHECK (
                        cantidad_teorica > 0
                        AND cantidad_teorica <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT detalle_producciones_cantidad_real_valida_check
                    CHECK (
                        cantidad_real IS NULL
                        OR (
                            cantidad_real >= 0
                            AND cantidad_real <> 'NaN'::numeric
                        )
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_producciones');
    }
};
