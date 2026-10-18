<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receta extends Model
{
    protected $table = 'recetas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rendimiento_cantidad' => 'decimal:3',
            'activa' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'articulo_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleReceta::class, 'receta_id');
    }

    public function producciones(): HasMany
    {
        return $this->hasMany(Produccion::class, 'receta_id');
    }
}
