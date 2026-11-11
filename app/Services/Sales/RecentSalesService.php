<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\PaymentType;
use App\Enums\SaleStatus;
use App\Models\DetalleVenta;
use App\Models\Venta;
use App\Services\Cash\CashSessionGuard;
use App\Support\CommercialDate;
use App\Support\InventoryQuantity;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder as SaleQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RecentSalesService
{
    private const VISIBLE_LINES = 3;

    public function current(?string $expectedSession = null): ?array
    {
        $today = CommercialDate::today();
        $session = $this->principalSession($today)->value('sesiones_caja.id');
        if ($session === null || ($expectedSession !== null && (string) $session !== $expectedSession)) {
            return null;
        }

        $activeSession = $this->principalSession($today)->where('sesiones_caja.id', $session)->select('sesiones_caja.id');
        $completedSales = Venta::query()
            ->whereIn('ventas.sesion_caja_id', $activeSession)
            ->where('ventas.fecha_comercial', $today)
            ->where('ventas.estado', SaleStatus::Completed->value);

        $sales = (clone $completedSales)
            ->with([
                'usuario:id,nombre,apellido',
                'detalles' => fn ($query) => $query->select('id', 'venta_id', 'articulo_nombre_snapshot', 'cantidad', 'unidad_simbolo_snapshot')
                    ->orderBy('id')->limit(self::VISIBLE_LINES + 1),
            ])
            ->orderByDesc('completada_en')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'usuario_id', 'pedido_id', 'numero_diario', 'completada_en', 'total']);

        $directIds = $sales->whereNull('pedido_id')->modelKeys();
        $orderIds = $sales->whereNotNull('pedido_id')->pluck('pedido_id')->all();
        $directMethods = $directIds === [] ? collect() : DB::table('pagos_venta as p')
            ->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
            ->whereIn('p.venta_id', $directIds)
            ->where('p.tipo', PaymentType::Charge->value)
            ->distinct()
            ->get(['p.venta_id', 'p.metodo_pago_id', 'm.nombre'])
            ->groupBy('venta_id');
        $orderMethods = $orderIds === [] ? collect() : DB::table('pagos_pedido as p')
            ->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
            ->whereIn('p.pedido_id', $orderIds)
            ->groupBy('p.pedido_id', 'p.metodo_pago_id', 'm.nombre')
            ->havingRaw('SUM(CASE WHEN p.tipo = ? THEN p.monto ELSE -p.monto END) > 0', [PaymentType::Charge->value])
            ->get(['p.pedido_id', 'm.nombre'])
            ->groupBy('pedido_id');

        return [
            'session' => (string) $session,
            'collected' => $this->collectionTotals($completedSales, $activeSession, $today),
            'rows' => $sales->map(static function (Venta $sale) use ($directMethods, $orderMethods): array {
                $methods = $sale->pedido_id === null ? $directMethods->get($sale->id) : $orderMethods->get($sale->pedido_id);
                $products = $sale->detalles->take(self::VISIBLE_LINES)->map(
                    static fn (DetalleVenta $line): string => InventoryQuantity::format((string) $line->cantidad, $line->unidad_simbolo_snapshot)
                        .' de '.$line->articulo_nombre_snapshot,
                );

                return [
                    'number' => $sale->numero_diario,
                    'time' => $sale->completada_en->timezone('America/Santiago')->format('H:i'),
                    'products' => $products->isEmpty() && $sale->pedido_id !== null
                        ? 'Pedido N.º '.$sale->pedido_id
                        : $products->implode(' · '),
                    'more' => $sale->detalles->count() > self::VISIBLE_LINES,
                    'operator' => trim($sale->usuario->nombre.' '.$sale->usuario->apellido),
                    'payment' => ($methods?->count() ?? 0) > 1 ? 'Pago mixto' : ($methods?->first()->nombre ?? 'Sin medio registrado'),
                    'total' => Money::clp((string) $sale->total),
                ];
            })->all(),
        ];
    }

    /** @return array{cash: string, digital: string} */
    private function collectionTotals(SaleQuery $completedSales, Builder $activeSession, string $today): array
    {
        $directPayments = $completedSales
            ->whereNull('ventas.pedido_id')
            ->join('pagos_venta as p', fn ($join) => $join->on('p.venta_id', '=', 'ventas.id')
                ->on('p.sesion_caja_id', '=', 'ventas.sesion_caja_id'))
            ->select('p.metodo_pago_id', 'p.tipo', 'p.monto')
            ->toBase();

        $day = CommercialDate::utcRange($today);
        $orderPayments = DB::table('pagos_pedido as p')
            ->whereIn('p.sesion_caja_id', $activeSession)
            ->where('p.ocurrido_en', '>=', $day['start'])
            ->where('p.ocurrido_en', '<', $day['end'])
            ->select('p.metodo_pago_id', 'p.tipo', 'p.monto');

        $totals = DB::query()->fromSub($directPayments->unionAll($orderPayments), 'p')
            ->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
            ->whereIn('m.codigo', ['efectivo', 'debito', 'credito'])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN p.tipo = ? THEN p.monto ELSE -p.monto END) FILTER (WHERE m.codigo = ?), 0) AS cash,
                 COALESCE(SUM(CASE WHEN p.tipo = ? THEN p.monto ELSE -p.monto END) FILTER (WHERE m.codigo IN (?, ?)), 0) AS digital',
                [PaymentType::Charge->value, 'efectivo', PaymentType::Charge->value, 'debito', 'credito'],
            )->first();

        return [
            'cash' => Money::clpTotal((string) $totals->cash),
            'digital' => Money::clpTotal((string) $totals->digital),
        ];
    }

    private function principalSession(string $today): Builder
    {
        return CashSessionGuard::operationalRegisterQuery($today)
            ->whereRaw('lower(cajas.codigo) = ?', ['principal']);
    }
}
