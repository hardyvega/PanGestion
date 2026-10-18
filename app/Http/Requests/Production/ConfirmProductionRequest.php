<?php

declare(strict_types=1);

namespace App\Http\Requests\Production;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::ProductionConfirm);
    }

    public function rules(): array
    {
        return ['command_uuid' => ['required', 'uuid']];
    }
}
