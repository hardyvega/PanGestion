<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50);
            $table->string('nombre', 100);
            $table->boolean('afecta_efectivo')->default(false);
            $table->smallInteger('orden_presentacion')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE metodos_pago
                ADD CONSTRAINT metodos_pago_codigo_no_vacio_check
                    CHECK (btrim(codigo) <> ''),
                ADD CONSTRAINT metodos_pago_codigo_sin_espacios_exteriores_check
                    CHECK (codigo = btrim(codigo)),
                ADD CONSTRAINT metodos_pago_codigo_minusculas_check
                    CHECK (codigo = lower(codigo)),
                ADD CONSTRAINT metodos_pago_codigo_formato_check
                    CHECK (codigo ~ '^[a-z][a-z0-9_-]*$'),
                ADD CONSTRAINT metodos_pago_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT metodos_pago_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT metodos_pago_orden_presentacion_no_negativo_check
                    CHECK (orden_presentacion >= 0)
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX metodos_pago_codigo_lower_unique
             ON metodos_pago (lower(codigo))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX metodos_pago_nombre_lower_unique
             ON metodos_pago (lower(nombre))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_pago');
    }
};
