<?php

declare(strict_types=1);

namespace App\Http\Requests\Personnel;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class StoreHourlyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::HourlyRatesManage);
    }

    public function rules(): array
    {
        return [
            'valor_hora' => ['required', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/'],
            'vigente_desde' => ['required', 'date_format:Y-m-d\TH:i'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'valor_hora' => is_string($this->valor_hora) ? trim($this->valor_hora) : $this->valor_hora,
            'observacion' => is_string($this->observacion) ? trim($this->observacion) : $this->observacion,
        ]);
    }
}
