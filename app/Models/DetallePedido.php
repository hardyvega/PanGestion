<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetallePedido extends Model
{
    protected $table = 'detalle_pedidos';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'pedido_id' => 'integer',
            'articulo_id' => 'integer',
            'cantidad' => 'decimal:3',
            'precio_unitario' => 'decimal:2',
            'articulo_sku_snapshot' => 'string',
            'articulo_nombre_snapshot' => 'string',
            'unidad_codigo_snapshot' => 'string',
            'unidad_simbolo_snapshot' => 'string',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }
}
