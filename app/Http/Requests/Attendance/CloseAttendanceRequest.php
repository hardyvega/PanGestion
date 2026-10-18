<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class CloseAttendanceRequest extends FormRequest
{
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
        return ['finalizada_en' => ['nullable', 'date_format:Y-m-d\TH:i']];
    }
}
