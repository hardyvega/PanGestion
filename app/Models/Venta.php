<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venta extends Model
{
    protected $table = 'ventas';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'fecha_comercial' => 'immutable_date',
            'numero_diario' => 'integer',
            'clave_idempotencia' => 'string',
            'clave_idempotencia_anulacion' => 'string',
            'usuario_id' => 'integer',
            'sesion_caja_id' => 'integer',
            'cliente_id' => 'integer',
            'pedido_id' => 'integer',
            'estado' => SaleStatus::class,
            'total' => 'decimal:2',
            'monto_anticipo_aplicado' => 'decimal:2',
            'completada_en' => 'immutable_datetime',
            'anulada_en' => 'immutable_datetime',
            'usuario_anulador_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function sesionCaja(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function usuarioAnulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_anulador_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleVenta::class, 'venta_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'venta_id');
    }
}
