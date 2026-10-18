<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDiario extends Model
{
    protected $table = 'stock_diario';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'articulo_id' => 'integer',
            'fecha_comercial' => 'immutable_date',
            'stock_apertura' => 'decimal:3',
            'total_entradas' => 'decimal:3',
            'total_salidas' => 'decimal:3',
            'stock_cierre' => 'decimal:3',
            'calculado_en' => 'immutable_datetime',
            'ultimo_movimiento_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
