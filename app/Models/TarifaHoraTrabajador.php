<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TarifaHoraTrabajador extends Model
{
    protected $table = 'tarifas_hora_trabajador';

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'trabajador_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_usuario_id');
    }

    protected function casts(): array
    {
        return [
            'valor_hora' => 'decimal:2',
            'vigente_desde' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
