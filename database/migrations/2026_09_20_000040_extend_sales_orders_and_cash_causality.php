<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->uuid('clave_idempotencia')->nullable();
            $table->unique(
                'clave_idempotencia',
                'pedidos_clave_idempotencia_unique',
            );
        });

        Schema::table('ventas', function (Blueprint $table): void {
            $table->uuid('clave_idempotencia_anulacion')->nullable();
            $table->unique(
                'clave_idempotencia_anulacion',
                'ventas_clave_idempotencia_anulacion_unique',
            );
        });

        Schema::table('detalle_pedidos', function (Blueprint $table): void {
            $table->string('articulo_sku_snapshot', 50)->nullable();
            $table->string('articulo_nombre_snapshot', 150)->nullable();
            $table->string('unidad_codigo_snapshot', 50)->nullable();
            $table->string('unidad_simbolo_snapshot', 20)->nullable();
        });

        Schema::table('movimientos_caja', function (Blueprint $table): void {
            $table->foreignId('pago_venta_id')->nullable();
            $table->foreignId('pago_pedido_id')->nullable();

            $table->foreign(
                'pago_venta_id',
                'movimientos_caja_pago_venta_id_foreign',
            )
                ->references('id')
                ->on('pagos_venta')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'pago_pedido_id',
                'movimientos_caja_pago_pedido_id_foreign',
            )
                ->references('id')
                ->on('pagos_pedido')
                ->onUpdate('no action')
                ->onDelete('restrict');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE movimientos_caja
                ADD CONSTRAINT movimientos_caja_causalidad_pago_check
                    CHECK (
                        (
                            pago_venta_id IS NULL
                            AND pago_pedido_id IS NULL
                        )
                        OR (
                            pago_venta_id IS NOT NULL
                            AND pago_pedido_id IS NULL
                            AND tipo_movimiento IN ('ingreso', 'egreso')
                        )
                        OR (
                            pago_venta_id IS NULL
                            AND pago_pedido_id IS NOT NULL
                            AND tipo_movimiento IN ('ingreso', 'egreso')
                        )
                    )
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX movimientos_caja_pago_venta_unique
                ON movimientos_caja (pago_venta_id)
                WHERE pago_venta_id IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX movimientos_caja_pago_pedido_unique
                ON movimientos_caja (pago_pedido_id)
                WHERE pago_pedido_id IS NOT NULL
            SQL);

        Schema::create('transiciones_pedido', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pedido_id');
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20);
            $table->foreignId('usuario_id');
            $table->uuid('clave_idempotencia');
            $table->text('motivo')->nullable();
            $table->timestampTz('ocurrida_en', 6)->useCurrent();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'pedido_id',
                'transiciones_pedido_pedido_id_foreign',
            )
                ->references('id')
                ->on('pedidos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_id',
                'transiciones_pedido_usuario_id_foreign',
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'clave_idempotencia',
                'transiciones_pedido_clave_idempotencia_unique',
            );

            $table->index(
                ['pedido_id', 'ocurrida_en', 'id'],
                'transiciones_pedido_pedido_id_ocurrida_en_id_index',
            );

            $table->index(
                ['usuario_id', 'ocurrida_en', 'id'],
                'transiciones_pedido_usuario_id_ocurrida_en_id_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE transiciones_pedido
                ADD CONSTRAINT transiciones_pedido_estado_anterior_valido_check
                    CHECK (
                        estado_anterior IS NULL
                        OR estado_anterior IN (
                            'pendiente',
                            'confirmado',
                            'en_preparacion',
                            'listo',
                            'entregado',
                            'cancelado'
                        )
                    ),
                ADD CONSTRAINT transiciones_pedido_estado_nuevo_valido_check
                    CHECK (
                        estado_nuevo IN (
                            'pendiente',
                            'confirmado',
                            'en_preparacion',
                            'listo',
                            'entregado',
                            'cancelado'
                        )
                    ),
                ADD CONSTRAINT transiciones_pedido_par_valido_check
                    CHECK (
                        (
                            estado_anterior IS NULL
                            AND estado_nuevo = 'pendiente'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'pendiente'
                            AND estado_nuevo = 'confirmado'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'confirmado'
                            AND estado_nuevo = 'en_preparacion'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'en_preparacion'
                            AND estado_nuevo = 'listo'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'listo'
                            AND estado_nuevo = 'entregado'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'pendiente'
                            AND estado_nuevo = 'cancelado'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'confirmado'
                            AND estado_nuevo = 'cancelado'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'en_preparacion'
                            AND estado_nuevo = 'cancelado'
                        )
                        OR (
                            estado_anterior IS NOT NULL
                            AND
                            estado_anterior = 'listo'
                            AND estado_nuevo = 'cancelado'
                        )
                    ),
                ADD CONSTRAINT transiciones_pedido_motivo_cancelacion_valido_check
                    CHECK (
                        (
                            estado_nuevo = 'cancelado'
                            AND motivo IS NOT NULL
                            AND btrim(motivo) <> ''
                            AND motivo = btrim(motivo)
                        )
                        OR (
                            estado_nuevo <> 'cancelado'
                            AND (
                                motivo IS NULL
                                OR (
                                    btrim(motivo) <> ''
                                    AND motivo = btrim(motivo)
                                )
                            )
                        )
                    ),
                ADD CONSTRAINT transiciones_pedido_fechas_validas_check
                    CHECK (ocurrida_en <= created_at)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('transiciones_pedido');

        DB::statement(
            'ALTER TABLE movimientos_caja
             DROP CONSTRAINT movimientos_caja_causalidad_pago_check'
        );
        DB::statement('DROP INDEX IF EXISTS movimientos_caja_pago_venta_unique');
        DB::statement('DROP INDEX IF EXISTS movimientos_caja_pago_pedido_unique');

        Schema::table('movimientos_caja', function (Blueprint $table): void {
            $table->dropForeign('movimientos_caja_pago_venta_id_foreign');
            $table->dropForeign('movimientos_caja_pago_pedido_id_foreign');
            $table->dropColumn(['pago_venta_id', 'pago_pedido_id']);
        });

        Schema::table('detalle_pedidos', function (Blueprint $table): void {
            $table->dropColumn([
                'articulo_sku_snapshot',
                'articulo_nombre_snapshot',
                'unidad_codigo_snapshot',
                'unidad_simbolo_snapshot',
            ]);
        });

        Schema::table('ventas', function (Blueprint $table): void {
            $table->dropUnique('ventas_clave_idempotencia_anulacion_unique');
            $table->dropColumn('clave_idempotencia_anulacion');
        });

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropUnique('pedidos_clave_idempotencia_unique');
            $table->dropColumn('clave_idempotencia');
        });
    }
};
