<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Articulo extends Model
{
    protected $table = 'articulos';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'descripcion',
        'codigo_barras',
        'stock_minimo',
        'imagen_ruta',
    ];

    protected function casts(): array
    {
        return [
            'precio_venta_actual' => 'decimal:2',
            'stock_actual' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'activo' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaArticulo::class, 'categoria_articulo_id');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_id');
    }

    public function historialPrecios(): HasMany
    {
        return $this->hasMany(HistorialPrecioVenta::class, 'articulo_id');
    }

    public function proveedores(): BelongsToMany
    {
        return $this->belongsToMany(
            Proveedor::class,
            'articulo_proveedor',
            'articulo_id',
            'proveedor_id',
            'id',
            'id',
        )->withPivot(['codigo_articulo_proveedor', 'activo'])->withTimestamps();
    }

    public function relacionesProveedores(): HasMany
    {
        return $this->hasMany(ArticuloProveedor::class, 'articulo_id');
    }

    public function historialCostos(): HasMany
    {
        return $this->hasMany(HistorialCostoArticulo::class, 'articulo_id');
    }

    public function recetas(): HasMany
    {
        return $this->hasMany(Receta::class, 'articulo_id');
    }
}
