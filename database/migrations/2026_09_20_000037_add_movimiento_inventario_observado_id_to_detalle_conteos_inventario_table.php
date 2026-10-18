<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalle_conteos_inventario', function (Blueprint $table) {
            $table->foreignId('movimiento_inventario_observado_id')->nullable();

            $table->foreign(
                'movimiento_inventario_observado_id',
                'detalle_conteos_mov_inv_observado_id_foreign'
            )
                ->references('id')
                ->on('movimientos_inventario')
                ->onUpdate('no action')
                ->onDelete('restrict');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_conteos_inventario
                ADD CONSTRAINT detalle_conteos_mov_inv_observado_consistente_check
                    CHECK (
                        movimiento_inventario_observado_id IS NULL
                        OR (
                            cantidad_contada IS NOT NULL
                            AND contada_en IS NOT NULL
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX detalle_conteos_mov_inv_observado_id_index
             ON detalle_conteos_inventario (movimiento_inventario_observado_id)
             WHERE movimiento_inventario_observado_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE detalle_conteos_inventario
             DROP CONSTRAINT detalle_conteos_mov_inv_observado_consistente_check'
        );

        Schema::table('detalle_conteos_inventario', function (Blueprint $table) {
            $table->dropIndex('detalle_conteos_mov_inv_observado_id_index');
            $table->dropForeign('detalle_conteos_mov_inv_observado_id_foreign');
            $table->dropColumn('movimiento_inventario_observado_id');
        });
    }
};
