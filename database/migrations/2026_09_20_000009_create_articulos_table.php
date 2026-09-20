<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulos', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50);
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->string('tipo_articulo', 20);
            $table->foreignId('categoria_articulo_id');
            $table->foreignId('unidad_id');
            $table->string('codigo_barras', 64)->nullable();
            $table->decimal('precio_venta_actual', 14, 2)->nullable();
            $table->decimal('stock_actual', 14, 3)->default(0);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->boolean('activo')->default(true);
            $table->string('imagen_ruta', 255)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'categoria_articulo_id',
                'articulos_categoria_articulo_id_foreign'
            )
                ->references('id')
                ->on('categorias_articulo')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'unidad_id',
                'articulos_unidad_id_foreign'
            )
                ->references('id')
                ->on('unidades_medida')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                'categoria_articulo_id',
                'articulos_categoria_articulo_id_index'
            );

            $table->index(
                'unidad_id',
                'articulos_unidad_id_index'
            );

            $table->index(
                ['tipo_articulo', 'activo'],
                'articulos_tipo_articulo_activo_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE articulos
                ADD CONSTRAINT articulos_sku_no_vacio_check
                    CHECK (btrim(sku) <> ''),
                ADD CONSTRAINT articulos_sku_sin_espacios_exteriores_check
                    CHECK (sku = btrim(sku)),
                ADD CONSTRAINT articulos_sku_minusculas_check
                    CHECK (sku = lower(sku)),
                ADD CONSTRAINT articulos_sku_formato_check
                    CHECK (sku ~ '^[a-z0-9][a-z0-9_-]*$'),
                ADD CONSTRAINT articulos_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT articulos_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT articulos_tipo_articulo_valido_check
                    CHECK (tipo_articulo IN ('elaborado', 'reventa', 'materia_prima', 'insumo_operacional')),
                ADD CONSTRAINT articulos_codigo_barras_valido_check
                    CHECK (
                        codigo_barras IS NULL
                        OR (
                            btrim(codigo_barras) <> ''
                            AND codigo_barras = btrim(codigo_barras)
                        )
                    ),
                ADD CONSTRAINT articulos_precio_venta_actual_valido_check
                    CHECK (
                        precio_venta_actual IS NULL
                        OR (
                            precio_venta_actual >= 0
                            AND precio_venta_actual <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT articulos_stock_actual_valido_check
                    CHECK (stock_actual >= 0 AND stock_actual <> 'NaN'::numeric),
                ADD CONSTRAINT articulos_stock_minimo_valido_check
                    CHECK (stock_minimo >= 0 AND stock_minimo <> 'NaN'::numeric),
                ADD CONSTRAINT articulos_imagen_ruta_valida_check
                    CHECK (
                        imagen_ruta IS NULL
                        OR (
                            btrim(imagen_ruta) <> ''
                            AND imagen_ruta = btrim(imagen_ruta)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX articulos_sku_lower_unique
             ON articulos (lower(sku))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX articulos_codigo_barras_unique
             ON articulos (codigo_barras)
             WHERE codigo_barras IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('articulos');
    }
};
