<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rol_id');
            $table->string('nombre', 100);
            $table->string('apellido', 100);
            $table->string('nombre_usuario', 50);
            $table->string('correo', 254)->nullable();
            $table->string('password', 255);
            $table->boolean('activo')->default(true);
            $table->timestampTz('ultimo_acceso', 6)->nullable();
            $table->rememberToken();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'rol_id',
                'usuarios_rol_id_foreign'
            )
                ->references('id')
                ->on('roles')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->index(
                'rol_id',
                'usuarios_rol_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE usuarios
                ADD CONSTRAINT usuarios_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT usuarios_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT usuarios_apellido_no_vacio_check
                    CHECK (btrim(apellido) <> ''),
                ADD CONSTRAINT usuarios_apellido_sin_espacios_exteriores_check
                    CHECK (apellido = btrim(apellido)),
                ADD CONSTRAINT usuarios_nombre_usuario_no_vacio_check
                    CHECK (btrim(nombre_usuario) <> ''),
                ADD CONSTRAINT usuarios_nombre_usuario_sin_espacios_check
                    CHECK (nombre_usuario = btrim(nombre_usuario)),
                ADD CONSTRAINT usuarios_nombre_usuario_formato_check
                    CHECK (
                        nombre_usuario ~
                        '^[A-Za-z0-9][A-Za-z0-9._-]*$'
                    ),
                ADD CONSTRAINT usuarios_correo_valido_check
                    CHECK (
                        correo IS NULL
                        OR (
                            btrim(correo) <> ''
                            AND correo = btrim(correo)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX usuarios_nombre_usuario_lower_unique
             ON usuarios (lower(nombre_usuario))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX usuarios_correo_lower_unique
             ON usuarios (lower(correo))
             WHERE correo IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS usuarios_correo_lower_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS usuarios_nombre_usuario_lower_unique'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE IF EXISTS usuarios
                DROP CONSTRAINT IF EXISTS usuarios_nombre_no_vacio_check,
                DROP CONSTRAINT IF EXISTS usuarios_nombre_sin_espacios_exteriores_check,
                DROP CONSTRAINT IF EXISTS usuarios_apellido_no_vacio_check,
                DROP CONSTRAINT IF EXISTS usuarios_apellido_sin_espacios_exteriores_check,
                DROP CONSTRAINT IF EXISTS usuarios_nombre_usuario_no_vacio_check,
                DROP CONSTRAINT IF EXISTS usuarios_nombre_usuario_sin_espacios_check,
                DROP CONSTRAINT IF EXISTS usuarios_nombre_usuario_formato_check,
                DROP CONSTRAINT IF EXISTS usuarios_correo_valido_check
            SQL);

        Schema::dropIfExists('usuarios');
    }
};
