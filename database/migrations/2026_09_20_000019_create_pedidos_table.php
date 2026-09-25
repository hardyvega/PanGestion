<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id');
            $table->foreignId('usuario_creador_id');
            $table->string('estado', 20)->default('pendiente');
            $table->timestampTz('entrega_programada_en', 6)->nullable();
            $table->timestampTz('entregado_en', 6)->nullable();
            $table->timestampTz('cancelado_en', 6)->nullable();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'cliente_id',
                'pedidos_cliente_id_foreign'
            )
                ->references('id')
                ->on('clientes')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_creador_id',
                'pedidos_usuario_creador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['estado', 'created_at', 'id'],
                'pedidos_estado_created_at_id_index'
            );

            $table->index(
                ['cliente_id', 'created_at', 'id'],
                'pedidos_cliente_id_created_at_id_index'
            );

            $table->index(
                ['usuario_creador_id', 'created_at', 'id'],
                'pedidos_usuario_creador_id_created_at_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE pedidos
                ADD CONSTRAINT pedidos_estado_valido_check
                    CHECK (
                        estado IN (
                            'pendiente',
                            'confirmado',
                            'en_preparacion',
                            'listo',
                            'entregado',
                            'cancelado'
                        )
                    ),
                ADD CONSTRAINT pedidos_eventos_finales_consistentes_check
                    CHECK (
                        (
                            estado = 'entregado'
                            AND entregado_en IS NOT NULL
                            AND cancelado_en IS NULL
                        )
                        OR (
                            estado = 'cancelado'
                            AND cancelado_en IS NOT NULL
                            AND entregado_en IS NULL
                        )
                        OR (
                            estado NOT IN ('entregado', 'cancelado')
                            AND entregado_en IS NULL
                            AND cancelado_en IS NULL
                        )
                    ),
                ADD CONSTRAINT pedidos_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX pedidos_entrega_programada_en_id_index
             ON pedidos (entrega_programada_en, id)
             WHERE entrega_programada_en IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
