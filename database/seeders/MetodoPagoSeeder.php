<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MetodoPagoSeeder extends Seeder
{
    private const METODOS = [
        'efectivo' => [
            'nombre' => 'Efectivo',
            'afecta_efectivo' => true,
            'orden_presentacion' => 10,
        ],
        'debito' => [
            'nombre' => 'Débito',
            'afecta_efectivo' => false,
            'orden_presentacion' => 20,
        ],
        'credito' => [
            'nombre' => 'Crédito',
            'afecta_efectivo' => false,
            'orden_presentacion' => 30,
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $codigos = array_keys(self::METODOS);
            $existentes = DB::table('metodos_pago')
                ->whereIn('codigo', $codigos)
                ->lockForUpdate()
                ->get()
                ->keyBy('codigo');

            foreach ($existentes as $codigo => $metodo) {
                $esperado = self::METODOS[$codigo]['afecta_efectivo'];

                if ($this->databaseBoolean($metodo->afecta_efectivo) !== $esperado) {
                    throw new RuntimeException(
                        "El método de pago [{$codigo}] tiene una configuración incompatible con su efecto en caja."
                    );
                }
            }

            $faltantes = array_values(array_diff($codigos, $existentes->keys()->all()));

            foreach ($faltantes as $codigo) {
                $nombre = self::METODOS[$codigo]['nombre'];
                $conflicto = DB::table('metodos_pago')
                    ->whereRaw('lower(nombre) = lower(?)', [$nombre])
                    ->lockForUpdate()
                    ->first(['codigo']);

                if ($conflicto !== null) {
                    throw new RuntimeException(
                        "No se puede crear el método de pago [{$codigo}] porque su nombre oficial ya está en uso."
                    );
                }
            }

            foreach ($faltantes as $codigo) {
                DB::table('metodos_pago')->insert([
                    'codigo' => $codigo,
                    'nombre' => self::METODOS[$codigo]['nombre'],
                    'afecta_efectivo' => self::METODOS[$codigo]['afecta_efectivo'],
                    'orden_presentacion' => self::METODOS[$codigo]['orden_presentacion'],
                    'activo' => true,
                ]);
            }
        });
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't'
            || $value === 'true';
    }
}
