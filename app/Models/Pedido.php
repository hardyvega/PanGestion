<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pedido extends Model
{
    public const MODE_CATALOG = 'catalogo';

    public const MODE_FREE = 'libre';

    protected $table = 'pedidos';

    protected $guarded = ['*'];

    protected $hidden = ['cliente_telefono_cifrado'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'cliente_id' => 'integer',
            'cliente_telefono_cifrado' => 'encrypted',
            'precio_total_acordado' => 'decimal:2',
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

    public function isFree(): bool
    {
        return $this->modalidad === self::MODE_FREE;
    }

    public function totalFrom(iterable $details): string
    {
        if ($this->isFree()) {
            return $this->precio_total_acordado;
        }

        $total = '0.00';
        foreach ($details as $detail) {
            $total = Decimal::add($total, Decimal::multiply((string) $detail->cantidad, (string) $detail->precio_unitario, 2), 2);
        }

        return $total;
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
