<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumoProduccion extends Model
{
    protected $table = 'consumos_produccion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad_teorica' => 'decimal:3',
            'cantidad_real' => 'decimal:3',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function produccion(): BelongsTo
    {
        return $this->belongsTo(Produccion::class, 'produccion_id');
    }

    public function detalleReceta(): BelongsTo
    {
        return $this->belongsTo(DetalleReceta::class, 'detalle_receta_id');
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_componente_id');
    }
}
