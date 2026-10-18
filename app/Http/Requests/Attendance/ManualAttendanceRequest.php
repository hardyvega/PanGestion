<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class ManualAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::AttendanceManage);
    }

    public function rules(): array
    {
        return [
            'trabajador_id' => ['required', 'integer', 'exists:trabajadores,id'],
            'iniciada_en' => ['required', 'date_format:Y-m-d\TH:i'],
            'finalizada_en' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:iniciada_en'],
        ];
    }
}
