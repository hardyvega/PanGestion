<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::CashSessionsOpen);
    }

    public function rules(): array
    {
        return [
            'caja_id' => ['required', 'integer', 'exists:cajas,id'],
            'monto_inicial' => ['prohibited'],
            'fondo_autorizado_id' => ['required', 'integer', 'exists:fondos_caja_autorizados,id'],
            'fecha_operativa' => ['required', 'date_format:Y-m-d'],
            'clave_idempotencia' => ['required', 'uuid'],
        ];
    }
}
