<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Orders\CancelOrderCommand;
use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\DeliverOrderCommand;
use App\Data\Orders\OrderLineData;
use App\Data\Orders\OrderPaymentData;
use App\Data\Orders\TransitionOrderCommand;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Http\Requests\Orders\CancelOrderRequest;
use App\Http\Requests\Orders\CreateOrderRequest;
use App\Http\Requests\Orders\DeliverOrderRequest;
use App\Http\Requests\Orders\TransitionOrderRequest;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Articles\SellableArticleLookupService;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Orders\OrderService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class OrderController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly SellableArticleLookupService $lookup,
        private readonly OrderService $orders,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeOrdersView($user);

        $base = Pedido::query();
        $this->applyFilters($base, $request);

        $summary = [];
        foreach (OrderStatus::cases() as $status) {
            $summary[$status->value] = (clone $base)->where('estado', $status->value)->count();
        }

        $orders = $base
            ->with(['cliente', 'detalles', 'pagos.metodoPago'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $orderSummaries = $orders->getCollection()->mapWithKeys(fn (Pedido $order): array => [
            $order->getKey() => $this->finance($order),
        ])->all();

        return view('orders.index', [
            'user' => $user,
            'orders' => $orders,
            'orderSummaries' => $orderSummaries,
            'summary' => $summary,
            'canCreate' => $this->permissions->allows($user, PermissionCode::OrdersCreate),
            'filters' => $request->only(['status', 'date_from', 'date_until', 'q']),
            'statuses' => OrderStatus::cases(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::OrdersCreate);

        $initialLines = old('lines', []);
        $articleIds = collect(is_array($initialLines) ? $initialLines : [])
            ->pluck('article_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return view('orders.create', [
            'user' => $user,
            'paymentMethods' => $this->activePaymentMethods(),
            'cashSessions' => $this->openCashSessions(),
            'initialCart' => $this->articlePayload($articleIds),
            'initialLines' => is_array($initialLines) ? $initialLines : [],
            'initialPayments' => old('payments', []),
            'clients' => $this->clientPayload(),
            'initialClientId' => old('client_id'),
            'commandUuid' => old('command_uuid', (string) Str::uuid()),
        ]);
    }

    public function searchArticles(Request $request): JsonResponse
    {
        $this->authorize($request->user(), PermissionCode::OrdersCreate);
        $term = $request->query('term');

        if (! is_string($term) || trim($term) === '') {
            return response()->json(['data' => [], 'exact_match' => false]);
        }

        $term = trim($term);
        $articles = $this->lookup->search($term);
        $data = $this->articlePayload($articles->modelKeys());
        $exact = false;

        if ($articles->count() === 1) {
            $article = $articles->first();
            $exact = $article !== null && (
                $article->codigo_barras === $term
                || $article->sku === $term
                || (ctype_digit($term) && (int) $term === (int) $article->getKey())
            );
        }

        return response()->json(['data' => array_values($data), 'exact_match' => $exact]);
    }

    public function searchClients(Request $request): JsonResponse
    {
        $this->authorize($request->user(), PermissionCode::OrdersCreate);
        $term = trim((string) $request->query('term', ''));

        if ($term === '') {
            return response()->json(['data' => $this->clientPayload()]);
        }

        $clients = \DB::table('clientes')
            ->where('activo', true)
            ->where(function ($query) use ($term): void {
                $query->where('nombre', 'ilike', '%'.$term.'%');
                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }
            })
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'nombre', 'correo']);

        return response()->json([
            'data' => $clients->map(static fn (object $client): array => [
                'id' => (int) $client->id,
                'nombre' => (string) $client->nombre,
                'correo' => $client->correo,
            ])->all(),
        ]);
    }

    public function store(CreateOrderRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        try {
            $order = $this->orders->create($user, $this->createCommand($data));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo crear el pedido. Revisa los datos e inténtalo nuevamente.');
        }

        return redirect()->route('orders.show', $order)->with('success', 'Pedido creado.');
    }

    public function show(Request $request, Pedido $pedido): View
    {
        $user = $request->user();
        $this->authorizeOrdersView($user);
        $pedido->load(['cliente', 'usuarioCreador', 'detalles', 'pagos.metodoPago', 'transiciones.usuario', 'venta']);
        $finance = $this->finance($pedido);

        return view('orders.show', [
            'user' => $user,
            'order' => $pedido,
            'finance' => $finance,
            'paymentMethods' => $this->activePaymentMethods(),
            'cashSessions' => $this->openCashSessions(),
            'canManage' => $this->permissions->allows($user, PermissionCode::OrdersManage),
            'canCancel' => $this->permissions->allows($user, PermissionCode::OrdersCancel),
            'canDeliver' => $this->permissions->allows($user, PermissionCode::OrdersDeliver),
            'canCollect' => $this->permissions->allows($user, PermissionCode::OrdersCollect),
            'deliveryUuid' => old('command_uuid', (string) Str::uuid()),
        ]);
    }

    public function confirm(Pedido $pedido, TransitionOrderRequest $request): RedirectResponse
    {
        return $this->transition($pedido, $request, OrderStatus::Confirmed, 'Pedido confirmado.');
    }

    public function preparation(Pedido $pedido, TransitionOrderRequest $request): RedirectResponse
    {
        return $this->transition($pedido, $request, OrderStatus::InPreparation, 'Pedido enviado a preparación.');
    }

    public function ready(Pedido $pedido, TransitionOrderRequest $request): RedirectResponse
    {
        return $this->transition($pedido, $request, OrderStatus::Ready, 'Pedido listo.');
    }

    public function cancel(Pedido $pedido, CancelOrderRequest $request): RedirectResponse
    {
        try {
            $this->orders->cancel($request->user(), new CancelOrderCommand(
                (int) $pedido->getKey(),
                $request->validated('command_uuid'),
                trim($request->validated('reason')),
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo cancelar el pedido.');
        }

        return redirect()->route('orders.show', $pedido)->with('success', 'Pedido cancelado.');
    }

    public function deliver(Pedido $pedido, DeliverOrderRequest $request): RedirectResponse
    {
        try {
            $this->orders->deliver($request->user(), $this->deliveryCommand($pedido, $request->validated()));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo entregar el pedido. Revisa el saldo, la caja y el stock.');
        }

        return redirect()->route('orders.show', $pedido)->with('success', 'Pedido entregado y convertido en venta.');
    }

    private function transition(
        Pedido $pedido,
        TransitionOrderRequest $request,
        OrderStatus $target,
        string $message,
    ): RedirectResponse {
        try {
            $this->orders->transition($request->user(), new TransitionOrderCommand(
                (int) $pedido->getKey(),
                $request->validated('command_uuid'),
                $target,
            ));
        } catch (Throwable $exception) {
            return $this->failure($exception, 'No se pudo actualizar el estado del pedido.');
        }

        return redirect()->route('orders.show', $pedido)->with('success', $message);
    }

    /** @param array<string, mixed> $data */
    private function createCommand(array $data): CreateOrderCommand
    {
        $articleIds = collect($data['lines'])->pluck('article_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $articles = \DB::table('articulos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->whereIn('articulos.id', $articleIds)
            ->where('articulos.activo', true)
            ->whereIn('articulos.tipo_articulo', ['elaborado', 'reventa'])
            ->whereNotNull('articulos.precio_venta_actual')
            ->get([
                'articulos.id', 'articulos.precio_venta_actual',
                'unidades_medida.codigo as unidad_codigo', 'unidades_medida.simbolo as unidad_simbolo',
            ])
            ->keyBy('id');

        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $article = $articles->get((int) $line['article_id']);
            if ($article === null) {
                $this->invalid('lines.'.$index.'.article_id', 'El artículo ya no está habilitado para pedidos.');
            }

            try {
                $lines[] = new OrderLineData(
                    (int) $line['article_id'],
                    (string) $line['quantity'],
                    Decimal::money((string) $article->precio_venta_actual),
                    $this->nullableTrim($line['observation'] ?? null),
                );
            } catch (Throwable) {
                $this->invalid('lines.'.$index.'.quantity', 'La cantidad del artículo no es válida.');
            }
        }

        $payments = [];
        $rawPayments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        $sessionId = (int) ($data['cash_session_id'] ?? 0);
        if ($rawPayments !== [] && $sessionId < 1) {
            $this->invalid('cash_session_id', 'Selecciona una sesión de caja para registrar el anticipo.');
        }
        $methods = \DB::table('metodos_pago')->whereIn('id', collect($rawPayments)->pluck('payment_method_id')->map(static fn (mixed $id): int => (int) $id)->all())->where('activo', true)->get()->keyBy('id');

        foreach ($rawPayments as $index => $payment) {
            $method = $methods->get((int) $payment['payment_method_id']);
            if ($method === null) {
                $this->invalid('payments.'.$index.'.payment_method_id', 'El método de pago no está disponible.');
            }

            try {
                $amount = Decimal::money((string) $payment['amount']);
                $received = null;
                $change = null;
                if ((bool) $method->afecta_efectivo) {
                    if (($payment['received_amount'] ?? '') === '') {
                        $this->invalid('payments.'.$index.'.received_amount', 'Indica el efectivo recibido.');
                    }
                    $received = Decimal::money((string) $payment['received_amount']);
                    if (Decimal::compare($received, $amount, 2) < 0) {
                        $this->invalid('payments.'.$index.'.received_amount', 'El efectivo recibido es menor al anticipo.');
                    }
                    $change = Decimal::subtract($received, $amount, 2);
                } elseif (($payment['received_amount'] ?? '') !== '') {
                    $this->invalid('payments.'.$index.'.received_amount', 'El efectivo recibido solo aplica al método efectivo.');
                }

                $payments[] = new OrderPaymentData(
                    (int) $payment['payment_method_id'],
                    PaymentType::Charge,
                    $amount,
                    $sessionId,
                    (string) $payment['payment_uuid'],
                    $received,
                    $change,
                );
            } catch (Throwable) {
                $this->invalid('payments.'.$index.'.amount', 'El monto del anticipo no es válido.');
            }
        }

        return new CreateOrderCommand(
            (string) $data['command_uuid'],
            (int) $data['client_id'],
            $lines,
            $payments,
            ! empty($data['scheduled_delivery_at']) ? CarbonImmutable::parse((string) $data['scheduled_delivery_at']) : null,
            $this->nullableTrim($data['observation'] ?? null),
        );
    }

    /** @param array<string, mixed> $data */
    private function deliveryCommand(Pedido $pedido, array $data): DeliverOrderCommand
    {
        $finance = $this->finance($pedido->load(['detalles', 'pagos']));
        $raw = $data['final_payment'] ?? null;
        $payment = null;

        if (is_array($raw)) {
            $amount = Decimal::money((string) ($raw['amount'] ?? ''));
            if (Decimal::compare($amount, $finance['balance'], 2) !== 0) {
                $this->invalid('final_payment.amount', 'El pago final debe cubrir exactamente el saldo.');
            }
            $method = \DB::table('metodos_pago')->where('id', (int) $raw['payment_method_id'])->where('activo', true)->first();
            if ($method === null) {
                $this->invalid('final_payment.payment_method_id', 'El método de pago no está disponible.');
            }

            $received = null;
            $change = null;
            if ((bool) $method->afecta_efectivo) {
                if (($raw['received_amount'] ?? '') === '') {
                    $this->invalid('final_payment.received_amount', 'Indica el efectivo recibido.');
                }
                $received = Decimal::money((string) $raw['received_amount']);
                if (Decimal::compare($received, $amount, 2) < 0) {
                    $this->invalid('final_payment.received_amount', 'El efectivo recibido es menor al saldo.');
                }
                $change = Decimal::subtract($received, $amount, 2);
            } elseif (($raw['received_amount'] ?? '') !== '') {
                $this->invalid('final_payment.received_amount', 'El efectivo recibido solo aplica al método efectivo.');
            }

            $payment = new OrderPaymentData(
                (int) $raw['payment_method_id'],
                PaymentType::Charge,
                $amount,
                (int) $raw['cash_session_id'],
                (string) $raw['payment_uuid'],
                $received,
                $change,
            );
        }

        return new DeliverOrderCommand((int) $pedido->getKey(), (string) $data['command_uuid'], $payment);
    }

    private function applyFilters($query, Request $request): void
    {
        $status = $request->query('status');
        if (is_string($status) && in_array($status, array_map(static fn (OrderStatus $item): string => $item->value, OrderStatus::cases()), true)) {
            $query->where('estado', $status);
        }
        if (is_string($request->query('date_from')) && $request->query('date_from') !== '') {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if (is_string($request->query('date_until')) && $request->query('date_until') !== '') {
            $query->whereDate('created_at', '<=', $request->query('date_until'));
        }
        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $query->where(function ($nested) use ($term): void {
                if (ctype_digit($term)) {
                    $nested->where('id', (int) $term);
                }
                $nested->orWhereHas('cliente', static fn ($client) => $client->where('nombre', 'ilike', '%'.$term.'%'))
                    ->orWhereHas('detalles', static fn ($detail) => $detail
                        ->where('articulo_nombre_snapshot', 'ilike', '%'.$term.'%')
                        ->orWhere('articulo_sku_snapshot', 'ilike', '%'.$term.'%'));
            });
        }
    }

    /** @return array{total: string, paid: string, balance: string} */
    private function finance(Pedido $order): array
    {
        $total = '0.00';
        foreach ($order->detalles as $detail) {
            $total = Decimal::add($total, Decimal::multiply((string) $detail->cantidad, (string) $detail->precio_unitario, 2), 2);
        }
        $paid = '0.00';
        foreach ($order->pagos as $payment) {
            $type = $payment->tipo instanceof PaymentType ? $payment->tipo->value : (string) $payment->tipo;
            $paid = $type === PaymentType::Charge->value
                ? Decimal::add($paid, (string) $payment->monto, 2)
                : Decimal::subtract($paid, (string) $payment->monto, 2);
        }

        return [
            'total' => $total,
            'paid' => $paid,
            'balance' => Decimal::compare($total, $paid, 2) > 0 ? Decimal::subtract($total, $paid, 2) : '0.00',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function articlePayload(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $articles = \DB::table('articulos')
            ->leftJoin('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->whereIn('articulos.id', $ids)
            ->where('articulos.activo', true)
            ->whereIn('articulos.tipo_articulo', ['elaborado', 'reventa'])
            ->whereNotNull('articulos.precio_venta_actual')
            ->get(['articulos.id', 'articulos.nombre', 'articulos.sku', 'articulos.codigo_barras', 'articulos.precio_venta_actual', 'unidades_medida.codigo AS unidad_codigo', 'unidades_medida.simbolo AS unidad_simbolo'])
            ->keyBy('id');

        $payload = [];
        foreach ($ids as $id) {
            $article = $articles->get($id);
            if ($article !== null) {
                $payload[$id] = [
                    'id' => (int) $article->id,
                    'nombre' => (string) $article->nombre,
                    'sku' => (string) $article->sku,
                    'codigo_barras' => $article->codigo_barras,
                    'precio' => Decimal::money((string) $article->precio_venta_actual),
                    'unidad_simbolo' => $article->unidad_simbolo,
                ];
            }
        }
        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function clientPayload(): array
    {
        return \DB::table('clientes')->where('activo', true)->orderBy('nombre')->limit(30)->get(['id', 'nombre', 'correo'])->map(static fn (object $client): array => [
            'id' => (int) $client->id,
            'nombre' => (string) $client->nombre,
            'correo' => $client->correo,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function activePaymentMethods(): array
    {
        return \DB::table('metodos_pago')->where('activo', true)->orderBy('orden_presentacion')->orderBy('id')->get(['id', 'nombre', 'afecta_efectivo'])->map(static fn (object $method): array => [
            'id' => (int) $method->id,
            'nombre' => (string) $method->nombre,
            'afecta_efectivo' => (bool) $method->afecta_efectivo,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function openCashSessions(): array
    {
        return \DB::table('sesiones_caja')->join('cajas', 'cajas.id', '=', 'sesiones_caja.caja_id')->whereNull('sesiones_caja.cerrada_en')->where('cajas.activo', true)->orderByDesc('sesiones_caja.abierta_en')->get(['sesiones_caja.id', 'cajas.nombre AS caja_nombre'])->map(static fn (object $session): array => [
            'id' => (int) $session->id,
            'nombre' => (string) $session->caja_nombre,
        ])->all();
    }

    private function authorizeOrdersView(?User $user): void
    {
        abort_unless($user instanceof User, 401);
        abort_unless($this->hasAnyOrderPermission($user), 403);
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }

    private function hasAnyOrderPermission(User $user): bool
    {
        return collect([
            PermissionCode::OrdersCreate,
            PermissionCode::OrdersManage,
            PermissionCode::OrdersCancel,
            PermissionCode::OrdersDeliver,
            PermissionCode::OrdersCollect,
        ])->contains(fn (PermissionCode $permission): bool => $this->permissions->allows($user, $permission));
    }


    private function failure(Throwable $exception, string $fallback): RedirectResponse
    {
        if ($exception instanceof ValidationException) {
            throw $exception;
        }
        if ($exception instanceof ModelNotFoundException) {
            $fallback = 'El pedido, artículo, método de pago o sesión ya no está disponible.';
        } elseif ($exception instanceof InventoryDomainException || $exception instanceof IdempotencyConflictException) {
            $fallback = $exception->getMessage() !== '' ? $exception->getMessage() : $fallback;
        } else {
            report($exception);
        }

        return back()->withInput()->withErrors(['order' => $fallback]);
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
