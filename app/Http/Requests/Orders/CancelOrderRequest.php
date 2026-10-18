<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::OrdersCancel);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'command_uuid' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
