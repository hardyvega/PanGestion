<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->foreignId('usuario_id');
            $table->foreignId('usuario_autorizador_id')->nullable();
            $table->string('tipo_movimiento', 30);
            $table->decimal('cantidad_delta', 14, 3);
            $table->decimal('stock_anterior', 14, 3);
            $table->decimal('stock_posterior', 14, 3);
            $table->uuid('clave_idempotencia');
            $table->integer('secuencia_idempotencia');
            $table->timestampTz('ocurrido_en', 6)->useCurrent();
            $table->text('motivo')->nullable();
            $table->string('articulo_sku_snapshot', 50);
            $table->string('articulo_nombre_snapshot', 150);
            $table->string('unidad_codigo_snapshot', 50);
            $table->string('unidad_simbolo_snapshot', 20);
            $table->foreignId('detalle_venta_id')->nullable();
            $table->foreignId('detalle_recepcion_mercaderia_id')->nullable();
            $table->foreignId('detalle_produccion_id')->nullable();
            $table->foreignId('consumo_produccion_id')->nullable();
            $table->foreignId('merma_id')->nullable();
            $table->foreignId('detalle_conteo_inventario_id')->nullable();
            $table->foreignId('movimiento_revertido_id')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'mov_inv_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_id',
                'mov_inv_usuario_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_autorizador_id',
                'mov_inv_usuario_autorizador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'detalle_venta_id',
                'mov_inv_detalle_venta_id_foreign'
            )
                ->references('id')
                ->on('detalle_ventas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'detalle_recepcion_mercaderia_id',
                'mov_inv_detalle_recepcion_id_foreign'
            )
                ->references('id')
                ->on('detalle_recepciones_mercaderia')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'detalle_produccion_id',
                'mov_inv_detalle_produccion_id_foreign'
            )
                ->references('id')
                ->on('detalle_producciones')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'consumo_produccion_id',
                'mov_inv_consumo_produccion_id_foreign'
            )
                ->references('id')
                ->on('consumos_produccion')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'merma_id',
                'mov_inv_merma_id_foreign'
            )
                ->references('id')
                ->on('mermas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'detalle_conteo_inventario_id',
                'mov_inv_detalle_conteo_id_foreign'
            )
                ->references('id')
                ->on('detalle_conteos_inventario')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'movimiento_revertido_id',
                'mov_inv_movimiento_revertido_id_foreign'
            )
                ->references('id')
                ->on('movimientos_inventario')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['clave_idempotencia', 'secuencia_idempotencia'],
                'mov_inv_idempotencia_secuencia_unique'
            );

            $table->unique(
                'movimiento_revertido_id',
                'mov_inv_movimiento_revertido_id_unique'
            );

            $table->index(
                ['articulo_id', 'id'],
                'mov_inv_articulo_id_id_index'
            );

            $table->index(
                ['articulo_id', 'ocurrido_en', 'id'],
                'mov_inv_articulo_ocurrido_en_id_index'
            );

            $table->index(
                ['tipo_movimiento', 'ocurrido_en', 'id'],
                'mov_inv_tipo_ocurrido_en_id_index'
            );

            $table->index(
                ['usuario_id', 'created_at', 'id'],
                'mov_inv_usuario_created_at_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE movimientos_inventario
                ADD CONSTRAINT mov_inv_sku_snapshot_valido_check
                    CHECK (
                        btrim(articulo_sku_snapshot) <> ''
                        AND articulo_sku_snapshot = btrim(articulo_sku_snapshot)
                        AND articulo_sku_snapshot = lower(articulo_sku_snapshot)
                        AND articulo_sku_snapshot ~ '^[a-z0-9][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT mov_inv_nombre_snapshot_valido_check
                    CHECK (
                        btrim(articulo_nombre_snapshot) <> ''
                        AND articulo_nombre_snapshot = btrim(articulo_nombre_snapshot)
                    ),
                ADD CONSTRAINT mov_inv_unidad_codigo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_codigo_snapshot) <> ''
                        AND unidad_codigo_snapshot = btrim(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot = lower(unidad_codigo_snapshot)
                        AND unidad_codigo_snapshot ~ '^[a-z][a-z0-9_-]*$'
                    ),
                ADD CONSTRAINT mov_inv_unidad_simbolo_snapshot_valido_check
                    CHECK (
                        btrim(unidad_simbolo_snapshot) <> ''
                        AND unidad_simbolo_snapshot = btrim(unidad_simbolo_snapshot)
                    ),
                ADD CONSTRAINT mov_inv_tipo_valido_check
                    CHECK (
                        tipo_movimiento IN (
                            'saldo_inicial',
                            'venta',
                            'anulacion_venta',
                            'recepcion',
                            'anulacion_recepcion',
                            'produccion_entrada',
                            'anulacion_produccion_entrada',
                            'produccion_consumo',
                            'anulacion_produccion_consumo',
                            'merma',
                            'anulacion_merma',
                            'ajuste_conteo_positivo',
                            'ajuste_conteo_negativo',
                            'ajuste_manual_entrada',
                            'ajuste_manual_salida'
                        )
                    ),
                ADD CONSTRAINT mov_inv_delta_valido_check
                    CHECK (
                        cantidad_delta <> 0
                        AND cantidad_delta <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT mov_inv_signo_consistente_check
                    CHECK (
                        (
                            tipo_movimiento IN (
                                'saldo_inicial',
                                'anulacion_venta',
                                'recepcion',
                                'produccion_entrada',
                                'anulacion_produccion_consumo',
                                'anulacion_merma',
                                'ajuste_conteo_positivo',
                                'ajuste_manual_entrada'
                            )
                            AND cantidad_delta > 0
                        )
                        OR (
                            tipo_movimiento IN (
                                'venta',
                                'anulacion_recepcion',
                                'anulacion_produccion_entrada',
                                'produccion_consumo',
                                'merma',
                                'ajuste_conteo_negativo',
                                'ajuste_manual_salida'
                            )
                            AND cantidad_delta < 0
                        )
                    ),
                ADD CONSTRAINT mov_inv_stock_anterior_valido_check
                    CHECK (
                        stock_anterior >= 0
                        AND stock_anterior <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT mov_inv_stock_posterior_valido_check
                    CHECK (
                        stock_posterior >= 0
                        AND stock_posterior <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT mov_inv_stock_consistente_check
                    CHECK (
                        stock_posterior = stock_anterior + cantidad_delta
                    ),
                ADD CONSTRAINT mov_inv_secuencia_idempotencia_valida_check
                    CHECK (secuencia_idempotencia > 0),
                ADD CONSTRAINT mov_inv_ocurrido_en_valido_check
                    CHECK (ocurrido_en <= created_at),
                ADD CONSTRAINT mov_inv_motivo_consistente_check
                    CHECK (
                        (
                            tipo_movimiento IN (
                                'saldo_inicial',
                                'ajuste_manual_entrada',
                                'ajuste_manual_salida'
                            )
                            AND motivo IS NOT NULL
                            AND btrim(motivo) <> ''
                            AND motivo = btrim(motivo)
                        )
                        OR (
                            tipo_movimiento NOT IN (
                                'saldo_inicial',
                                'ajuste_manual_entrada',
                                'ajuste_manual_salida'
                            )
                            AND (
                                motivo IS NULL
                                OR (
                                    btrim(motivo) <> ''
                                    AND motivo = btrim(motivo)
                                )
                            )
                        )
                    ),
                ADD CONSTRAINT mov_inv_origen_causal_consistente_check
                    CHECK (
                        (
                            tipo_movimiento = 'saldo_inicial'
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 0
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento = 'venta'
                            AND detalle_venta_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento = 'anulacion_venta'
                            AND detalle_venta_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NOT NULL
                        )
                        OR (
                            tipo_movimiento = 'recepcion'
                            AND detalle_recepcion_mercaderia_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento = 'anulacion_recepcion'
                            AND detalle_recepcion_mercaderia_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NOT NULL
                        )
                        OR (
                            tipo_movimiento = 'produccion_entrada'
                            AND detalle_produccion_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento = 'anulacion_produccion_entrada'
                            AND detalle_produccion_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NOT NULL
                        )
                        OR (
                            tipo_movimiento = 'produccion_consumo'
                            AND consumo_produccion_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento = 'anulacion_produccion_consumo'
                            AND consumo_produccion_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NOT NULL
                        )
                        OR (
                            tipo_movimiento = 'merma'
                            AND merma_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento = 'anulacion_merma'
                            AND merma_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NOT NULL
                        )
                        OR (
                            tipo_movimiento IN (
                                'ajuste_conteo_positivo',
                                'ajuste_conteo_negativo'
                            )
                            AND detalle_conteo_inventario_id IS NOT NULL
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 1
                            AND movimiento_revertido_id IS NULL
                        )
                        OR (
                            tipo_movimiento IN (
                                'ajuste_manual_entrada',
                                'ajuste_manual_salida'
                            )
                            AND num_nonnulls(
                                detalle_venta_id,
                                detalle_recepcion_mercaderia_id,
                                detalle_produccion_id,
                                consumo_produccion_id,
                                merma_id,
                                detalle_conteo_inventario_id
                            ) = 0
                            AND movimiento_revertido_id IS NULL
                        )
                    ),
                ADD CONSTRAINT mov_inv_reverso_distinto_check
                    CHECK (
                        movimiento_revertido_id IS NULL
                        OR movimiento_revertido_id <> id
                    ),
                ADD CONSTRAINT mov_inv_saldo_inicial_consistente_check
                    CHECK (
                        tipo_movimiento <> 'saldo_inicial'
                        OR (
                            stock_anterior = 0
                            AND stock_posterior = cantidad_delta
                        )
                    )
            SQL);

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_detalle_venta_original_unique
             ON movimientos_inventario (detalle_venta_id)
             WHERE tipo_movimiento = 'venta'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_detalle_recepcion_original_unique
             ON movimientos_inventario (detalle_recepcion_mercaderia_id)
             WHERE tipo_movimiento = 'recepcion'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_detalle_produccion_original_unique
             ON movimientos_inventario (detalle_produccion_id)
             WHERE tipo_movimiento = 'produccion_entrada'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_consumo_produccion_original_unique
             ON movimientos_inventario (consumo_produccion_id)
             WHERE tipo_movimiento = 'produccion_consumo'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_merma_original_unique
             ON movimientos_inventario (merma_id)
             WHERE tipo_movimiento = 'merma'"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_detalle_conteo_ajuste_unique
             ON movimientos_inventario (detalle_conteo_inventario_id)
             WHERE tipo_movimiento IN (
                 'ajuste_conteo_positivo',
                 'ajuste_conteo_negativo'
             )"
        );

        DB::statement(
            "CREATE UNIQUE INDEX mov_inv_articulo_saldo_inicial_unique
             ON movimientos_inventario (articulo_id)
             WHERE tipo_movimiento = 'saldo_inicial'"
        );

        DB::statement(
            'CREATE INDEX mov_inv_autorizador_created_at_id_index
             ON movimientos_inventario (usuario_autorizador_id, created_at, id)
             WHERE usuario_autorizador_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
