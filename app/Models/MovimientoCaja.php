<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCaja extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'movimientos_caja';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'sesion_caja_id' => 'integer',
            'usuario_id' => 'integer',
            'tipo_movimiento' => 'string',
            'monto' => 'decimal:2',
            'ocurrido_en' => 'immutable_datetime',
            'pago_venta_id' => 'integer',
            'pago_pedido_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function sesionCaja(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function pagoVenta(): BelongsTo
    {
        return $this->belongsTo(PagoVenta::class, 'pago_venta_id');
    }

    public function pagoPedido(): BelongsTo
    {
        return $this->belongsTo(PagoPedido::class, 'pago_pedido_id');
    }
}
