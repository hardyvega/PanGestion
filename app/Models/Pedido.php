<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pedido extends Model
{
    protected $table = 'pedidos';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'cliente_id' => 'integer',
            'usuario_creador_id' => 'integer',
            'estado' => OrderStatus::class,
            'entrega_programada_en' => 'immutable_datetime',
            'entregado_en' => 'immutable_datetime',
            'cancelado_en' => 'immutable_datetime',
            'clave_idempotencia' => 'string',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function usuarioCreador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_creador_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetallePedido::class, 'pedido_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoPedido::class, 'pedido_id');
    }

    public function venta(): HasOne
    {
        return $this->hasOne(Venta::class, 'pedido_id');
    }

    public function transiciones(): HasMany
    {
        return $this->hasMany(TransicionPedido::class, 'pedido_id');
    }
}
