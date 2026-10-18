<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialCostoArticulo extends Model
{
    protected $table = 'historial_costos_articulo';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'articulo_id' => 'integer',
            'proveedor_id' => 'integer',
            'costo_anterior' => 'decimal:2',
            'costo_nuevo' => 'decimal:2',
            'vigente_desde' => 'immutable_datetime',
            'usuario_id' => 'integer',
            'detalle_recepcion_mercaderia_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function detalleRecepcion(): BelongsTo
    {
        return $this->belongsTo(DetalleRecepcionMercaderia::class, 'detalle_recepcion_mercaderia_id');
    }
}
