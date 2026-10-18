<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function articulos(): BelongsToMany
    {
        return $this->belongsToMany(
            Articulo::class,
            'articulo_proveedor',
            'proveedor_id',
            'articulo_id',
            'id',
            'id',
        )->withPivot(['codigo_articulo_proveedor', 'activo'])->withTimestamps();
    }

    public function relacionesArticulos(): HasMany
    {
        return $this->hasMany(ArticuloProveedor::class, 'proveedor_id');
    }

    public function historialCostos(): HasMany
    {
        return $this->hasMany(HistorialCostoArticulo::class, 'proveedor_id');
    }
}
