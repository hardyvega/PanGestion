<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::OrdersManage);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'command_uuid' => ['required', 'uuid'],
        ];
    }
}
