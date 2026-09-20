<?php

namespace Tests\Feature\Database\BlockTwo;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class PaymentMethodConstraintsTest extends TestCase
{
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_payment_method_can_be_created_with_database_defaults(): void
    {
        $paymentMethodId = $this->createPaymentMethod();

        $paymentMethod = DB::table('metodos_pago')->where('id', $paymentMethodId)->first();

        $this->assertNotNull($paymentMethod);
        $this->assertSame('pago_prueba', $paymentMethod->codigo);
        $this->assertSame('Pago de prueba', $paymentMethod->nombre);
        $this->assertFalse((bool) $paymentMethod->afecta_efectivo);
        $this->assertSame(0, (int) $paymentMethod->orden_presentacion);
        $this->assertTrue((bool) $paymentMethod->activo);
        $this->assertNotNull($paymentMethod->created_at);
        $this->assertNotNull($paymentMethod->updated_at);
    }

    public function test_positive_presentation_order_is_accepted(): void
    {
        $paymentMethodId = $this->createPaymentMethod(['orden_presentacion' => 7]);

        $this->assertSame(
            7,
            (int) DB::table('metodos_pago')->where('id', $paymentMethodId)->value('orden_presentacion'),
        );
    }

    public function test_identical_valid_payment_method_code_cannot_be_duplicated(): void
    {
        $this->createPaymentMethod();

        $this->expectException(QueryException::class);

        DB::table('metodos_pago')->insert($this->paymentMethodData([
            'nombre' => 'Pago alternativo',
        ]));
    }

    public function test_payment_method_name_is_unique_case_insensitively(): void
    {
        $this->createPaymentMethod();

        $this->expectException(QueryException::class);

        DB::table('metodos_pago')->insert($this->paymentMethodData([
            'codigo' => 'pago_alterno',
            'nombre' => 'PAGO DE PRUEBA',
        ]));
    }

    #[DataProvider('invalidPaymentMethodCodeValues')]
    public function test_payment_method_code_checks_reject_invalid_values(string $codigo): void
    {
        $this->expectException(QueryException::class);

        DB::table('metodos_pago')->insert($this->paymentMethodData(['codigo' => $codigo]));
    }

    public static function invalidPaymentMethodCodeValues(): array
    {
        return [
            'empty code' => [''],
            'code with surrounding whitespace' => [' pago_prueba'],
            'uppercase code' => ['Pago_prueba'],
            'invalid code format' => ['1pago_prueba'],
        ];
    }

    #[DataProvider('invalidPaymentMethodNameValues')]
    public function test_payment_method_name_checks_reject_invalid_values(string $nombre): void
    {
        $this->expectException(QueryException::class);

        DB::table('metodos_pago')->insert($this->paymentMethodData(['nombre' => $nombre]));
    }

    public static function invalidPaymentMethodNameValues(): array
    {
        return [
            'empty name' => [''],
            'name with surrounding whitespace' => [' Pago de prueba'],
        ];
    }

    public function test_negative_presentation_order_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('metodos_pago')->insert($this->paymentMethodData([
            'orden_presentacion' => -1,
        ]));
    }
}
