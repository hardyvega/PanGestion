<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';

    protected $guarded = ['id'];

    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class, 'unidad_id');
    }

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
