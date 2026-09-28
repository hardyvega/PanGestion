<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id');
            $table->foreignId('articulo_id');
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->decimal('cantidad', 14, 3);
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'venta_id',
                'detalle_ventas_venta_id_foreign'
            )
                ->references('id')
                ->on('ventas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_id',
                'detalle_ventas_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['venta_id', 'id'],
                'detalle_ventas_venta_id_id_index'
            );

            $table->index(
                ['articulo_id', 'venta_id', 'id'],
                'detalle_ventas_articulo_id_venta_id_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_ventas
                ADD CONSTRAINT detalle_ventas_articulo_sku_snapshot_valido_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_ventas_articulo_nombre_snapshot_valido_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT detalle_ventas_unidad_codigo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT detalle_ventas_unidad_simbolo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT detalle_ventas_cantidad_valida_check
                    CHECK (
                        cantidad > 0
                        AND cantidad <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT detalle_ventas_precio_unitario_valido_check
                    CHECK (
                        precio_unitario >= 0
                        AND precio_unitario <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT detalle_ventas_subtotal_consistente_check
                    CHECK (
                        subtotal >= 0
                        AND subtotal <> 'NaN'::numeric
                        AND subtotal = round(cantidad * precio_unitario, 2)
                    ),
                ADD CONSTRAINT detalle_ventas_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_ventas');
    }
};
