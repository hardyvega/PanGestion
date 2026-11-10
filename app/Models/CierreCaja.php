<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CierreCaja extends Model
{
    protected $table = 'cierres_caja';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'fecha_operativa' => 'immutable_date', 'fecha_siguiente' => 'immutable_date',
            'esperado_antes_retiro' => 'decimal:2', 'contado_antes_retiro' => 'decimal:2',
            'diferencia' => 'decimal:2', 'retiro_final' => 'decimal:2', 'fondo_remanente' => 'decimal:2',
            'resumen' => 'array', 'confirmado_en' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
        ];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function confirmador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_confirmador_id');
    }

    public function fondoSiguiente(): HasOne
    {
        return $this->hasOne(FondoCajaAutorizado::class, 'cierre_origen_id');
    }

    public function movimientoRetiro(): HasOne
    {
        return $this->hasOne(MovimientoCaja::class, 'cierre_caja_id');
    }
}
