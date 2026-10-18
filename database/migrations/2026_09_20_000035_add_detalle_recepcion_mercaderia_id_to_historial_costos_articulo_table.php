<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('historial_costos_articulo', function (Blueprint $table) {
            $table->foreignId('detalle_recepcion_mercaderia_id')->nullable();

            $table->foreign(
                'detalle_recepcion_mercaderia_id',
                'hist_costos_detalle_recepcion_id_foreign'
            )
                ->references('id')
                ->on('detalle_recepciones_mercaderia')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                'detalle_recepcion_mercaderia_id',
                'hist_costos_detalle_recepcion_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('historial_costos_articulo', function (Blueprint $table) {
            $table->dropUnique('hist_costos_detalle_recepcion_id_unique');
            $table->dropForeign('hist_costos_detalle_recepcion_id_foreign');
            $table->dropColumn('detalle_recepcion_mercaderia_id');
        });
    }
};
