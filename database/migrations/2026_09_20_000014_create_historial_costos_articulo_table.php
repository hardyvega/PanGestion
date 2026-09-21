<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_costos_articulo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->foreignId('proveedor_id')->nullable();
            $table->decimal('costo_anterior', 14, 2)->nullable();
            $table->decimal('costo_nuevo', 14, 2)->nullable();
            $table->timestampTz('vigente_desde', 6)->useCurrent();
            $table->foreignId('usuario_id');
            $table->text('motivo')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'historial_costos_articulo_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'proveedor_id',
                'historial_costos_articulo_proveedor_id_foreign'
            )
                ->references('id')
                ->on('proveedores')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_id',
                'historial_costos_articulo_usuario_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['articulo_id', 'vigente_desde', 'id'],
                'historial_costos_articulo_articulo_vigencia_id_index'
            );

            $table->index(
                'usuario_id',
                'historial_costos_articulo_usuario_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE historial_costos_articulo
                ADD CONSTRAINT historial_costos_articulo_costo_anterior_valido_check
                    CHECK (
                        costo_anterior IS NULL
                        OR (
                            costo_anterior >= 0
                            AND costo_anterior <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT historial_costos_articulo_costo_nuevo_valido_check
                    CHECK (
                        costo_nuevo IS NULL
                        OR (
                            costo_nuevo >= 0
                            AND costo_nuevo <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT historial_costos_articulo_cambio_valido_check
                    CHECK (costo_anterior IS NOT NULL OR costo_nuevo IS NOT NULL),
                ADD CONSTRAINT historial_costos_articulo_motivo_valido_check
                    CHECK (
                        motivo IS NULL
                        OR (
                            btrim(motivo) <> ''
                            AND motivo = btrim(motivo)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX historial_costos_articulo_proveedor_articulo_vigencia_id_index
             ON historial_costos_articulo (proveedor_id, articulo_id, vigente_desde, id)
             WHERE proveedor_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_costos_articulo');
    }
};
