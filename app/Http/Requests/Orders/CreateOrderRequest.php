<?php

declare(strict_types=1);

namespace App\Http\Requests\Orders;

use App\Data\Orders\FreeOrderData;
use App\Data\Orders\OrderContact;
use App\Enums\PermissionCode;
use App\Http\Requests\ValidatesCommercialTime;
use App\Services\Authorization\EffectivePermissionResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class CreateOrderRequest extends FormRequest
{
    use ValidatesCommercialTime;

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
            'contact_name' => ['bail', 'required', 'string', 'max:150'],
            'contact_phone' => ['bail', 'required', 'string', 'max:50', static function (string $attribute, string $value, Closure $fail): void {
                if (OrderContact::normalizePhone($value) === null) {
                    $fail('Ingresa un teléfono chileno válido o un número internacional con código de país.');
                }
            }],
            'cash_session_id' => ['nullable', 'integer', 'min:1'],
            'scheduled_delivery_at' => ['bail', 'required', 'string', $this->commercialTimeRule()],
            'scheduled_delivery_at_occurrence' => ['nullable', 'in:primera,segunda'],
            'description' => ['bail', 'required', 'string', 'max:'.FreeOrderData::TEXT_LIMIT, $this->validOrderText(...)],
            'specifications' => ['bail', 'nullable', 'string', 'max:'.FreeOrderData::TEXT_LIMIT, $this->validOrderText(...)],
            'agreed_total' => ['bail', 'required', static function (string $attribute, mixed $value, Closure $fail): void {
                if (FreeOrderData::normalizePrice($value) === null) {
                    $fail('Ingresa un precio mayor que cero, en pesos enteros y con hasta 12 dígitos. Puedes escribir 25000 o 25.000.');
                }
            }],
            'lines' => ['prohibited'],
            'payments' => ['nullable', 'array'],
            'payments.*.payment_method_id' => ['required', 'integer', 'min:1'],
            'payments.*.amount' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            'payments.*.payment_uuid' => ['required', 'uuid'],
            'payments.*.received_amount' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'contact_name' => 'nombre del cliente',
            'contact_phone' => 'teléfono de contacto',
            'scheduled_delivery_at' => 'fecha y hora de entrega',
            'description' => 'detalle del pedido',
            'specifications' => 'especificaciones del pedido',
            'agreed_total' => 'precio total acordado',
            'lines' => 'lista de artículos',
        ];
    }

    private function validOrderText(string $attribute, string $value, Closure $fail): void
    {
        if (FreeOrderData::normalizeText($value) === null) {
            $fail('Revisa el texto y elimina los caracteres no válidos.');
        }
    }

    public function scheduledDeliveryAt(): CarbonImmutable
    {
        return $this->commercialInstant('scheduled_delivery_at');
    }
}
