<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50);
            $table->string('nombre', 100);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE roles
                ADD CONSTRAINT roles_codigo_no_vacio_check
                    CHECK (btrim(codigo) <> ''),
                ADD CONSTRAINT roles_codigo_sin_espacios_exteriores_check
                    CHECK (codigo = btrim(codigo)),
                ADD CONSTRAINT roles_codigo_minusculas_check
                    CHECK (codigo = lower(codigo)),
                ADD CONSTRAINT roles_codigo_formato_check
                    CHECK (codigo ~ '^[a-z][a-z0-9._-]*$'),
                ADD CONSTRAINT roles_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT roles_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre))
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX roles_codigo_lower_unique
             ON roles (lower(codigo))'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS roles_codigo_lower_unique'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE IF EXISTS roles
                DROP CONSTRAINT IF EXISTS roles_codigo_no_vacio_check,
                DROP CONSTRAINT IF EXISTS roles_codigo_sin_espacios_exteriores_check,
                DROP CONSTRAINT IF EXISTS roles_codigo_minusculas_check,
                DROP CONSTRAINT IF EXISTS roles_codigo_formato_check,
                DROP CONSTRAINT IF EXISTS roles_nombre_no_vacio_check,
                DROP CONSTRAINT IF EXISTS roles_nombre_sin_espacios_exteriores_check
            SQL);

        Schema::dropIfExists('roles');
    }
};
