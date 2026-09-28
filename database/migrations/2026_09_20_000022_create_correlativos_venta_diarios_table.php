<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correlativos_venta_diarios', function (Blueprint $table) {
            $table->date('fecha_comercial');
            $table->bigInteger('ultimo_numero')->default(0);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->primary(
                'fecha_comercial',
                'correlativos_venta_diarios_pkey'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE correlativos_venta_diarios
                ADD CONSTRAINT correlativos_venta_diarios_ultimo_numero_valido_check
                    CHECK (ultimo_numero >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('correlativos_venta_diarios');
    }
};
