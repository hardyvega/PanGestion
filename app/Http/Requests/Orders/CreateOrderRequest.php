<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $permissions = app(EffectivePermissionResolver::class);

        return $permissions->allows($user, PermissionCode::OrdersCreate)
            && (empty($this->input('payments'))
                || $permissions->allows($user, PermissionCode::OrdersCollect));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'command_uuid' => ['required', 'uuid'],
            'client_id' => ['required', 'integer', 'min:1'],
            'cash_session_id' => ['nullable', 'integer', 'min:1'],
            'scheduled_delivery_at' => ['nullable', 'date'],
            'observation' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.article_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,11}(?:\.\d{1,3})?$/'],
            'lines.*.observation' => ['nullable', 'string', 'max:1000'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required', 'integer', 'min:1'],
            'payments.*.amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'payments.*.payment_uuid' => ['required', 'uuid'],
            'payments.*.received_amount' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ];
    }
}
