<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransicionPedido extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'transiciones_pedido';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'pedido_id' => 'integer',
            'estado_anterior' => OrderStatus::class,
            'estado_nuevo' => OrderStatus::class,
            'usuario_id' => 'integer',
            'clave_idempotencia' => 'string',
            'ocurrida_en' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
