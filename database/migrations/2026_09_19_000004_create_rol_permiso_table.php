<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->foreignId('rol_id');
            $table->foreignId('permiso_id');

            $table->primary(
                ['rol_id', 'permiso_id'],
                'rol_permiso_pkey'
            );

            $table->foreign(
                'rol_id',
                'rol_permiso_rol_id_foreign'
            )
                ->references('id')
                ->on('roles')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'permiso_id',
                'rol_permiso_permiso_id_foreign'
            )
                ->references('id')
                ->on('permisos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                'permiso_id',
                'rol_permiso_permiso_id_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol_permiso');
    }
};
