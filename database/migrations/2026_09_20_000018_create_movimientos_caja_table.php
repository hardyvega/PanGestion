<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_caja_id');
            $table->foreignId('usuario_id');
            $table->string('tipo_movimiento', 20);
            $table->decimal('monto', 14, 2);
            $table->text('concepto');
            $table->timestampTz('ocurrido_en', 6)->useCurrent();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'sesion_caja_id',
                'movimientos_caja_sesion_caja_id_foreign'
            )
                ->references('id')
                ->on('sesiones_caja')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_id',
                'movimientos_caja_usuario_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['sesion_caja_id', 'ocurrido_en', 'id'],
                'movimientos_caja_sesion_caja_id_ocurrido_en_id_index'
            );

            $table->index(
                ['tipo_movimiento', 'ocurrido_en', 'id'],
                'movimientos_caja_tipo_movimiento_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_id', 'ocurrido_en', 'id'],
                'movimientos_caja_usuario_id_ocurrido_en_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE movimientos_caja
                ADD CONSTRAINT movimientos_caja_tipo_movimiento_valido_check
                    CHECK (
                        tipo_movimiento IN ('ingreso', 'egreso', 'retiro')
                    ),
                ADD CONSTRAINT movimientos_caja_monto_valido_check
                    CHECK (
                        monto > 0
                        AND monto <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT movimientos_caja_concepto_valido_check
                    CHECK (
                        btrim(concepto) <> ''
                        AND concepto = btrim(concepto)
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_caja');
    }
};
