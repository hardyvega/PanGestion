<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FondoCajaAutorizado extends Model
{
    protected $table = 'fondos_caja_autorizados';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'fecha_operativa' => 'immutable_date', 'utilizada_en' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function autorizante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_autorizante_id');
    }

    public function sesion(): HasOne
    {
        return $this->hasOne(SesionCaja::class, 'fondo_autorizado_id');
    }

    public function cierreOrigen(): BelongsTo
    {
        return $this->belongsTo(CierreCaja::class, 'cierre_origen_id');
    }
}
