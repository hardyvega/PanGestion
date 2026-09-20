<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 100);
            $table->string('nombre', 150);
            $table->string('modulo', 50);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->index(
                ['modulo', 'activo'],
                'permisos_modulo_activo_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE permisos
                ADD CONSTRAINT permisos_codigo_no_vacio_check
                    CHECK (btrim(codigo) <> ''),
                ADD CONSTRAINT permisos_codigo_sin_espacios_exteriores_check
                    CHECK (codigo = btrim(codigo)),
                ADD CONSTRAINT permisos_codigo_minusculas_check
                    CHECK (codigo = lower(codigo)),
                ADD CONSTRAINT permisos_codigo_formato_check
                    CHECK (
                        codigo ~
                        '^[a-z][a-z0-9_-]*([.][a-z][a-z0-9_-]*)+$'
                    ),
                ADD CONSTRAINT permisos_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT permisos_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT permisos_modulo_no_vacio_check
                    CHECK (btrim(modulo) <> ''),
                ADD CONSTRAINT permisos_modulo_sin_espacios_exteriores_check
                    CHECK (modulo = btrim(modulo)),
                ADD CONSTRAINT permisos_modulo_minusculas_check
                    CHECK (modulo = lower(modulo)),
                ADD CONSTRAINT permisos_modulo_formato_check
                    CHECK (modulo ~ '^[a-z][a-z0-9_-]*$')
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX permisos_codigo_lower_unique
             ON permisos (lower(codigo))'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS permisos_codigo_lower_unique'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE IF EXISTS permisos
                DROP CONSTRAINT IF EXISTS permisos_codigo_no_vacio_check,
                DROP CONSTRAINT IF EXISTS permisos_codigo_sin_espacios_exteriores_check,
                DROP CONSTRAINT IF EXISTS permisos_codigo_minusculas_check,
                DROP CONSTRAINT IF EXISTS permisos_codigo_formato_check,
                DROP CONSTRAINT IF EXISTS permisos_nombre_no_vacio_check,
                DROP CONSTRAINT IF EXISTS permisos_nombre_sin_espacios_exteriores_check,
                DROP CONSTRAINT IF EXISTS permisos_modulo_no_vacio_check,
                DROP CONSTRAINT IF EXISTS permisos_modulo_sin_espacios_exteriores_check,
                DROP CONSTRAINT IF EXISTS permisos_modulo_minusculas_check,
                DROP CONSTRAINT IF EXISTS permisos_modulo_formato_check
            SQL);

        Schema::dropIfExists('permisos');
    }
};
