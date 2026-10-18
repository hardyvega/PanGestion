<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleReceta extends Model
{
    protected $table = 'detalle_recetas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:3',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class, 'receta_id');
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_componente_id');
    }
}
