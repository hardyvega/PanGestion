<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Trabajador extends Model
{
    protected $table = 'trabajadores';

    protected $guarded = ['*'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function tarifasHora(): HasMany
    {
        return $this->hasMany(TarifaHoraTrabajador::class, 'trabajador_id');
    }

    public function jornadas(): HasMany
    {
        return $this->hasMany(JornadaTrabajo::class, 'trabajador_id');
    }

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'fecha_ingreso' => 'immutable_date',
            'fecha_termino' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
