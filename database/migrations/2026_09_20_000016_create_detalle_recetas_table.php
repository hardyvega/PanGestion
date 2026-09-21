<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_recetas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receta_id');
            $table->foreignId('articulo_componente_id');
            $table->decimal('cantidad', 14, 3);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'receta_id',
                'detalle_recetas_receta_id_foreign'
            )
                ->references('id')
                ->on('recetas')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'articulo_componente_id',
                'detalle_recetas_articulo_componente_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['receta_id', 'articulo_componente_id'],
                'detalle_recetas_receta_id_articulo_componente_id_unique'
            );

            $table->index(
                'articulo_componente_id',
                'detalle_recetas_articulo_componente_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE detalle_recetas
                ADD CONSTRAINT detalle_recetas_cantidad_valida_check
                    CHECK (
                        cantidad > 0
                        AND cantidad <> 'NaN'::numeric
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_recetas');
    }
};
