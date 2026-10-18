<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conteos_inventario', function (Blueprint $table) {
            $table->uuid('clave_idempotencia_creacion')->nullable();
            $table->uuid('clave_idempotencia_confirmacion')->nullable();

            $table->unique(
                'clave_idempotencia_creacion',
                'conteos_inventario_clave_idempotencia_creacion_unique'
            );

            $table->unique(
                'clave_idempotencia_confirmacion',
                'conteos_inventario_clave_idempotencia_confirmacion_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('conteos_inventario', function (Blueprint $table) {
            $table->dropUnique('conteos_inventario_clave_idempotencia_creacion_unique');
            $table->dropUnique('conteos_inventario_clave_idempotencia_confirmacion_unique');
            $table->dropColumn([
                'clave_idempotencia_creacion',
                'clave_idempotencia_confirmacion',
            ]);
        });
    }
};
