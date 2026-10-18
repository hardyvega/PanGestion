<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produccion extends Model
{
    protected $table = 'producciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'receta_id' => 'integer',
            'usuario_responsable_id' => 'integer',
            'usuario_creador_id' => 'integer',
            'usuario_confirmador_id' => 'integer',
            'usuario_anulador_id' => 'integer',
            'confirmada_en' => 'immutable_datetime',
            'anulada_en' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function receta(): BelongsTo
    {
        return $this->belongsTo(Receta::class, 'receta_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_responsable_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(DetalleProduccion::class, 'produccion_id');
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(ConsumoProduccion::class, 'produccion_id');
    }
}
