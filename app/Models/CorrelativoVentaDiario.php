<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorrelativoVentaDiario extends Model
{
    protected $table = 'correlativos_venta_diarios';

    protected $primaryKey = 'fecha_comercial';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'fecha_comercial' => 'immutable_date',
            'ultimo_numero' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
