<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->text('telefono_cifrado')->nullable();
            $table->string('telefono_busqueda_hash', 64)->nullable();
            $table->string('correo', 254)->nullable();
            $table->text('observacion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE clientes
                ADD CONSTRAINT clientes_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT clientes_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT clientes_telefono_cifrado_valido_check
                    CHECK (telefono_cifrado IS NULL OR telefono_cifrado <> ''),
                ADD CONSTRAINT clientes_telefono_busqueda_hash_formato_check
                    CHECK (
                        telefono_busqueda_hash IS NULL
                        OR telefono_busqueda_hash ~ '^[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT clientes_telefono_par_consistente_check
                    CHECK (
                        (
                            telefono_cifrado IS NULL
                            AND telefono_busqueda_hash IS NULL
                        )
                        OR (
                            telefono_cifrado IS NOT NULL
                            AND telefono_busqueda_hash IS NOT NULL
                        )
                    ),
                ADD CONSTRAINT clientes_correo_valido_check
                    CHECK (
                        correo IS NULL
                        OR (
                            btrim(correo) <> ''
                            AND correo = btrim(correo)
                        )
                    ),
                ADD CONSTRAINT clientes_observacion_valida_check
                    CHECK (
                        observacion IS NULL
                        OR (
                            btrim(observacion) <> ''
                            AND observacion = btrim(observacion)
                        )
                    )
            SQL);

        DB::statement(
            'CREATE INDEX clientes_telefono_busqueda_hash_index
             ON clientes (telefono_busqueda_hash)
             WHERE telefono_busqueda_hash IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
