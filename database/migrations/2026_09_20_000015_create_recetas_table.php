<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->integer('version');
            $table->decimal('rendimiento_cantidad', 14, 3);
            $table->boolean('activa')->default(false);
            $table->text('observacion')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'recetas_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['articulo_id', 'version'],
                'recetas_articulo_id_version_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE recetas
                ADD CONSTRAINT recetas_version_valida_check
                    CHECK (version > 0),
                ADD CONSTRAINT recetas_rendimiento_cantidad_valida_check
                    CHECK (
                        rendimiento_cantidad > 0
                        AND rendimiento_cantidad <> 'NaN'::numeric
                    ),
                ADD CONSTRAINT recetas_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX recetas_articulo_id_activa_unique
             ON recetas (articulo_id)
             WHERE activa = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recetas');
    }
};
