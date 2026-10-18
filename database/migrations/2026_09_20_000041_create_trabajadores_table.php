<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trabajadores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->nullable();
            $table->string('codigo', 50);
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->boolean('activo')->default(true);
            $table->date('fecha_ingreso');
            $table->date('fecha_termino')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign('usuario_id', 'trabajadores_usuario_id_foreign')
                ->references('id')->on('usuarios')
                ->onUpdate('no action')->onDelete('restrict');
            $table->unique('usuario_id', 'trabajadores_usuario_id_unique');
            $table->unique('codigo', 'trabajadores_codigo_unique');
            $table->index(['activo', 'apellidos', 'nombres', 'id'], 'trabajadores_activo_nombre_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE trabajadores
                ADD CONSTRAINT trabajadores_codigo_valido_check CHECK (
                    btrim(codigo) <> '' AND codigo = btrim(codigo)
                ),
                ADD CONSTRAINT trabajadores_nombres_validos_check CHECK (
                    btrim(nombres) <> '' AND nombres = btrim(nombres)
                ),
                ADD CONSTRAINT trabajadores_apellidos_validos_check CHECK (
                    btrim(apellidos) <> '' AND apellidos = btrim(apellidos)
                ),
                ADD CONSTRAINT trabajadores_fechas_validas_check CHECK (
                    fecha_termino IS NULL OR fecha_termino >= fecha_ingreso
                )
            SQL);

        DB::statement('CREATE UNIQUE INDEX trabajadores_codigo_upper_unique ON trabajadores (upper(codigo))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trabajadores_codigo_upper_unique');
        Schema::dropIfExists('trabajadores');
    }
};
