<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sesiones_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caja_id');
            $table->foreignId('usuario_apertura_id');
            $table->foreignId('usuario_cierre_id')->nullable();
            $table->decimal('monto_inicial', 14, 2);
            $table->timestampTz('abierta_en', 6)->useCurrent();
            $table->timestampTz('cerrada_en', 6)->nullable();
            $table->decimal('monto_cierre_declarado', 14, 2)->nullable();
            $table->decimal('monto_teorico_cierre', 14, 2)->nullable();
            $table->text('observacion_cierre')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'caja_id',
                'sesiones_caja_caja_id_foreign'
            )
                ->references('id')
                ->on('cajas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_apertura_id',
                'sesiones_caja_usuario_apertura_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'usuario_cierre_id',
                'sesiones_caja_usuario_cierre_id_foreign'
            )
                ->references('id')
                ->on('usuarios')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                ['caja_id', 'abierta_en', 'id'],
                'sesiones_caja_caja_id_abierta_en_id_index'
            );

            $table->index(
                'usuario_apertura_id',
                'sesiones_caja_usuario_apertura_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE sesiones_caja
                ADD CONSTRAINT sesiones_caja_monto_inicial_valido_check
                    CHECK (
                        monto_inicial >= 0
                        AND monto_inicial <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT sesiones_caja_monto_cierre_declarado_valido_check
                    CHECK (
                        monto_cierre_declarado IS NULL
                        OR (
                            monto_cierre_declarado >= 0
                            AND monto_cierre_declarado <> 'NaN'::numeric
                        )
                    ),
                ADD CONSTRAINT sesiones_caja_monto_teorico_cierre_valido_check
                    CHECK (
                        monto_teorico_cierre IS NULL
                        OR monto_teorico_cierre <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT sesiones_caja_cierre_consistente_check
                    CHECK (
                        (
                            cerrada_en IS NULL
                            AND usuario_cierre_id IS NULL
                            AND monto_cierre_declarado IS NULL
                            AND monto_teorico_cierre IS NULL
                            AND observacion_cierre IS NULL
                        )
                        OR (
                            cerrada_en IS NOT NULL
                            AND usuario_cierre_id IS NOT NULL
                            AND monto_cierre_declarado IS NOT NULL
                            AND monto_teorico_cierre IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT sesiones_caja_fechas_validas_check
                    CHECK (
                        cerrada_en IS NULL
                        OR cerrada_en >= abierta_en
                    ),
                ADD CONSTRAINT sesiones_caja_observacion_cierre_valida_check
                    CHECK (
                        observacion_cierre IS NULL
                        OR (
                            btrim(observacion_cierre) <> ''
                            AND observacion_cierre = btrim(observacion_cierre)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX sesiones_caja_caja_id_abierta_unique
             ON sesiones_caja (caja_id)
             WHERE cerrada_en IS NULL'
        );

        DB::statement(
            'CREATE INDEX sesiones_caja_usuario_cierre_id_index
             ON sesiones_caja (usuario_cierre_id)
             WHERE usuario_cierre_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sesiones_caja');
    }
};
