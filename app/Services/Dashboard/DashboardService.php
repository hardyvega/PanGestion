<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Data\Dashboard\DashboardMetrics;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Enums\SaleStatus;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function summary(User $user): DashboardMetrics
    {
        $canViewFinancials = $this->permissions->allows($user, PermissionCode::SalesCreate);

        if (! $canViewFinancials) {
            return DashboardMetrics::withoutFinancialAccess();
        }

        $today = CommercialDate::today();
        $salesToday = $this->salesSummary($today, $today);
        $salesWeek = $salesMonth = null;

        if ($this->permissions->allows($user, PermissionCode::CashSessionsView)) {
            $todayDate = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'America/Santiago');
            $weekStart = $todayDate->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');
            $monthStart = $todayDate->startOfMonth()->format('Y-m-d');
            $salesWeek = $this->salesSummary($weekStart, $today)['amount'];
            $salesMonth = $this->salesSummary($monthStart, $today)['amount'];
        }

        return new DashboardMetrics(
            canViewFinancials: true,
            salesToday: $salesToday['amount'],
            salesTodayCount: $salesToday['count'],
            cashToday: $this->paymentNet('efectivo', CommercialDate::utcRange($today)),
            debitToday: $this->paymentNet('debito', CommercialDate::utcRange($today)),
            salesWeek: $salesWeek,
            salesMonth: $salesMonth,
        );
    }

    /** @return array{amount: string, count: int} */
    private function salesSummary(string $from, string $to): array
    {
        $summary = DB::table('ventas')
            ->where('estado', SaleStatus::Completed->value)
            ->whereBetween('fecha_comercial', [$from, $to])
            ->selectRaw('COUNT(*) AS sales_count, COALESCE(SUM(total), 0) AS sales_amount')
            ->first();

        return [
            'amount' => Decimal::money((string) ($summary?->sales_amount ?? '0.00')),
            'count' => (int) ($summary?->sales_count ?? 0),
        ];
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $range */
    private function paymentNet(string $paymentMethodCode, array $range): string
    {
        $direct = $this->directPaymentNet($paymentMethodCode, $range);
        $fromOrders = $this->orderPaymentNet($paymentMethodCode, $range);

        return Decimal::add($direct, $fromOrders, 2);
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $range */
    private function directPaymentNet(string $paymentMethodCode, array $range): string
    {
        $amount = DB::table('pagos_venta AS pagos')
            ->join('ventas', 'ventas.id', '=', 'pagos.venta_id')
            ->join('metodos_pago AS metodos', 'metodos.id', '=', 'pagos.metodo_pago_id')
            ->whereNull('ventas.pedido_id')
            ->where('metodos.codigo', $paymentMethodCode)
            ->where('pagos.ocurrido_en', '>=', $range['start'])
            ->where('pagos.ocurrido_en', '<', $range['end'])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN pagos.tipo = ? THEN pagos.monto WHEN pagos.tipo = ? THEN -pagos.monto ELSE 0 END), 0) AS amount',
                [PaymentType::Charge->value, PaymentType::Refund->value],
            )
            ->value('amount');

        return Decimal::money((string) ($amount ?? '0.00'));
    }

    /** @param array{start: CarbonImmutable, end: CarbonImmutable} $range */
    private function orderPaymentNet(string $paymentMethodCode, array $range): string
    {
        $amount = DB::table('pagos_pedido AS pagos')
            ->join('metodos_pago AS metodos', 'metodos.id', '=', 'pagos.metodo_pago_id')
            ->where('metodos.codigo', $paymentMethodCode)
            ->where('pagos.ocurrido_en', '>=', $range['start'])
            ->where('pagos.ocurrido_en', '<', $range['end'])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN pagos.tipo = ? THEN pagos.monto WHEN pagos.tipo = ? THEN -pagos.monto ELSE 0 END), 0) AS amount',
                [PaymentType::Charge->value, PaymentType::Refund->value],
            )
            ->value('amount');

        return Decimal::money((string) ($amount ?? '0.00'));
    }
}
