<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagos_pedido', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id');
            $table->foreignId('metodo_pago_id');
            $table->foreignId('sesion_caja_id');
            $table->foreignId('usuario_id');
            $table->uuid('clave_idempotencia');
            $table->string('tipo', 20);
            $table->decimal('monto', 14, 2);
            $table->decimal('monto_recibido', 14, 2)->nullable();
            $table->decimal('vuelto', 14, 2)->nullable();
            $table->timestampTz('ocurrido_en', 6)->useCurrent();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'pedido_id',
                'pagos_pedido_pedido_id_foreign'
            )
                ->references('id')
                ->on('pedidos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'metodo_pago_id',
                'pagos_pedido_metodo_pago_id_foreign'
            )
                ->references('id')
                ->on('metodos_pago')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'sesion_caja_id',
                'pagos_pedido_sesion_caja_id_foreign'
            )
                ->references('id')
                ->on('sesiones_caja')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_id',
                'pagos_pedido_usuario_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'clave_idempotencia',
                'pagos_pedido_clave_idempotencia_unique'
            );

            $table->index(
                ['pedido_id', 'ocurrido_en', 'id'],
                'pagos_pedido_pedido_id_ocurrido_en_id_index'
            );

            $table->index(
                ['sesion_caja_id', 'ocurrido_en', 'id'],
                'pagos_pedido_sesion_caja_id_ocurrido_en_id_index'
            );

            $table->index(
                ['metodo_pago_id', 'ocurrido_en', 'id'],
                'pagos_pedido_metodo_pago_id_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_id', 'ocurrido_en', 'id'],
                'pagos_pedido_usuario_id_ocurrido_en_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pagos_pedido
                ADD CONSTRAINT pagos_pedido_tipo_valido_check
                    CHECK (
                        tipo IN ('cobro', 'devolucion')
                    ),
                ADD CONSTRAINT pagos_pedido_monto_valido_check
                    CHECK (
                        monto > 0
                        AND monto <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT pagos_pedido_monto_recibido_valido_check
                    CHECK (
                        monto_recibido IS NULL
                        OR (
                            monto_recibido >= 0
                            AND monto_recibido <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT pagos_pedido_vuelto_valido_check
                    CHECK (
                        vuelto IS NULL
                        OR (
                            vuelto >= 0
                            AND vuelto <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT pagos_pedido_efectivo_consistente_check
                    CHECK (
                        (
                            tipo = 'devolucion'
                            AND monto_recibido IS NULL
                            AND vuelto IS NULL
                        )
                        OR (
                            tipo = 'cobro'
                            AND (
                                (
                                    monto_recibido IS NULL
                                    AND vuelto IS NULL
                                )
                                OR (
                                    monto_recibido IS NOT NULL
                                    AND vuelto IS NOT NULL
                                    AND monto_recibido >= monto
                                    AND vuelto = monto_recibido - monto
                                )
                            )
                        )
                    ),
                ADD CONSTRAINT pagos_pedido_observacion_valida_check
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
        Schema::dropIfExists('pagos_pedido');
    }
};
