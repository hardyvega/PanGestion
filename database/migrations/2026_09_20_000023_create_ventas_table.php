<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->date('fecha_comercial');
            $table->bigInteger('numero_diario');
            $table->uuid('clave_idempotencia');
            $table->foreignId('usuario_id');
            $table->foreignId('sesion_caja_id');
            $table->foreignId('cliente_id')->nullable();
            $table->foreignId('pedido_id')->nullable();
            $table->string('estado', 20)->default('completada');
            $table->decimal('total', 14, 2);
            $table->decimal('monto_anticipo_aplicado', 14, 2)->default(0);
            $table->timestampTz('completada_en', 6);
            $table->timestampTz('anulada_en', 6)->nullable();
            $table->foreignId('usuario_anulador_id')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'usuario_id',
                'ventas_usuario_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'sesion_caja_id',
                'ventas_sesion_caja_id_foreign'
            )
                ->references('id')
                ->on('sesiones_caja')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'cliente_id',
                'ventas_cliente_id_foreign'
            )
                ->references('id')
                ->on('clientes')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'pedido_id',
                'ventas_pedido_id_foreign'
            )
                ->references('id')
                ->on('pedidos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_anulador_id',
                'ventas_usuario_anulador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['fecha_comercial', 'numero_diario'],
                'ventas_fecha_comercial_numero_diario_unique'
            );

            $table->unique(
                'clave_idempotencia',
                'ventas_clave_idempotencia_unique'
            );

            $table->unique(
                'pedido_id',
                'ventas_pedido_id_unique'
            );

            $table->index(
                ['completada_en', 'id'],
                'ventas_completada_en_id_index'
            );

            $table->index(
                ['usuario_id', 'completada_en', 'id'],
                'ventas_usuario_id_completada_en_id_index'
            );

            $table->index(
                ['sesion_caja_id', 'completada_en', 'id'],
                'ventas_sesion_caja_id_completada_en_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ventas
                ADD CONSTRAINT ventas_numero_diario_positivo_check
                    CHECK (numero_diario > 0),
                ADD CONSTRAINT ventas_fecha_comercial_consistente_check
                    CHECK (
                        fecha_comercial =
                            (completada_en AT TIME ZONE 'America/Santiago')::date
                    ),
                ADD CONSTRAINT ventas_estado_valido_check
                    CHECK (estado IN ('completada', 'anulada')),
                ADD CONSTRAINT ventas_total_valido_check
                    CHECK (
                        total >= 0
                        AND total <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT ventas_monto_anticipo_aplicado_valido_check
                    CHECK (
                        monto_anticipo_aplicado >= 0
                        AND monto_anticipo_aplicado <> 'NaN'::numeric
                        AND monto_anticipo_aplicado <= total
                        AND (
                            pedido_id IS NOT NULL
                            OR monto_anticipo_aplicado = 0
                        )
                    ),
                ADD CONSTRAINT ventas_origen_pedido_consistente_check
                    CHECK (
                        pedido_id IS NULL
                        OR cliente_id IS NOT NULL
                    ),
                ADD CONSTRAINT ventas_anulacion_consistente_check
                    CHECK (
                        (
                            estado = 'completada'
                            AND anulada_en IS NULL
                            AND usuario_anulador_id IS NULL
                            AND motivo_anulacion IS NULL
                        )
                        OR (
                            estado = 'anulada'
                            AND anulada_en IS NOT NULL
                            AND usuario_anulador_id IS NOT NULL
                            AND motivo_anulacion IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT ventas_fechas_validas_check
                    CHECK (
                        anulada_en IS NULL
                        OR anulada_en >= completada_en
                    ),
                ADD CONSTRAINT ventas_motivo_anulacion_valido_check
                    CHECK (
                        motivo_anulacion IS NULL
                        OR (
                            btrim(motivo_anulacion) <> ''
                            AND motivo_anulacion = btrim(motivo_anulacion)
                        )
                    ),
                ADD CONSTRAINT ventas_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX ventas_cliente_id_completada_en_id_index
             ON ventas (cliente_id, completada_en, id)
             WHERE cliente_id IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX ventas_usuario_anulador_id_index
             ON ventas (usuario_anulador_id)
             WHERE usuario_anulador_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
