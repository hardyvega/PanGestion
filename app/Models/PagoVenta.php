<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PagoVenta extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pagos_venta';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'venta_id' => 'integer',
            'metodo_pago_id' => 'integer',
            'sesion_caja_id' => 'integer',
            'usuario_id' => 'integer',
            'clave_idempotencia' => 'string',
            'tipo' => PaymentType::class,
            'monto' => 'decimal:2',
            'monto_recibido' => 'decimal:2',
            'vuelto' => 'decimal:2',
            'ocurrido_en' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function metodoPago(): BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id');
    }

    public function sesionCaja(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function movimientoCaja(): HasOne
    {
        return $this->hasOne(MovimientoCaja::class, 'pago_venta_id');
    }
}
