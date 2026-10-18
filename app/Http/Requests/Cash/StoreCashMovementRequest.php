<?php

declare(strict_types=1);

namespace App\Http\Requests\Cash;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::CashManualMovementsManage);
    }

    public function rules(): array
    {
        return [
            'tipo_movimiento' => ['required', 'in:ingreso,egreso,retiro'],
            'monto' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/', 'not_regex:/^0+(?:\.0{1,2})?$/'],
            'concepto' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->concepto)) {
            $this->merge(['concepto' => trim($this->concepto)]);
        }
    }
}
