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
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::CashSessionsClose);
    }

    public function rules(): array
    {
        return [
            'monto_declarado' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->observacion)) {
            $this->merge(['observacion' => trim($this->observacion)]);
        }
    }
}
