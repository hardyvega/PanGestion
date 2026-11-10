<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionCode;
use App\Http\Requests\ValidatesCommercialTime;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class CloseAttendanceRequest extends FormRequest
{
    use ValidatesCommercialTime;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            app(EffectivePermissionResolver::class)->allows($user, PermissionCode::AttendanceMark)
            || app(EffectivePermissionResolver::class)->allows($user, PermissionCode::AttendanceManage)
        );
    }

    public function rules(): array
    {
        return [
            'finalizada_en' => ['bail', 'nullable', 'string', $this->commercialTimeRule()],
            'finalizada_en_occurrence' => ['nullable', 'in:primera,segunda'],
        ];
    }
}
