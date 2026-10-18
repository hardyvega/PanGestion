<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\MovimientoInventario;
use App\Models\StockDiario;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

final class StockDailyRebuilder
{
    public function rebuild(iterable $articleIds, string $fromCommercialDate): void
    {
        $dailyModel = new StockDiario();
        $connection = $dailyModel->getConnection();

        if ($connection->transactionLevel() <= 0) {
            throw new LogicException('La reconstruccion requiere una transaccion externa activa.');
        }

        $ids = [];

        foreach ($articleIds as $id) {
            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Los IDs deben ser enteros positivos.');
            }

            $ids[$id] = true;
        }

        ksort($ids, SORT_NUMERIC);
        $ids = array_keys($ids);
        CommercialDate::utcRange($fromCommercialDate);

        if ($ids === []) {
            return;
        }

        $ledger = $connection->table((new MovimientoInventario())->getTable())
            ->whereIn('articulo_id', $ids)
            ->orderBy('articulo_id', 'asc')
            ->orderBy('ocurrido_en', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'articulo_id', 'cantidad_delta', 'stock_posterior', 'ocurrido_en'])
            ->groupBy('articulo_id');
        $existing = $connection->table($dailyModel->getTable())
            ->whereIn('articulo_id', $ids)
            ->where('fecha_comercial', '>=', $fromCommercialDate)
            ->orderBy('articulo_id', 'asc')
            ->orderBy('fecha_comercial', 'asc')
            ->lockForUpdate()
            ->get(['articulo_id', 'fecha_comercial']);
        $lastProjectionDate = [];

        // Existing projection dates extend the horizon; their stock never feeds it.
        foreach ($existing as $row) {
            $lastProjectionDate[$row->articulo_id] = $row->fecha_comercial;
        }

        $today = CommercialDate::today();
        $calculatedAt = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $rows = [];

        foreach ($ids as $id) {
            $movements = $ledger->get($id);

            if ($movements === null || $movements->isEmpty()) {
                continue;
            }

            $opening = '0.000';
            $watermark = null;
            $days = [];
            $lastLedgerDate = '';

            foreach ($movements as $movement) {
                $date = CommercialDate::fromInstant(CarbonImmutable::parse($movement->ocurrido_en, 'UTC'));
                $lastLedgerDate = max($lastLedgerDate, $date);

                if ($date < $fromCommercialDate) {
                    $opening = Decimal::quantity($movement->stock_posterior);
                    $watermark = $movement->id;

                    continue;
                }

                $days[$date] ??= ['entries' => '0.000', 'outputs' => '0.000', 'watermark' => null];
                $delta = Decimal::quantity($movement->cantidad_delta);

                if (Decimal::sign($delta) > 0) {
                    $days[$date]['entries'] = Decimal::add($days[$date]['entries'], $delta, 3);
                } else {
                    $days[$date]['outputs'] = Decimal::add(
                        $days[$date]['outputs'],
                        Decimal::subtract('0.000', $delta, 3),
                        3,
                    );
                }

                $days[$date]['watermark'] = $movement->id;
            }

            $horizon = max($today, $lastProjectionDate[$id] ?? $lastLedgerDate, $lastLedgerDate);
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $fromCommercialDate, 'UTC');

            while (($date = $day->format('Y-m-d')) <= $horizon) {
                $entries = Decimal::quantity($days[$date]['entries'] ?? '0.000');
                $outputs = Decimal::quantity($days[$date]['outputs'] ?? '0.000');
                $closing = Decimal::quantity(Decimal::subtract(Decimal::add($opening, $entries, 3), $outputs, 3));

                if (Decimal::sign($opening) < 0 || Decimal::sign($closing) < 0) {
                    throw new InventoryDomainException('El ledger deriva un stock diario negativo.');
                }

                $watermark = $days[$date]['watermark'] ?? $watermark;
                $rows[] = [
                    'articulo_id' => $id,
                    'fecha_comercial' => $date,
                    'stock_apertura' => $opening,
                    'total_entradas' => $entries,
                    'total_salidas' => $outputs,
                    'stock_cierre' => $closing,
                    'calculado_en' => $calculatedAt,
                    'ultimo_movimiento_id' => $watermark,
                    'created_at' => $calculatedAt,
                    'updated_at' => $calculatedAt,
                ];
                $opening = $closing;
                $day = $day->addDay();
            }
        }

        foreach (array_chunk($rows, 500) as $batch) {
            $connection->table($dailyModel->getTable())->upsert(
                $batch,
                ['articulo_id', 'fecha_comercial'],
                [
                    'stock_apertura',
                    'total_entradas',
                    'total_salidas',
                    'stock_cierre',
                    'calculado_en',
                    'ultimo_movimiento_id',
                    'updated_at',
                ],
            );
        }
    }
}
