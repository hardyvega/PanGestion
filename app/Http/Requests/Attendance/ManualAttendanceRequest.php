<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Enums\PermissionCode;
use App\Http\Requests\ValidatesCommercialTime;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ManualAttendanceRequest extends FormRequest
{
    use ValidatesCommercialTime;

    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::AttendanceManage);
    }

    public function rules(): array
    {
        return [
            'trabajador_id' => ['required', 'integer', 'exists:trabajadores,id'],
            'iniciada_en' => ['bail', 'required', 'string', $this->commercialTimeRule()],
            'iniciada_en_occurrence' => ['nullable', 'in:primera,segunda'],
            'finalizada_en' => ['bail', 'required', 'string', $this->commercialTimeRule()],
            'finalizada_en_occurrence' => ['nullable', 'in:primera,segunda'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $start = $this->commercialInstant('iniciada_en');
            $end = $this->commercialInstant('finalizada_en');
            if ($start !== null && $end !== null && $end->lessThan($start)) {
                $validator->errors()->add('finalizada_en', 'El término debe ser posterior o igual al inicio de la jornada.');
            }
        }];
    }
}
