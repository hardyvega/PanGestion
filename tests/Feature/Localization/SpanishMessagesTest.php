<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Enums\PermissionCode as P;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Support\CommercialDate;
use App\Support\UserFacingError;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\TestCase;

final class SpanishMessagesTest extends TestCase
{
    use DailyCashFixtures;
    use RefreshDatabase;

    public function test_spanish_is_effective_without_changing_private_environment_files_and_covers_framework_rules(): void
    {
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('es', config('app.fallback_locale'));
        $english = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $spanish = trans('validation');
        foreach (Arr::dot(Arr::except($english, ['custom', 'attributes'])) as $key => $message) {
            $this->assertTrue(Arr::has($spanish, $key), $key);
            $this->assertNotSame($message, Arr::get($spanish, $key), $key);
        }
        $this->assertSame('(y 2 errores más)', trans_choice('(and :count more errors)', 2, ['count' => 2]));
    }

    #[DataProvider('validationCases')]
    public function test_shared_rules_keep_the_same_failure_and_use_friendly_field_names(array $data, array $rules, string $field, string $expected): void
    {
        $validator = Validator::make($data, $rules);
        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($field));
        $message = $validator->errors()->first($field);
        $this->assertStringContainsString($expected, $message);
        $this->assertDoesNotMatchRegularExpression('/The | field |selected .* invalid|must be|validation\./', $message);
    }

    public static function validationCases(): array
    {
        return [
            'required' => [[], ['nombre' => 'required'], 'nombre', 'Completa el campo nombre'],
            'integer' => [['client_id' => 'abc'], ['client_id' => 'integer'], 'client_id', 'número entero'],
            'numeric' => [['precio_venta' => 'abc'], ['precio_venta' => 'numeric'], 'precio_venta', 'precio de venta'],
            'min' => [['client_id' => 0], ['client_id' => 'integer|min:1'], 'client_id', 'cliente'],
            'max' => [['nombre' => 'abc'], ['nombre' => 'string|max:2'], 'nombre', 'como máximo 2 caracteres'],
            'between' => [['monto' => 1], ['monto' => 'numeric|between:2,5'], 'monto', 'entre 2 y 5'],
            'email' => [['correo' => 'invalido'], ['correo' => 'email'], 'correo', 'correo electrónico'],
            'confirmed' => [['password' => 'Prueba123', 'password_confirmation' => 'Otra123'], ['password' => 'confirmed'], 'password', 'confirmación de contraseña no coincide'],
            'date' => [['fecha_operativa' => 'ayer-no'], ['fecha_operativa' => 'date'], 'fecha_operativa', 'fecha de la jornada'],
            'date-format' => [['fecha_siguiente' => '23/10/2026'], ['fecha_siguiente' => 'date_format:Y-m-d'], 'fecha_siguiente', 'próxima jornada'],
            'boolean' => [['activo' => 'quizas'], ['activo' => 'boolean'], 'activo', 'estado'],
            'array' => [['lines' => 'texto'], ['lines' => 'array'], 'lines', 'productos'],
            'enum' => [['tipo_movimiento' => 'otro'], ['tipo_movimiento' => 'in:ingreso,egreso,retiro'], 'tipo_movimiento', 'tipo de movimiento'],
            'accepted' => [['confirmacion' => false], ['confirmacion' => 'accepted'], 'confirmacion', 'aceptar confirmación'],
            'prohibited' => [['monto_inicial' => '1000'], ['monto_inicial' => 'prohibited'], 'monto_inicial', 'fondo inicial'],
            'uuid' => [['command_uuid' => 'abc'], ['command_uuid' => 'uuid'], 'command_uuid', 'referencia de operación'],
            'quantity' => [['cantidad' => '1,5'], ['cantidad' => 'regex:/^[0-9]+(?:\.[0-9]{1,3})?$/'], 'cantidad', 'hasta tres decimales'],
            'nested-quantity' => [['lines' => [['quantity' => 'abc']]], ['lines.*.quantity' => 'regex:/^\d+(?:\.\d{1,3})?$/'], 'lines.0.quantity', 'línea 1'],
            'nested-money' => [['payments' => [['amount' => 'abc']]], ['payments.*.amount' => 'regex:/^\d+(?:\.\d{1,2})?$/'], 'payments.0.amount', 'pago 1'],
            'required-with' => [['final_payment' => ['amount' => '100']], ['final_payment.payment_method_id' => 'required_with:final_payment'], 'final_payment.payment_method_id', 'método del pago final'],
        ];
    }

    #[DataProvider('articleCases')]
    public function test_article_errors_are_in_spanish_preserve_input_and_never_mutate_records(string $field, mixed $value, string $message): void
    {
        $this->actingAs($this->dailyActor([P::ArticlesManage, P::ArticlesView]));
        $payload = $this->articlePayload();
        $payload[$field] = $value;
        $this->from('/articulos/crear')->post('/articulos', $payload)->assertRedirect('/articulos/crear')
            ->assertSessionHasErrors($field)->assertSessionHasInput('codigo_barras', $payload['codigo_barras']);
        $errors = session('errors')->getBag('default');
        $this->assertStringContainsString($message, $errors->first($field));
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $page = $this->get('/articulos/crear')->assertOk();
        $this->assertTrue(app('view')->shared('errors')->any(), 'The response must receive the flashed errors.');
        $page->assertSee($message)->assertSee('field-error', false)
            ->assertDontSee('The sku field')->assertSee('Bebida de prueba');
        $this->assertDatabaseCount('articulos', 1);
        $this->assertDatabaseHas('articulos', ['sku' => 'existente', 'codigo_barras' => '000001', 'stock_actual' => '0.000']);
        $this->assertDatabaseCount('historial_precios_venta', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public static function articleCases(): array
    {
        return [
            ['sku', 'prueba Bebida a 001', 'Usa letras minúsculas, números, guiones o guiones bajos, sin espacios.'],
            ['sku', 'existente', 'Este código interno (SKU) ya está en uso.'],
            ['codigo_barras', '000001', 'Este código de barras ya pertenece a otro artículo.'],
            ['sku', '', 'Ingresa el código interno (SKU) del artículo.'],
            ['categoria_articulo_id', 0, 'La categoría seleccionada no existe o no está disponible.'],
            ['unidad_id', 0, 'La unidad de medida seleccionada no existe o no está disponible.'],
            ['precio_venta', '1,100', 'Ingresa un precio de venta válido'],
        ];
    }

    public function test_valid_sku_normalization_and_leading_zero_barcodes_still_work(): void
    {
        $this->actingAs($this->dailyActor([P::ArticlesManage]));
        $payload = $this->articlePayload();
        $payload['sku'] = ' PRUEBA-BEBIDA-A-001 ';
        $this->post('/articulos', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('articulos', ['sku' => 'prueba-bebida-a-001', 'codigo_barras' => '000999', 'stock_actual' => '0.000']);
    }

    #[DataProvider('moduleCases')]
    public function test_each_module_keeps_field_errors_and_json_validation_contract(string $endpoint, string $field, string $label): void
    {
        $this->actingAs($this->dailyActor(P::cases()));
        $response = $this->postJson($endpoint, [])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
        $this->assertStringContainsString($label, $response->json('errors.'.$field.'.0'));
        $this->assertStringNotContainsString('(and ', $response->json('message'));
    }

    public static function moduleCases(): array
    {
        return [
            ['/catalogo/categorias', 'codigo', 'código'], ['/catalogo/unidades', 'simbolo', 'símbolo'],
            ['/inventario/ajustes', 'articulo_id', 'artículo'], ['/inventario/conteos', 'articulos', 'artículos'],
            ['/inventario/mermas', 'cantidad', 'cantidad'], ['/recepciones', 'tipo_origen', 'origen de la recepción'],
            ['/ventas', 'command_uuid', 'operación'], ['/pedidos', 'contact_name', 'nombre del cliente'],
            ['/caja/sesiones', 'fondo_autorizado_id', 'fondo autorizado'], ['/proveedores', 'nombre', 'nombre'],
            ['/recetas', 'componentes', 'ingredientes'], ['/producciones', 'consumos', 'consumos'],
            ['/personal', 'nombres', 'nombres'], ['/asistencia/gestionar/manual', 'trabajador_id', 'trabajador'],
            ['/administracion/usuarios', 'rol_id', 'rol'], ['/administracion/roles', 'codigo', 'código'],
        ];
    }

    public function test_stock_errors_are_readable_and_leave_stock_unchanged(): void
    {
        $this->actingAs($this->dailyActor([P::InventoryAdjustmentsOutput]));
        $this->articlePayload();
        $article = DB::table('articulos')->value('id');
        $this->from('/inventario/ajustes/crear')->post('/inventario/ajustes', [
            'tipo' => 'salida', 'articulo_id' => $article, 'cantidad' => '5.000', 'motivo' => 'Prueba de mensaje',
        ])->assertRedirect()->assertSessionHasErrors();
        $this->assertStringContainsString('No hay stock suficiente', session('errors')->first());
        $this->post('/inventario/ajustes', [
            'tipo' => 'salida', 'articulo_id' => $article, 'cantidad' => '9999999999999999.000', 'motivo' => 'Prueba de rango',
        ])->assertRedirect()->assertSessionHasErrors();
        $this->assertStringContainsString('supera el máximo permitido', session('errors')->first());
        $this->assertDatabaseHas('articulos', ['id' => $article, 'stock_actual' => '0.000']);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_cash_opening_and_closing_errors_keep_their_rules_and_history(): void
    {
        $cashier = $this->dailyActor([P::CashSessionsOpen]);
        $owner = $this->dailyActor([P::CashSessionsClose, P::CashFundsAuthorize]);
        $register = $this->dailyRegister();
        $other = $this->dailyRegister();
        $fund = $this->dailyFund($register, '1000');
        $this->actingAs($cashier)->post('/caja/sesiones', [
            'caja_id' => $other, 'fondo_autorizado_id' => $fund->id,
            'fecha_operativa' => CommercialDate::today(), 'clave_idempotencia' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHasErrors('cash');
        $this->assertStringContainsString('Falta un fondo autorizado', session('errors')->first('cash'));
        $session = app(\App\Services\Cash\CashService::class)->open($cashier, $register, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
        $this->actingAs($owner)->post('/caja/sesiones/'.$session->id.'/cerrar', [
            'monto_declarado' => '1000', 'fondo_siguiente' => '1001', 'fecha_siguiente' => \Carbon\CarbonImmutable::parse(CommercialDate::today())->addDay()->format('Y-m-d'),
            'confirmacion' => '1', 'clave_idempotencia' => (string) Str::uuid(), 'esperado_revisado' => '1000',
        ])->assertRedirect()->assertSessionHasErrors('cash');
        $this->assertStringContainsString('No puede superar el efectivo contado', session('errors')->first('cash'));
        $this->assertNull($session->fresh()->cerrada_en);
        $this->assertDatabaseCount('cierres_caja', 0);
        $this->assertDatabaseCount('movimientos_caja', 0);
    }

    #[DataProvider('httpCases')]
    public function test_http_errors_are_safe_and_spanish_even_in_debug_mode(int $status, bool $json): void
    {
        config(['app.debug' => true]);
        Route::get('/_localization/error', static function () use ($status): never {
            abort($status, 'PRIVATE SQLSTATE SELECT private_table /private/path');
        });
        $response = $json ? $this->getJson('/_localization/error') : $this->get('/_localization/error');
        $response->assertStatus($status)->assertDontSee('PRIVATE')->assertDontSee('SQLSTATE')->assertDontSee('/private/path');
        if ($json) {
            $this->assertSame(['message'], array_keys($response->json()));
            $response->assertJsonPath('message', trans('http.'.$status.'.message'));
        } else {
            $response->assertSee(trans('http.'.$status.'.title'))->assertSee('Almapan');
        }
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function httpCases(): array
    {
        $cases = [];
        foreach ([401, 403, 404, 405, 419, 429, 500, 503] as $code) {
            foreach ([false, true] as $json) {
                $cases[$code.($json ? '-json' : '-html')] = [$code, $json];
            }
        }
        return $cases;
    }

    public function test_real_access_and_session_errors_keep_authentication_contracts(): void
    {
        $this->get('/articulos')->assertRedirect('/login');
        $this->getJson('/articulos')->assertUnauthorized()->assertJsonPath('message', trans('http.401.message'));
        $this->actingAs($this->dailyActor([]))->get('/articulos')->assertForbidden()->assertSee('No tienes permiso para esta acción');
        $this->app->bind(PreventRequestForgery::class, static fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool { return false; }
        });
        $this->post('/logout')->assertStatus(419)->assertSee('Tu sesión ha expirado')
            ->assertSee('no se enviarán automáticamente');
        $this->assertAuthenticated();
    }

    public function test_login_errors_are_spanish_without_saving_passwords_and_throttle_remains_429(): void
    {
        $this->from('/login')->post('/login', [])->assertSessionHasErrors(['nombre_usuario' => 'Completa el campo nombre de usuario.']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['nombre_usuario' => 'locale_limit', 'password' => 'SyntheticOnly123'])->assertRedirect('/login')
                ->assertSessionHasErrors(['nombre_usuario' => 'Nombre de usuario o contraseña incorrectos.']);
        }
        $this->post('/login', ['nombre_usuario' => 'locale_limit', 'password' => 'SyntheticOnly123'])->assertStatus(429)
            ->assertSee('Demasiados intentos. Espera un momento y vuelve a intentarlo.')->assertDontSee('Redirecting to');
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_unexpected_receipt_failure_is_reported_as_500_not_a_form_error(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $this->actingAs($this->dailyActor([P::InventoryReceiptsCreate]));
        $supplier = DB::table('proveedores')->insertGetId(['codigo' => 'locale_supplier', 'nombre' => 'Proveedor de prueba', 'activo' => true]);
        DB::connection()->beforeExecuting(static function (string $sql): void {
            if (str_contains($sql, 'insert into "recepciones_mercaderia"')) {
                throw new RuntimeException('PRIVATE_QUERY_FAILURE');
            }
        });
        $this->post('/recepciones', ['tipo_origen' => 'proveedor', 'proveedor_id' => $supplier])
            ->assertStatus(500)->assertSee('Ocurrió un problema inesperado')->assertDontSee('PRIVATE_QUERY_FAILURE');
        Log::shouldHaveReceived('error')->withArgs(static fn ($message, $context): bool => str_contains($message, 'PRIVATE_QUERY_FAILURE') && isset($context['exception']));
        $this->assertDatabaseCount('recepciones_mercaderia', 0);
    }

    public function test_operation_messages_hide_technical_terms_without_changing_exception_contracts(): void
    {
        $this->assertStringContainsString('stock suficiente', UserFacingError::message(new InsufficientStockException('technical')));
        $this->assertStringContainsString('Revisa su estado', UserFacingError::message(new IdempotencyConflictException('UUID payload')));
        $this->assertStringNotContainsString('ledger', UserFacingError::message(new InventoryDomainException('El ledger deriva un stock diario negativo.')));
        $this->assertStringContainsString('supera el máximo permitido', UserFacingError::message(new \OutOfRangeException('El valor decimal excede el rango permitido.')));
        $this->expectException(InvalidArgumentException::class);
        UserFacingError::message(new InvalidArgumentException('Unexpected internal argument'));
    }

    private function articlePayload(): array
    {
        $category = DB::table('categorias_articulo')->insertGetId(['codigo' => 'locale_category', 'nombre' => 'Bebidas', 'activo' => true]);
        $unit = DB::table('unidades_medida')->insertGetId(['codigo' => 'locale_unit', 'nombre' => 'Unidad', 'simbolo' => 'un', 'magnitud' => 'unidad', 'activo' => true]);
        DB::table('articulos')->insert(['sku' => 'existente', 'nombre' => 'Artículo existente', 'codigo_barras' => '000001',
            'categoria_articulo_id' => $category, 'unidad_id' => $unit, 'tipo_articulo' => 'reventa', 'stock_actual' => '0.000', 'stock_minimo' => '0.000', 'activo' => true]);

        return ['sku' => 'prueba-bebida-a-001', 'nombre' => 'Bebida de prueba', 'codigo_barras' => '000999',
            'categoria_articulo_id' => $category, 'unidad_id' => $unit, 'tipo_articulo' => 'reventa', 'stock_minimo' => '0.000', 'precio_venta' => '1100'];
    }
}
