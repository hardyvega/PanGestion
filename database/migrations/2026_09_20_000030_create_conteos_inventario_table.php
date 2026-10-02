<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesion_caja_id')->nullable();
            $table->foreignId('usuario_creador_id');
            $table->foreignId('usuario_responsable_id');
            $table->foreignId('usuario_confirmador_id')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->timestampTz('confirmada_en', 6)->nullable();
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'sesion_caja_id',
                'conteos_inventario_sesion_caja_id_foreign'
            )
                ->references('id')
                ->on('sesiones_caja')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_creador_id',
                'conteos_inventario_usuario_creador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_responsable_id',
                'conteos_inventario_usuario_responsable_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_confirmador_id',
                'conteos_inventario_usuario_confirmador_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['created_at', 'id'],
                'conteos_inventario_created_at_id_index'
            );

            $table->index(
                ['estado', 'created_at', 'id'],
                'conteos_inventario_estado_created_at_id_index'
            );

            $table->index(
                ['usuario_creador_id', 'created_at', 'id'],
                'conteos_inventario_usuario_creador_id_created_at_id_index'
            );

            $table->index(
                ['usuario_responsable_id', 'created_at', 'id'],
                'conteos_inventario_usuario_responsable_id_created_at_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conteos_inventario
                ADD CONSTRAINT conteos_inventario_estado_valido_check
                    CHECK (
                        estado IN ('borrador', 'confirmado')
                    ),
                ADD CONSTRAINT conteos_inventario_confirmacion_consistente_check
                    CHECK (
                        (
                            estado = 'borrador'
                            AND usuario_confirmador_id IS NULL
                            AND confirmada_en IS NULL
                        )
                        OR (
                            estado = 'confirmado'
                            AND usuario_confirmador_id IS NOT NULL
                            AND confirmada_en IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT conteos_inventario_fecha_confirmacion_valida_check
                    CHECK (
                        confirmada_en IS NULL
                        OR confirmada_en >= created_at
                    ),
                ADD CONSTRAINT conteos_inventario_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            "CREATE UNIQUE INDEX conteos_inventario_estado_borrador_unique
             ON conteos_inventario (estado)
             WHERE estado = 'borrador'"
        );

        DB::statement(
            'CREATE INDEX conteos_inventario_sesion_caja_id_created_at_id_index
             ON conteos_inventario (sesion_caja_id, created_at, id)
             WHERE sesion_caja_id IS NOT NULL'
        );

        DB::statement(
            'CREATE INDEX conteos_inventario_confirmada_en_id_index
             ON conteos_inventario (confirmada_en, id)
             WHERE confirmada_en IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('conteos_inventario');
    }
};
