<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Caja;
use App\Models\FondoCajaAutorizado;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CashFundService
{
    private const OPENING_MIGRATION = '2026_11_08_000047_add_independent_cash_opening_funds';

    public function __construct(private readonly EffectivePermissionResolver $permissions) {}

    public function authorizeInitial(User $actor, int $registerId, string $date, string $amount, string $reason, string $uuid): FondoCajaAutorizado
    {
        return $this->authorize($actor, $registerId, $date, $amount, $reason, $uuid, 'inicial');
    }

    public function authorizeOpening(User $actor, int $registerId, string $date, string $amount, string $reason, string $uuid): FondoCajaAutorizado
    {
        return $this->authorize($actor, $registerId, $date, $amount, $reason, $uuid, 'apertura');
    }

    private function authorize(User $actor, int $registerId, string $date, string $amount, string $reason, string $uuid, string $origin): FondoCajaAutorizado
    {
        $this->permissions->authorize($actor, PermissionCode::CashFundsAuthorize);
        $amount = Decimal::money($amount);
        $reason = trim($reason);
        $this->validateDate($date);
        if (! Str::isUuid($uuid) || Decimal::sign($amount) < 0 || $reason === '' || mb_strlen($reason) > 5000) {
            throw new InvalidArgumentException('Revisa el monto, motivo y confirmación del fondo.');
        }

        return DB::transaction(function () use ($actor, $registerId, $date, $amount, $reason, $uuid, $origin): FondoCajaAutorizado {
            $register = Caja::query()->lockForUpdate()->find($registerId);
            if ($register === null || ! $register->activo) {
                throw new InventoryDomainException('La caja no está activa.');
            }
            if ($origin === 'apertura' && $register->codigo !== 'principal') {
                throw new InventoryDomainException('La autorización de apertura corresponde únicamente a Caja principal.');
            }
            $existing = FondoCajaAutorizado::query()->where('clave_idempotencia', $uuid)->first();
            if ($existing !== null) {
                if ((int) $existing->caja_id !== $registerId || (int) $existing->usuario_autorizante_id !== (int) $actor->id || $existing->fecha_operativa->format('Y-m-d') !== $date || (string) $existing->monto !== $amount || $existing->motivo !== $reason || $existing->origen !== $origin) {
                    throw new IdempotencyConflictException('La confirmación ya corresponde a otro fondo.');
                }

                return $existing;
            }
            if ($origin === 'inicial') {
                if ($date < CommercialDate::today()) {
                    throw new InventoryDomainException('No se puede autorizar un primer fondo para una fecha vencida.');
                }
                if ($register->sesiones()->exists() || $register->fondosAutorizados()->exists()) {
                    throw new InventoryDomainException('El primer fondo ya fue configurado o existen sesiones. Se requiere revisión del dueño; no se reemplazará el historial.');
                }
                $authorizedAt = now('UTC');
            } else {
                if (! $this->openingSchemaReady()) {
                    throw new InventoryDomainException('La autorización de apertura aún no está habilitada en este entorno.');
                }
                if ($register->sesiones()->whereNull('cerrada_en')->exists()) {
                    throw new InventoryDomainException('Caja principal ya tiene una sesión abierta. Si es de otra jornada, revisa el cierre pendiente.');
                }
                if ($register->fondosAutorizados()->where('fecha_operativa', $date)->exists()) {
                    throw new InventoryDomainException('Caja principal ya tiene un fondo autorizado para esa jornada.');
                }
                if (! $register->sesiones()->exists() && ! $register->fondosAutorizados()->exists()) {
                    throw new InventoryDomainException('Para una caja sin historial, utiliza Autorizar primer fondo.');
                }
                $authorizedAt = now('UTC');
                if ($date !== CommercialDate::fromInstant($authorizedAt)) {
                    throw new InventoryDomainException('Solo puedes autorizar el fondo de la jornada actual de Santiago.');
                }
            }
            $id = DB::table('fondos_caja_autorizados')->insertGetId([
                'caja_id' => $registerId, 'fecha_operativa' => $date, 'monto' => $amount,
                'usuario_autorizante_id' => $actor->id, 'origen' => $origin, 'motivo' => $reason,
                'clave_idempotencia' => $uuid, 'created_at' => $authorizedAt,
            ]);

            return FondoCajaAutorizado::query()->findOrFail($id);
        });
    }

    public function initialRegisters(): Collection
    {
        return Caja::query()->where('activo', true)->doesntHave('sesiones')
            ->doesntHave('fondosAutorizados')->orderBy('nombre')->get();
    }

    public function openingRegister(string $date): ?Caja
    {
        if (! $this->openingSchemaReady()) {
            return null;
        }

        return Caja::query()->where('codigo', 'principal')->where('activo', true)
            ->where(fn ($query) => $query->has('sesiones')->orHas('fondosAutorizados'))
            ->whereDoesntHave('sesiones', fn ($query) => $query->whereNull('cerrada_en'))
            ->whereDoesntHave('fondosAutorizados', fn ($query) => $query->where('fecha_operativa', $date))
            ->first();
    }

    public function history(User $actor): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::CashFundsAuthorize);

        return FondoCajaAutorizado::query()->whereHas('caja', fn ($query) => $query->where('codigo', 'principal'))
            ->with('autorizante:id,nombre,apellido')->orderByDesc('fecha_operativa')->orderByDesc('id')
            ->paginate(10, pageName: 'fondos_page')->withQueryString();
    }

    private function openingSchemaReady(): bool
    {
        return DB::table('migrations')->where('migration', self::OPENING_MIGRATION)->exists();
    }

    public function available(int $registerId): ?FondoCajaAutorizado
    {
        return FondoCajaAutorizado::query()->with('autorizante')
            ->where('caja_id', $registerId)->where('fecha_operativa', CommercialDate::today())
            ->whereNull('utilizada_en')->first();
    }

    public function validateDate(string $date): void
    {
        CommercialDate::utcRange($date);
    }
}
