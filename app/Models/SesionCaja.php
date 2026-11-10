<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SesionCaja extends Model
{
    protected $table = 'sesiones_caja';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'caja_id' => 'integer',
            'fondo_autorizado_id' => 'integer',
            'fecha_operativa' => 'immutable_date',
            'usuario_apertura_id' => 'integer',
            'usuario_cierre_id' => 'integer',
            'monto_inicial' => 'decimal:2',
            'abierta_en' => 'immutable_datetime',
            'cerrada_en' => 'immutable_datetime',
            'monto_cierre_declarado' => 'decimal:2',
            'monto_teorico_cierre' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    public function fondoAutorizado(): BelongsTo
    {
        return $this->belongsTo(FondoCajaAutorizado::class, 'fondo_autorizado_id');
    }

    public function cierre(): HasOne
    {
        return $this->hasOne(CierreCaja::class, 'sesion_caja_id');
    }

    public function usuarioApertura(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_apertura_id');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_cierre_id');
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'sesion_caja_id');
    }

    public function pagosVenta(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'sesion_caja_id');
    }

    public function pagosPedido(): HasMany
    {
        return $this->hasMany(PagoPedido::class, 'sesion_caja_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoCaja::class, 'sesion_caja_id');
    }
}
