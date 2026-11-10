<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceOriginConstraint("origen IN ('inicial', 'apertura')");
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE fondos_caja_autorizados IN ACCESS EXCLUSIVE MODE');
        if (DB::table('fondos_caja_autorizados')->where('origen', 'apertura')->exists()) {
            throw new RuntimeException('No se puede revertir la migración mientras existan fondos de apertura independientes.');
        }

        $this->replaceOriginConstraint("origen = 'inicial'");
    }

    private function replaceOriginConstraint(string $originsWithoutClosing): void
    {
        DB::statement(<<<SQL
            ALTER TABLE fondos_caja_autorizados
                DROP CONSTRAINT fondos_caja_origen_check,
                ADD CONSTRAINT fondos_caja_origen_check CHECK (
                    monto >= 0 AND monto <> 'NaN'::numeric AND btrim(motivo) <> ''
                    AND (($originsWithoutClosing AND cierre_origen_id IS NULL)
                        OR (origen = 'cierre' AND cierre_origen_id IS NOT NULL))
                    AND (utilizada_en IS NULL OR utilizada_en >= created_at)
                )
            SQL);
    }
};
