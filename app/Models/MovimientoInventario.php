<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;

class MovimientoInventario extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'movimientos_inventario';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'articulo_id' => 'integer',
            'usuario_id' => 'integer',
            'usuario_autorizador_id' => 'integer',
            'tipo_movimiento' => InventoryMovementType::class,
            'cantidad_delta' => 'decimal:3',
            'stock_anterior' => 'decimal:3',
            'stock_posterior' => 'decimal:3',
            'clave_idempotencia' => 'string',
            'secuencia_idempotencia' => 'integer',
            'ocurrido_en' => 'immutable_datetime',
            'detalle_venta_id' => 'integer',
            'detalle_recepcion_mercaderia_id' => 'integer',
            'detalle_produccion_id' => 'integer',
            'consumo_produccion_id' => 'integer',
            'merma_id' => 'integer',
            'detalle_conteo_inventario_id' => 'integer',
            'movimiento_revertido_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
