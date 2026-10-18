<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\HourlyPayReportQuery;
use App\Enums\AttendanceStatus;
use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class HourlyPayReportService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function report(User $actor, HourlyPayReportQuery $query): array
    {
        $this->permissions->authorize($actor, PermissionCode::HourlyPayView);
        $from = CarbonImmutable::instance($query->from)->setTimezone('UTC');
        $until = CarbonImmutable::instance($query->until)->setTimezone('UTC');
        $rows = DB::table('jornadas_trabajo')
            ->join('trabajadores', 'trabajadores.id', '=', 'jornadas_trabajo.trabajador_id')
            ->where('jornadas_trabajo.estado', AttendanceStatus::Closed->value)
            ->where('jornadas_trabajo.iniciada_en', '>=', $from)
            ->where('jornadas_trabajo.iniciada_en', '<', $until)
            ->orderBy('trabajadores.apellidos')->orderBy('trabajadores.nombres')
            ->orderBy('jornadas_trabajo.iniciada_en')->orderBy('jornadas_trabajo.id')
            ->get([
                'jornadas_trabajo.id',
                'jornadas_trabajo.trabajador_id',
                'trabajadores.codigo',
                'trabajadores.nombres',
                'trabajadores.apellidos',
                'jornadas_trabajo.iniciada_en',
                'jornadas_trabajo.finalizada_en',
                'jornadas_trabajo.minutos_trabajados',
                'jornadas_trabajo.valor_hora_snapshot',
                'jornadas_trabajo.monto_calculado',
            ]);

        $items = [];
        $totalMinutes = 0;
        $totalAmount = '0.00';
        foreach ($rows as $row) {
            $minutes = (int) $row->minutos_trabajados;
            $amount = Decimal::money((string) $row->monto_calculado);
            $totalMinutes += $minutes;
            $totalAmount = Decimal::add($totalAmount, $amount, 2);
            $items[] = [
                'jornada_id' => (int) $row->id,
                'trabajador_id' => (int) $row->trabajador_id,
                'codigo' => $row->codigo,
                'nombres' => $row->nombres,
                'apellidos' => $row->apellidos,
                'iniciada_en' => CarbonImmutable::parse($row->iniciada_en, 'UTC'),
                'finalizada_en' => CarbonImmutable::parse($row->finalizada_en, 'UTC'),
                'minutos_trabajados' => $minutes,
                'horas' => Decimal::round(bcdiv((string) $minutes, '60', 4), 2),
                'valor_hora_snapshot' => Decimal::money((string) $row->valor_hora_snapshot),
                'monto_calculado' => $amount,
            ];
        }

        return [
            'desde' => $from,
            'hasta' => $until,
            'items' => $items,
            'trabajadores' => count(array_unique(array_column($items, 'trabajador_id'))),
            'jornadas' => count($items),
            'total_minutos' => $totalMinutes,
            'total_horas' => Decimal::round(bcdiv((string) $totalMinutes, '60', 4), 2),
            'total_monto' => $totalAmount,
        ];
    }
}
