<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Exceptions\Inventory\InventoryDomainException;
use App\Support\CommercialDate;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CashSessionGuard
{
    public static function openRegisterQuery(): Builder
    {
        return DB::table('sesiones_caja')
            ->join('cajas', 'cajas.id', '=', 'sesiones_caja.caja_id')
            ->whereNull('sesiones_caja.cerrada_en')
            ->where('cajas.activo', true);
    }

    public static function operationalRegisterQuery(string $today): Builder
    {
        return self::openRegisterQuery()->where('sesiones_caja.fecha_operativa', $today);
    }

    public static function operationalPrincipalQuery(string $today): Builder
    {
        return self::operationalRegisterQuery($today)->where('cajas.codigo', 'principal');
    }

    public static function assertOpen(object $session, ?DateTimeInterface $at = null): void
    {
        self::assertUnclosed($session);
        $date = self::operatingDate($session);
        if ($date === null) {
            throw new InventoryDomainException('La sesión no tiene fecha operativa. Solicita a un dueño que la revise.');
        }
        $operatingDay = $at === null ? CommercialDate::today() : CommercialDate::fromInstant($at);
        if ($date !== $operatingDay) {
            throw new InventoryDomainException('La sesión pertenece a otra fecha operativa. Se requiere revisión del dueño; no se trasladarán saldos automáticamente.');
        }
    }

    public static function canClose(object $session, string $today): bool
    {
        $date = self::operatingDate($session);

        return $session->cerrada_en === null && $date !== null && $date <= $today && $session->fondo_autorizado_id !== null;
    }

    public static function assertClosable(object $session, string $today): void
    {
        self::assertUnclosed($session);
        if (! self::canClose($session, $today)) {
            throw new InventoryDomainException('La sesión requiere una fecha operativa no futura y un fondo autorizado trazable antes de su cierre.');
        }
    }

    private static function assertUnclosed(object $session): void
    {
        if ($session->cerrada_en !== null) {
            throw new InventoryDomainException('La sesión está cerrada. No se puede modificar su dinero; una compensación posterior requiere otro flujo autorizado.');
        }
    }

    private static function operatingDate(object $session): ?string
    {
        $date = $session->fecha_operativa ?? null;

        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;
    }
}
