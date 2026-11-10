<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cierres_caja', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sesion_caja_id')->unique()->constrained('sesiones_caja')->restrictOnDelete();
            $table->foreignId('usuario_confirmador_id')->constrained('usuarios')->restrictOnDelete();
            $table->uuid('clave_idempotencia')->unique();
            $table->date('fecha_operativa');
            $table->date('fecha_siguiente');
            foreach (['esperado_antes_retiro', 'contado_antes_retiro', 'diferencia', 'retiro_final', 'fondo_remanente'] as $column) {
                $table->decimal($column, 14, 2);
            }
            $table->text('observacion')->nullable();
            $table->jsonb('resumen');
            $table->timestampTz('confirmado_en', 6);
            $table->timestampTz('created_at', 6)->useCurrent();
        });
        Schema::create('fondos_caja_autorizados', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('caja_id')->constrained('cajas')->restrictOnDelete();
            $table->date('fecha_operativa');
            $table->decimal('monto', 14, 2);
            $table->foreignId('usuario_autorizante_id')->constrained('usuarios')->restrictOnDelete();
            $table->string('origen', 20);
            $table->foreignId('cierre_origen_id')->nullable()->unique()->constrained('cierres_caja')->restrictOnDelete();
            $table->uuid('clave_idempotencia')->unique();
            $table->text('motivo');
            $table->timestampTz('utilizada_en', 6)->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['caja_id', 'fecha_operativa']);
        });
        Schema::table('sesiones_caja', function (Blueprint $table): void {
            $table->foreignId('fondo_autorizado_id')->nullable()->unique()->constrained('fondos_caja_autorizados')->restrictOnDelete();
            $table->date('fecha_operativa')->nullable();
            $table->uuid('clave_idempotencia_apertura')->nullable()->unique();
        });
        Schema::table('movimientos_caja', function (Blueprint $table): void {
            $table->foreignId('cierre_caja_id')->nullable()->unique()->constrained('cierres_caja')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE cierres_caja ADD CONSTRAINT cierres_caja_importes_check CHECK (
                esperado_antes_retiro <> 'NaN'::numeric AND diferencia <> 'NaN'::numeric
                AND contado_antes_retiro >= 0 AND contado_antes_retiro <> 'NaN'::numeric
                AND retiro_final >= 0 AND retiro_final <> 'NaN'::numeric
                AND fondo_remanente >= 0 AND fondo_remanente <> 'NaN'::numeric
                AND contado_antes_retiro = retiro_final + fondo_remanente
                AND diferencia = contado_antes_retiro - esperado_antes_retiro
                AND fecha_siguiente > fecha_operativa
                AND (diferencia = 0 OR (observacion IS NOT NULL AND btrim(observacion) <> ''))
                AND jsonb_typeof(resumen) = 'object'
            );
            ALTER TABLE fondos_caja_autorizados ADD CONSTRAINT fondos_caja_origen_check CHECK (
                monto >= 0 AND monto <> 'NaN'::numeric AND btrim(motivo) <> ''
                AND ((origen = 'inicial' AND cierre_origen_id IS NULL) OR (origen = 'cierre' AND cierre_origen_id IS NOT NULL))
                AND (utilizada_en IS NULL OR utilizada_en >= created_at)
            );
            CREATE UNIQUE INDEX fondos_caja_unico_inicial ON fondos_caja_autorizados(caja_id) WHERE origen = 'inicial';
            ALTER TABLE sesiones_caja ADD CONSTRAINT sesiones_caja_fondo_consistente_check CHECK (
                (fondo_autorizado_id IS NULL AND fecha_operativa IS NULL AND clave_idempotencia_apertura IS NULL)
                OR (fondo_autorizado_id IS NOT NULL AND fecha_operativa IS NOT NULL AND clave_idempotencia_apertura IS NOT NULL)
            );
            ALTER TABLE movimientos_caja ADD CONSTRAINT movimientos_caja_retiro_cierre_check CHECK (
                cierre_caja_id IS NULL OR (tipo_movimiento = 'retiro' AND pago_venta_id IS NULL AND pago_pedido_id IS NULL)
            );
            CREATE OR REPLACE FUNCTION proteger_cierre_caja() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'El cierre confirmado es inmutable' USING ERRCODE = '23514';
            END $$;
            CREATE TRIGGER cierres_caja_inmutables BEFORE UPDATE OR DELETE ON cierres_caja
                FOR EACH ROW EXECUTE FUNCTION proteger_cierre_caja();
            CREATE OR REPLACE FUNCTION proteger_fondo_caja() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'La autorizacion de fondo es historica' USING ERRCODE = '23514';
                END IF;
                IF (to_jsonb(NEW) - 'utilizada_en') IS DISTINCT FROM (to_jsonb(OLD) - 'utilizada_en')
                    OR OLD.utilizada_en IS NOT NULL OR NEW.utilizada_en IS NULL THEN
                    RAISE EXCEPTION 'Solo puede consumirse una vez el fondo autorizado' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER fondos_caja_inmutables BEFORE UPDATE OR DELETE ON fondos_caja_autorizados
                FOR EACH ROW EXECUTE FUNCTION proteger_fondo_caja();
            CREATE OR REPLACE FUNCTION proteger_sesion_caja_confirmada() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.cerrada_en IS NOT NULL AND EXISTS(SELECT 1 FROM cierres_caja WHERE sesion_caja_id=OLD.id) THEN
                    RAISE EXCEPTION 'La sesion confirmada es inmutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER sesiones_caja_confirmadas BEFORE UPDATE OR DELETE ON sesiones_caja
                FOR EACH ROW EXECUTE FUNCTION proteger_sesion_caja_confirmada();
            CREATE OR REPLACE FUNCTION proteger_movimiento_caja_confirmada() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE destino bigint;
            BEGIN
                IF TG_OP = 'INSERT' THEN destino := NEW.sesion_caja_id; ELSE destino := OLD.sesion_caja_id; END IF;
                IF TG_OP = 'UPDATE' THEN
                    PERFORM 1 FROM sesiones_caja WHERE id IN (destino, NEW.sesion_caja_id) ORDER BY id FOR UPDATE;
                ELSE
                    PERFORM 1 FROM sesiones_caja WHERE id = destino FOR UPDATE;
                END IF;
                IF EXISTS(SELECT 1 FROM sesiones_caja s JOIN cierres_caja c ON c.sesion_caja_id=s.id WHERE s.id=destino AND s.cerrada_en IS NOT NULL) THEN
                    RAISE EXCEPTION 'No se pueden alterar movimientos de un cierre confirmado' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND EXISTS(SELECT 1 FROM sesiones_caja s JOIN cierres_caja c ON c.sesion_caja_id=s.id WHERE s.id=NEW.sesion_caja_id AND s.cerrada_en IS NOT NULL) THEN
                    RAISE EXCEPTION 'No se puede imputar a una sesion cerrada' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER movimientos_caja_confirmados BEFORE INSERT OR UPDATE OR DELETE ON movimientos_caja
                FOR EACH ROW EXECUTE FUNCTION proteger_movimiento_caja_confirmada();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER movimientos_caja_confirmados ON movimientos_caja; DROP FUNCTION proteger_movimiento_caja_confirmada(); DROP TRIGGER sesiones_caja_confirmadas ON sesiones_caja; DROP FUNCTION proteger_sesion_caja_confirmada(); DROP TRIGGER fondos_caja_inmutables ON fondos_caja_autorizados; DROP FUNCTION proteger_fondo_caja(); DROP TRIGGER cierres_caja_inmutables ON cierres_caja; DROP FUNCTION proteger_cierre_caja();');
        Schema::table('movimientos_caja', function (Blueprint $table): void {
            $table->dropForeign(['cierre_caja_id']);
            $table->dropColumn('cierre_caja_id');
        });
        Schema::table('sesiones_caja', function (Blueprint $table): void {
            $table->dropForeign(['fondo_autorizado_id']);
            $table->dropColumn(['fondo_autorizado_id', 'fecha_operativa', 'clave_idempotencia_apertura']);
        });
        Schema::dropIfExists('fondos_caja_autorizados');
        Schema::dropIfExists('cierres_caja');
    }
};
