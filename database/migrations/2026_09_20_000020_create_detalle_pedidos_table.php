<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id');
            $table->foreignId('articulo_id');
            $table->decimal('cantidad', 14, 3);
            $table->decimal('precio_unitario', 14, 2);
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'pedido_id',
                'detalle_pedidos_pedido_id_foreign'
            )
                ->references('id')
                ->on('pedidos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_id',
                'detalle_pedidos_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['pedido_id', 'id'],
                'detalle_pedidos_pedido_id_id_index'
            );

            $table->index(
                ['articulo_id', 'pedido_id', 'id'],
                'detalle_pedidos_articulo_id_pedido_id_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_pedidos
                ADD CONSTRAINT detalle_pedidos_cantidad_valida_check
                    CHECK (
                        cantidad > 0
                        AND cantidad <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT detalle_pedidos_precio_unitario_valido_check
                    CHECK (
                        precio_unitario >= 0
                        AND precio_unitario <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT detalle_pedidos_observacion_valida_check
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
        Schema::dropIfExists('detalle_pedidos');
    }
};
