<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Foundation\Http\FormRequest;

final class CompleteSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && app(EffectivePermissionResolver::class)->allows($user, PermissionCode::SalesCreate);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'command_uuid' => ['required', 'uuid'],
            'cash_session_id' => ['required', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.article_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/D'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer', 'min:1'],
            'payments.*.amount' => ['required', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
            'payments.*.payment_uuid' => ['required', 'uuid'],
            'payments.*.received_amount' => ['nullable', 'string', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'command_uuid.uuid' => 'La intención de cobro no es válida. Vuelve a intentarlo.',
            'lines.required' => 'Agrega al menos un producto al carrito.',
            'lines.*.quantity.regex' => 'La cantidad debe ser un número válido de hasta tres decimales.',
            'payments.required' => 'Agrega al menos un método de pago.',
            'payments.*.amount.regex' => 'El monto de pago debe tener hasta dos decimales.',
            'payments.*.received_amount.regex' => 'El monto recibido debe tener hasta dos decimales.',
        ];
    }
}
