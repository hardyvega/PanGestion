<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50);
            $table->string('nombre', 150);
            $table->string('nombre_contacto', 150)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('correo', 254)->nullable();
            $table->text('observacion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE proveedores
                ADD CONSTRAINT proveedores_codigo_no_vacio_check
                    CHECK (btrim(codigo) <> ''),
                ADD CONSTRAINT proveedores_codigo_sin_espacios_exteriores_check
                    CHECK (codigo = btrim(codigo)),
                ADD CONSTRAINT proveedores_codigo_minusculas_check
                    CHECK (codigo = lower(codigo)),
                ADD CONSTRAINT proveedores_codigo_formato_check
                    CHECK (codigo ~ '^[a-z0-9][a-z0-9_-]*$'),
                ADD CONSTRAINT proveedores_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT proveedores_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT proveedores_nombre_contacto_valido_check
                    CHECK (
                        nombre_contacto IS NULL
                        OR (
                            btrim(nombre_contacto) <> ''
                            AND nombre_contacto = btrim(nombre_contacto)
                        )
                    ),
                ADD CONSTRAINT proveedores_telefono_valido_check
                    CHECK (
                        telefono IS NULL
                        OR (
                            btrim(telefono) <> ''
                            AND telefono = btrim(telefono)
                        )
                    ),
                ADD CONSTRAINT proveedores_correo_valido_check
                    CHECK (
                        correo IS NULL
                        OR (
                            btrim(correo) <> ''
                            AND correo = btrim(correo)
                        )
                    ),
                ADD CONSTRAINT proveedores_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX proveedores_codigo_lower_unique
             ON proveedores (lower(codigo))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
