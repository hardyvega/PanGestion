<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialPrecioVenta extends Model
{
    protected $table = 'historial_precios_venta';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'precio_anterior' => 'decimal:2',
            'precio_nuevo' => 'decimal:2',
            'vigente_desde' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
