<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReceiptOriginType;
use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecepcionMercaderia extends Model
{
    protected $table = 'recepciones_mercaderia';

    protected $guarded = ['*'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'clave_idempotencia' => 'string',
            'tipo_origen' => ReceiptOriginType::class,
            'estado' => ReceiptStatus::class,
            'proveedor_id' => 'integer',
            'usuario_aportante_id' => 'integer',
            'usuario_creador_id' => 'integer',
            'usuario_confirmador_id' => 'integer',
            'usuario_anulador_id' => 'integer',
            'ocurrido_en' => 'immutable_datetime',
            'confirmada_en' => 'immutable_datetime',
            'anulada_en' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleRecepcionMercaderia::class, 'recepcion_mercaderia_id', 'id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id', 'id');
    }
}
