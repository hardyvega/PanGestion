<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class JornadaTrabajo extends Model
{
    protected $table = 'jornadas_trabajo';

    protected $guarded = ['*'];

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class, 'trabajador_id');
    }

    public function tarifaHora(): BelongsTo
    {
        return $this->belongsTo(TarifaHoraTrabajador::class, 'tarifa_hora_trabajador_id');
    }

    public function usuarioInicio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_inicio_id');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_cierre_id');
    }

    public function usuarioAnulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_anulador_id');
    }

    protected function casts(): array
    {
        return [
            'estado' => AttendanceStatus::class,
            'iniciada_en' => 'immutable_datetime',
            'finalizada_en' => 'immutable_datetime',
            'valor_hora_snapshot' => 'decimal:2',
            'monto_calculado' => 'decimal:2',
            'anulada_en' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
