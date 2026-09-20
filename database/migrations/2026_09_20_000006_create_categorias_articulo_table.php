<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias_articulo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50);
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE categorias_articulo
                ADD CONSTRAINT categorias_articulo_codigo_no_vacio_check
                    CHECK (btrim(codigo) <> ''),
                ADD CONSTRAINT categorias_articulo_codigo_sin_espacios_exteriores_check
                    CHECK (codigo = btrim(codigo)),
                ADD CONSTRAINT categorias_articulo_codigo_minusculas_check
                    CHECK (codigo = lower(codigo)),
                ADD CONSTRAINT categorias_articulo_codigo_formato_check
                    CHECK (codigo ~ '^[a-z][a-z0-9_-]*$'),
                ADD CONSTRAINT categorias_articulo_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT categorias_articulo_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre))
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX categorias_articulo_codigo_lower_unique
             ON categorias_articulo (lower(codigo))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX categorias_articulo_nombre_lower_unique
             ON categorias_articulo (lower(nombre))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_articulo');
    }
};
