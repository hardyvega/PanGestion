<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class AnnulProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::ProductionAnnul);
    }

    public function rules(): array
    {
        return [
            'command_uuid' => ['required', 'uuid'],
            'motivo_anulacion' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'motivo_anulacion' => is_string($this->motivo_anulacion) ? trim($this->motivo_anulacion) : $this->motivo_anulacion,
        ]);
    }
}
