<?php

declare(strict_types=1);

use App\Data\Orders\DeliverOrderCommand;
use App\Data\Orders\OrderPaymentData;
use App\Data\Production\AnnulProductionCommand;
use App\Data\Production\ConfirmProductionCommand;
use App\Data\Sales\AnnulSaleCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Enums\PaymentType;
use App\Models\MovimientoCaja;
use App\Models\User;
use App\Services\Cash\CashFundService;
use App\Services\Cash\CashService;
use App\Services\Orders\OrderService;
use App\Services\Production\ProductionService;
use App\Services\Sales\SaleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 3);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$database = DB::connection();
if ($database->scalar('SELECT current_database()') !== 'pangestion_test'
    || $database->scalar('SELECT current_user') !== 'pangestion_test_user') {
    throw new RuntimeException('El worker no esta conectado a pangestion_test.');
}

file_put_contents($input['ready_file'], 'ready');
$database->select('SELECT pg_advisory_lock(?, ?)', [
    $input['ready_class_id'],
    $input['ready_object_id'],
]);
while (! is_file($input['go_file'])) {
    usleep(10_000);
}

try {
    $payload = $input['payload'];
    if ($input['scenario'] === 'cash_operate') {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($payload['clock']));
        Carbon::setTestNow(CarbonImmutable::parse($payload['clock']));
    }
    $actor = User::query()->findOrFail((int) $payload['actor_id']);

    $result = match ($input['scenario']) {
        'cash_operate' => match ($payload['action']) {
            'authorize_opening' => app(CashFundService::class)->authorizeOpening($actor, (int) $payload['register_id'], $payload['date'], $payload['amount'], $payload['reason'], $payload['uuid']),
            'raw_movement' => tap((new MovimientoCaja)->forceFill([
                'sesion_caja_id' => (int) $payload['session_id'], 'usuario_id' => $actor->id,
                'tipo_movimiento' => 'ingreso', 'monto' => '1', 'concepto' => 'Ingreso concurrente directo', 'ocurrido_en' => now('UTC'),
            ]), static fn ($movement) => $movement->save()),
            'open' => app(CashService::class)->open($actor, (int) $payload['register_id'], (int) $payload['fund_id'], $payload['date'], $payload['uuid']),
            'close' => app(CashService::class)->close($actor, (int) $payload['session_id'], $payload['counted'], $payload['next_fund'], $payload['next_date'], $payload['uuid'], true, null, $payload['expected']),
            'movement' => app(CashService::class)->registerManualMovement($actor, (int) $payload['session_id'], 'ingreso', '1', 'Ingreso concurrente'),
            default => throw new InvalidArgumentException('Accion de caja desconocida.'),
        },
        'sale' => app(SaleService::class)->complete(
            $actor,
            new CompleteSaleCommand(
                $payload['command_uuid'],
                (int) $payload['cash_session_id'],
                array_map(
                    static fn (array $line): SaleLineData => new SaleLineData(
                        (int) $line['article_id'],
                        $line['quantity'],
                        $line['unit_price'],
                    ),
                    $payload['lines'],
                ),
                array_map(
                    static fn (array $payment): SalePaymentData => new SalePaymentData(
                        (int) $payment['payment_method_id'],
                        PaymentType::from($payment['type']),
                        $payment['amount'],
                        (int) $payment['cash_session_id'],
                        $payment['command_uuid'],
                        $payment['received_amount'] ?? null,
                        $payment['change'] ?? null,
                        $payment['observation'] ?? null,
                    ),
                    $payload['payments'],
                ),
                $payload['client_id'] ?? null,
                $payload['observation'] ?? null,
                isset($payload['completed_at'])
                    ? CarbonImmutable::parse($payload['completed_at'])
                    : null,
            ),
        ),
        'delivery' => app(OrderService::class)->deliver(
            $actor,
            new DeliverOrderCommand(
                (int) $payload['order_id'],
                $payload['command_uuid'],
                isset($payload['final_payment'])
                    ? new OrderPaymentData(
                        (int) $payload['final_payment']['payment_method_id'],
                        PaymentType::from($payload['final_payment']['type']),
                        $payload['final_payment']['amount'],
                        (int) $payload['final_payment']['cash_session_id'],
                        $payload['final_payment']['command_uuid'],
                        $payload['final_payment']['received_amount'] ?? null,
                        $payload['final_payment']['change'] ?? null,
                        $payload['final_payment']['observation'] ?? null,
                    )
                    : null,
            ),
        ),
        'production_confirm' => app(ProductionService::class)->confirm(
            $actor,
            new ConfirmProductionCommand((int) $payload['production_id'], $payload['command_uuid']),
        ),
        'production_annul' => app(ProductionService::class)->annul(
            $actor,
            new AnnulProductionCommand(
                (int) $payload['production_id'],
                $payload['command_uuid'],
                $payload['reason'],
            ),
        ),
        'sale_annul' => app(SaleService::class)->annul(
            $actor,
            new AnnulSaleCommand(
                (int) $payload['sale_id'],
                $payload['command_uuid'],
                $payload['reason'],
            ),
        ),
        default => throw new InvalidArgumentException('Escenario concurrente desconocido.'),
    };

    echo 'RESULT '.json_encode([
        'ok' => true,
        'result_id' => method_exists($result, 'getKey') ? $result->getKey() : null,
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    echo 'RESULT '.json_encode([
        'ok' => false,
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR)."\n";
}
