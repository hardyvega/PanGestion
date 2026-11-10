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
            $table->string('cliente_nombre', 150)->nullable();
            $table->text('cliente_telefono_cifrado')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE pedidos
                ALTER COLUMN cliente_id DROP NOT NULL,
                ADD CONSTRAINT pedidos_contacto_consistente_check CHECK (
                    (cliente_id IS NOT NULL AND cliente_nombre IS NULL AND cliente_telefono_cifrado IS NULL)
                    OR (
                        cliente_id IS NULL
                        AND cliente_nombre IS NOT NULL AND btrim(cliente_nombre) <> ''
                        AND cliente_telefono_cifrado IS NOT NULL AND btrim(cliente_telefono_cifrado) <> ''
                        AND entrega_programada_en IS NOT NULL
                    )
                );

            ALTER TABLE ventas DROP CONSTRAINT ventas_origen_pedido_consistente_check;

            CREATE OR REPLACE FUNCTION ventas_validar_contacto_pedido() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM pedidos WHERE id = NEW.pedido_id
                    AND (cliente_id IS NULL) <> (NEW.cliente_id IS NULL)
                ) THEN
                    RAISE EXCEPTION 'El vínculo de cliente de la venta es incompatible con su pedido'
                        USING ERRCODE = '23514', CONSTRAINT = 'ventas_origen_pedido_consistente_check';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER ventas_contacto_pedido_check
                BEFORE INSERT OR UPDATE OF pedido_id, cliente_id ON ventas
                FOR EACH ROW WHEN (NEW.pedido_id IS NOT NULL)
                EXECUTE FUNCTION ventas_validar_contacto_pedido();
            SQL);
    }

    public function down(): void
    {
        // SET NOT NULL impide revertir si ya hay pedidos con contacto propio.
        DB::unprepared(<<<'SQL'
            ALTER TABLE pedidos ALTER COLUMN cliente_id SET NOT NULL;
            ALTER TABLE pedidos DROP CONSTRAINT pedidos_contacto_consistente_check;
            DROP TRIGGER ventas_contacto_pedido_check ON ventas;
            DROP FUNCTION ventas_validar_contacto_pedido();
            ALTER TABLE ventas ADD CONSTRAINT ventas_origen_pedido_consistente_check
                CHECK (pedido_id IS NULL OR cliente_id IS NOT NULL);
            SQL);

        Schema::table('pedidos', function (Blueprint $table): void {
            $table->dropColumn(['cliente_nombre', 'cliente_telefono_cifrado']);
        });
    }
};
