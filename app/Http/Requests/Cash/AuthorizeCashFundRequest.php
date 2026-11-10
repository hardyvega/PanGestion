<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class AuthorizeCashFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::CashFundsAuthorize);
    }

    public function rules(): array
    {
        return [
            'caja_id' => ['required', 'integer', 'exists:cajas,id'], 'fecha_operativa' => ['required', 'date_format:Y-m-d'],
            'monto' => ['required', 'regex:/\A0*[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'], 'motivo' => ['required', 'string', 'max:5000'],
            'clave_idempotencia' => ['required', 'uuid'], 'confirmacion' => ['required', 'accepted'],
        ];
    }
}
