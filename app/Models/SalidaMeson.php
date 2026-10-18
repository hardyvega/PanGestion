<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalidaMeson extends Model
{
    protected $table = 'salidas_meson';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'ocurrido_en' => 'immutable_datetime',
            'anulada_en' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function detalleProduccion(): BelongsTo
    {
        return $this->belongsTo(DetalleProduccion::class, 'detalle_produccion_id');
    }
}
