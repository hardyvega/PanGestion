<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulo_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('articulo_id');
            $table->foreignId('proveedor_id');
            $table->string('codigo_articulo_proveedor', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->timestampTz('updated_at', 6)->useCurrent();

            $table->foreign(
                'articulo_id',
                'articulo_proveedor_articulo_id_foreign'
            )
                ->references('id')
                ->on('articulos')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->foreign(
                'proveedor_id',
                'articulo_proveedor_proveedor_id_foreign'
            )
                ->references('id')
                ->on('proveedores')
                ->onUpdate('no action')
                ->onDelete('restrict');

            $table->unique(
                ['articulo_id', 'proveedor_id'],
                'articulo_proveedor_articulo_id_proveedor_id_unique'
            );

            $table->index(
                'proveedor_id',
                'articulo_proveedor_proveedor_id_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE articulo_proveedor
                ADD CONSTRAINT articulo_proveedor_codigo_articulo_proveedor_valido_check
                    CHECK (
                        codigo_articulo_proveedor IS NULL
                        OR (
                            btrim(codigo_articulo_proveedor) <> ''
                            AND codigo_articulo_proveedor = btrim(codigo_articulo_proveedor)
                        )
                    )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('articulo_proveedor');
    }
};
