<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50);
            $table->string('nombre', 100);
            $table->string('simbolo', 20);
            $table->string('magnitud', 20);
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE unidades_medida
                ADD CONSTRAINT unidades_medida_codigo_no_vacio_check
                    CHECK (btrim(codigo) <> ''),
                ADD CONSTRAINT unidades_medida_codigo_sin_espacios_exteriores_check
                    CHECK (codigo = btrim(codigo)),
                ADD CONSTRAINT unidades_medida_codigo_minusculas_check
                    CHECK (codigo = lower(codigo)),
                ADD CONSTRAINT unidades_medida_codigo_formato_check
                    CHECK (codigo ~ '^[a-z][a-z0-9_-]*$'),
                ADD CONSTRAINT unidades_medida_nombre_no_vacio_check
                    CHECK (btrim(nombre) <> ''),
                ADD CONSTRAINT unidades_medida_nombre_sin_espacios_exteriores_check
                    CHECK (nombre = btrim(nombre)),
                ADD CONSTRAINT unidades_medida_simbolo_no_vacio_check
                    CHECK (btrim(simbolo) <> ''),
                ADD CONSTRAINT unidades_medida_simbolo_sin_espacios_exteriores_check
                    CHECK (simbolo = btrim(simbolo)),
                ADD CONSTRAINT unidades_medida_magnitud_valida_check
                    CHECK (magnitud IN ('unidad', 'masa', 'volumen', 'otra'))
            SQL);

        DB::statement(
            'CREATE UNIQUE INDEX unidades_medida_codigo_lower_unique
             ON unidades_medida (lower(codigo))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX unidades_medida_nombre_lower_unique
             ON unidades_medida (lower(nombre))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX unidades_medida_simbolo_lower_unique
             ON unidades_medida (lower(simbolo))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
    }
};
