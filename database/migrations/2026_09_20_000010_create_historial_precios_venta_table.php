<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_precios_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->decimal('precio_anterior', 14, 2)->nullable();
            $table->decimal('precio_nuevo', 14, 2)->nullable();
            $table->timestampTz('vigente_desde', 6)->useCurrent();
            $table->foreignId('usuario_id');
            $table->text('motivo')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'historial_precios_venta_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_id',
                'historial_precios_venta_usuario_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['articulo_id', 'vigente_desde', 'id'],
                'historial_precios_venta_articulo_vigencia_id_index'
            );

            $table->index(
                'usuario_id',
                'historial_precios_venta_usuario_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historial_precios_venta
                ADD CONSTRAINT historial_precios_venta_precio_anterior_valido_check
                    CHECK (
                        precio_anterior IS NULL
                        OR (
                            precio_anterior >= 0
                            AND precio_anterior <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT historial_precios_venta_precio_nuevo_valido_check
                    CHECK (
                        precio_nuevo IS NULL
                        OR (
                            precio_nuevo >= 0
                            AND precio_nuevo <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT historial_precios_venta_cambio_valido_check
                    CHECK (precio_anterior IS NOT NULL OR precio_nuevo IS NOT NULL),
                ADD CONSTRAINT historial_precios_venta_motivo_valido_check
                    CHECK (
                        motivo IS NULL
                        OR (
                            btrim(motivo) <> ''
                            AND motivo = btrim(motivo)
                        )
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_precios_venta');
    }
};
