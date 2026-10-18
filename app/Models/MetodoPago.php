<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetodoPago extends Model
{
    protected $table = 'metodos_pago';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'afecta_efectivo' => 'boolean',
            'orden_presentacion' => 'integer',
            'activo' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function pagosVenta(): HasMany
    {
        return $this->hasMany(PagoVenta::class, 'metodo_pago_id');
    }

    public function pagosPedido(): HasMany
    {
        return $this->hasMany(PagoPedido::class, 'metodo_pago_id');
    }
}
