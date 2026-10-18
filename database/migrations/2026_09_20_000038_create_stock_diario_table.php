<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_diario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->date('fecha_comercial');
            $table->decimal('stock_apertura', 14, 3);
            $table->decimal('total_entradas', 14, 3);
            $table->decimal('total_salidas', 14, 3);
            $table->decimal('stock_cierre', 14, 3);
            $table->timestampTz('calculado_en', 6)->useCurrent();
            $table->foreignId('ultimo_movimiento_id')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'stock_diario_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'ultimo_movimiento_id',
                'stock_diario_ultimo_movimiento_id_foreign'
            )
                ->references('id')
                ->on('movimientos_inventario')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['articulo_id', 'fecha_comercial'],
                'stock_diario_articulo_fecha_unique'
            );

            $table->index(
                ['fecha_comercial', 'articulo_id'],
                'stock_diario_fecha_articulo_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE stock_diario
                ADD CONSTRAINT stock_diario_apertura_valida_check
                    CHECK (
                        stock_apertura >= 0
                        AND stock_apertura <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT stock_diario_entradas_validas_check
                    CHECK (
                        total_entradas >= 0
                        AND total_entradas <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT stock_diario_salidas_validas_check
                    CHECK (
                        total_salidas >= 0
                        AND total_salidas <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT stock_diario_cierre_valido_check
                    CHECK (
                        stock_cierre >= 0
                        AND stock_cierre <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT stock_diario_cierre_consistente_check
                    CHECK (
                        stock_cierre = stock_apertura + total_entradas - total_salidas
                    )
            SQL);

        DB::statement(
            'CREATE INDEX stock_diario_ultimo_movimiento_id_index
             ON stock_diario (ultimo_movimiento_id)
             WHERE ultimo_movimiento_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_diario');
    }
};
