<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class HourlyPayReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::HourlyPayView);
    }

    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }
}
