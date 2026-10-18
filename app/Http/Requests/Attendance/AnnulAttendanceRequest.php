<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class AnnulAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::AttendanceManage);
    }

    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:1000']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['motivo' => is_string($this->motivo) ? trim($this->motivo) : $this->motivo]);
    }
}
