<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class DeliverOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(EffectivePermissionResolver::class)->allows($this->user(), PermissionCode::OrdersDeliver);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'command_uuid' => ['required', 'uuid'],
            'final_payment' => ['nullable', 'array'],
            'final_payment.payment_method_id' => ['required_with:final_payment', 'integer', 'min:1'],
            'final_payment.amount' => ['required_with:final_payment', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'final_payment.payment_uuid' => ['required_with:final_payment', 'uuid'],
            'final_payment.received_amount' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'final_payment.cash_session_id' => ['required_with:final_payment', 'integer', 'min:1'],
        ];
    }
}
