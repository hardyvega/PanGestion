<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allowsAll($this->user(), [PermissionCode::CashSessionsClose, PermissionCode::CashFundsAuthorize]);
    }

    public function rules(): array
    {
        return [
            'monto_declarado' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/'],
            'observacion' => ['nullable', 'string', 'max:5000'],
            'fondo_siguiente' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/'],
            'fecha_siguiente' => ['required', 'date_format:Y-m-d'],
            'clave_idempotencia' => ['required', 'uuid'],
            'confirmacion' => ['required', 'accepted'],
            'esperado_revisado' => ['required', 'regex:/^-?[0-9]+(?:\.[0-9]{1,2})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->observacion)) {
            $this->merge(['observacion' => trim($this->observacion)]);
        }
    }
}
