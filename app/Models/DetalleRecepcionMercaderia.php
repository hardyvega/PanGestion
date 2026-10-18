<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleRecepcionMercaderia extends Model
{
    protected $table = 'detalle_recepciones_mercaderia';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'recepcion_mercaderia_id' => 'integer',
            'articulo_id' => 'integer',
            'cantidad' => 'decimal:3',
            'costo_unitario' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function recepcion(): BelongsTo
    {
        return $this->belongsTo(RecepcionMercaderia::class, 'recepcion_mercaderia_id', 'id');
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id', 'id');
    }
}
