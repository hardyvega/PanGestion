<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DetalleProduccion extends Model
{
    protected $table = 'detalle_producciones';

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

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    public function salidasMeson(): HasMany
    {
        return $this->hasMany(SalidaMeson::class, 'detalle_produccion_id');
    }
}
