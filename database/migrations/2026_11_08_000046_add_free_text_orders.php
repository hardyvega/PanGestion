<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table): void {
            $table->string('modalidad', 8)->default('catalogo');
            $table->text('detalle_texto')->nullable();
            $table->text('especificaciones_texto')->nullable();
            $table->decimal('precio_total_acordado', 14, 2)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE pedidos ADD CONSTRAINT pedidos_modalidad_consistente_check CHECK (
                (modalidad = 'catalogo' AND detalle_texto IS NULL
                    AND especificaciones_texto IS NULL AND precio_total_acordado IS NULL)
                OR (
                    modalidad = 'libre' AND cliente_id IS NULL
                    AND detalle_texto IS NOT NULL AND detalle_texto ~ '[^[:space:]]'
                    AND char_length(detalle_texto) <= 2000
                    AND (especificaciones_texto IS NULL OR (
                        especificaciones_texto ~ '[^[:space:]]' AND char_length(especificaciones_texto) <= 2000
                    ))
                    AND precio_total_acordado IS NOT NULL AND precio_total_acordado > 0
                    AND precio_total_acordado <> 'NaN'::numeric
                    AND precio_total_acordado = trunc(precio_total_acordado)
                )
            );

            CREATE OR REPLACE FUNCTION pedidos_proteger_modalidad() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'pedidos' THEN
                    RAISE EXCEPTION 'La modalidad del pedido no puede cambiar' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (SELECT 1 FROM pedidos WHERE id = NEW.pedido_id AND modalidad = 'libre') THEN
                    RAISE EXCEPTION 'El pedido libre no admite líneas de catálogo' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER pedidos_modalidad_inmutable
                BEFORE UPDATE OF modalidad ON pedidos
                FOR EACH ROW WHEN (OLD.modalidad IS DISTINCT FROM NEW.modalidad)
                EXECUTE FUNCTION pedidos_proteger_modalidad();

            CREATE TRIGGER detalle_pedidos_solo_catalogo
                BEFORE INSERT OR UPDATE OF pedido_id ON detalle_pedidos
                FOR EACH ROW EXECUTE FUNCTION pedidos_proteger_modalidad();
            SQL);
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE pedidos IN ACCESS EXCLUSIVE MODE');
        if (DB::table('pedidos')->where('modalidad', 'libre')->exists()) {
            throw new RuntimeException('La reversión eliminaría datos de pedidos libres.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER detalle_pedidos_solo_catalogo ON detalle_pedidos;
            DROP TRIGGER pedidos_modalidad_inmutable ON pedidos;
            DROP FUNCTION pedidos_proteger_modalidad();
            ALTER TABLE pedidos DROP CONSTRAINT pedidos_modalidad_consistente_check;
            SQL);

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn(['modalidad', 'detalle_texto', 'especificaciones_texto', 'precio_total_acordado']);
        });
    }
};
