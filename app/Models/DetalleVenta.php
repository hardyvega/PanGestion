<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DetalleVenta extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'detalle_ventas';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'venta_id' => 'integer',
            'articulo_id' => 'integer',
            'articulo_sku_snapshot' => 'string',
            'articulo_nombre_snapshot' => 'string',
            'unidad_codigo_snapshot' => 'string',
            'unidad_simbolo_snapshot' => 'string',
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'detalle_venta_id');
    }
}
