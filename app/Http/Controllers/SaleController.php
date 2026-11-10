<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\UserFacingError;

use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Http\Requests\Sales\CompleteSaleRequest;
use App\Models\User;
use App\Models\Venta;
use App\Services\Articles\SellableArticleLookupService;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Cash\CashSessionGuard;
use App\Services\Sales\RecentSalesService;
use App\Services\Sales\SaleService;
use App\Support\CommercialDate;
use App\Support\Decimal;
use App\Support\InventoryQuantity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class SaleController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly SellableArticleLookupService $lookup,
        private readonly SaleService $sales,
        private readonly RecentSalesService $recentSales,
    ) {
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorizeSales($user);

        $initialLines = old('lines', []);
        $articleIds = collect(is_array($initialLines) ? $initialLines : [])
            ->pluck('article_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $cashSessions = $this->openCashSessions();

        return view('sales.create', [
            'user' => $user,
            'paymentMethods' => $this->activePaymentMethods(),
            'cashSessions' => $cashSessions,
            'cashSessionNotice' => $cashSessions === [] ? $this->cashSessionNotice() : null,
            'recentSales' => $this->recentSales->current(),
            'initialCart' => $this->articlePayload($articleIds),
            'initialLines' => is_array($initialLines) ? $initialLines : [],
            'initialPayments' => old('payments', []),
            'commandUuid' => old('command_uuid', (string) Str::uuid()),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeSales($user);

        $term = $request->query('term');
        if (! is_string($term)) {
            return response()->json(['data' => [], 'exact_match' => false]);
        }

        $term = trim($term);
        $articles = $this->lookup->search($term);
        $payload = $this->articlePayload($articles->modelKeys());
        $exactMatch = false;

        if ($articles->count() === 1) {
            $article = $articles->first();
            $exactMatch = $article !== null && (
                (is_string($article->codigo_barras) && $article->codigo_barras === $term)
                || (is_string($article->sku) && $article->sku === $term)
                || (ctype_digit($term) && (int) $term === (int) $article->getKey())
            );
        }

        return response()->json([
            'data' => array_values($payload),
            'exact_match' => $exactMatch,
        ]);
    }

    public function recent(Request $request, string $session): JsonResponse
    {
        $this->authorizeSales($request->user());
        $recent = $this->recentSales->current($session);

        return ($recent === null
            ? response()->json(['message' => 'La sesión de Caja principal ya no está vigente. Abre Nueva venta para consultar la jornada actual.'], 409)
            : response()->json([
                'html' => view('sales._recent-rows', ['rows' => $recent['rows']])->render(),
                'collected' => $recent['collected'],
            ]))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(CompleteSaleRequest $request): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeSales($user);
        $validated = $request->validated();

        $command = $this->commandFrom($validated);

        try {
            $sale = $this->sales->complete($user, $command);
        } catch (InsufficientStockException) {
            return back()->withInput()->withErrors([
                'checkout' => 'No hay stock suficiente para completar la venta. Revisa las cantidades.',
            ]);
        } catch (IdempotencyConflictException) {
            return back()->withInput()->withErrors([
                'checkout' => 'Esta intención de cobro ya fue utilizada con otros datos. Revisa el carrito e inténtalo nuevamente.',
            ]);
        } catch (InventoryDomainException $exception) {
            return back()->withInput()->withErrors([
                'checkout' => $this->domainMessage($exception),
            ]);
        } catch (ModelNotFoundException $exception) {
            return back()->withInput()->withErrors([
                'checkout' => $this->notFoundMessage($exception),
            ]);
        }

        $request->session()->flash('sale_result', $sale->getKey());

        return redirect()->route('sales.success');
    }

    public function success(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $this->authorizeSales($user);
        $saleId = $request->session()->pull('sale_result');

        if (! is_numeric($saleId) || (int) $saleId < 1) {
            return redirect()->route('sales.create');
        }

        $sale = Venta::query()
            ->with(['pagos.metodoPago'])
            ->whereKey((int) $saleId)
            ->where('usuario_id', $user->getKey())
            ->first();

        if ($sale === null) {
            return redirect()->route('sales.create');
        }

        $change = '0.00';
        foreach ($sale->pagos as $payment) {
            if ($payment->vuelto !== null) {
                $change = Decimal::add($change, (string) $payment->vuelto, 2);
            }
        }

        return view('sales.success', [
            'user' => $user,
            'sale' => $sale,
            'change' => $change,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function commandFrom(array $data): CompleteSaleCommand
    {
        $sessionId = (int) $data['cash_session_id'];
        $validSessionIds = collect($this->openCashSessions())->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if (! in_array($sessionId, $validSessionIds, true)) {
            throw ValidationException::withMessages([
                'cash_session_id' => $validSessionIds === [] ? $this->cashSessionNotice() : 'Selecciona una sesión de caja vigente para realizar ventas.',
            ]);
        }

        $rawLines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $articleIds = collect($rawLines)->pluck('article_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $articles = DB::table('articulos')->whereIn('id', $articleIds)->get()->keyBy('id');
        $lines = [];
        $total = '0.00';

        foreach ($rawLines as $index => $rawLine) {
            $articleId = (int) ($rawLine['article_id'] ?? 0);
            $article = $articles->get($articleId);

            if ($article === null || ! (bool) $article->activo || ! in_array($article->tipo_articulo, ['elaborado', 'reventa'], true)) {
                $this->invalid('lines.'.$index.'.article_id', 'El producto ya no está disponible para la venta.');
            }

            if ($article->precio_venta_actual === null) {
                $this->invalid('lines.'.$index.'.article_id', 'El producto no tiene un precio de venta vigente.');
            }

            try {
                $quantity = Decimal::quantity((string) ($rawLine['quantity'] ?? ''));
                $unitPrice = Decimal::money((string) $article->precio_venta_actual);
            } catch (Throwable) {
                $this->invalid('lines.'.$index.'.quantity', 'La cantidad o el precio del producto no son válidos.');
            }

            $lines[] = new SaleLineData($articleId, $quantity, $unitPrice);
            $total = Decimal::add($total, Decimal::multiply($quantity, $unitPrice, 2), 2);
        }

        $methodIds = collect($data['payments'] ?? [])
            ->pluck('payment_method_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $methods = DB::table('metodos_pago')->whereIn('id', $methodIds)->where('activo', true)->get()->keyBy('id');
        $paymentTotal = '0.00';
        $payments = [];

        foreach (($data['payments'] ?? []) as $index => $rawPayment) {
            $methodId = (int) ($rawPayment['payment_method_id'] ?? 0);
            $method = $methods->get($methodId);

            if ($method === null) {
                $this->invalid('payments.'.$index.'.payment_method_id', 'El método de pago no está disponible.');
            }

            try {
                $amount = Decimal::money((string) ($rawPayment['amount'] ?? ''));
                if (Decimal::compare($amount, '0.00', 2) <= 0) {
                    $this->invalid('payments.'.$index.'.amount', 'Cada pago debe ser mayor que cero.');
                }
            } catch (Throwable) {
                $this->invalid('payments.'.$index.'.amount', 'El monto de pago no es válido.');
            }

            $received = null;
            $change = null;
            $receivedInput = $rawPayment['received_amount'] ?? null;

            if ((bool) $method->afecta_efectivo) {
                if ($receivedInput === null || $receivedInput === '') {
                    $this->invalid('payments.'.$index.'.received_amount', 'Indica el monto recibido en efectivo.');
                }

                try {
                    $received = Decimal::money((string) $receivedInput);
                } catch (Throwable) {
                    $this->invalid('payments.'.$index.'.received_amount', 'El monto recibido no es válido.');
                }

                if (Decimal::compare($received, $amount, 2) < 0) {
                    $this->invalid('payments.'.$index.'.received_amount', 'El efectivo recibido es menor al monto del pago.');
                }

                $change = Decimal::subtract($received, $amount, 2);
            } elseif ($receivedInput !== null && $receivedInput !== '') {
                $this->invalid('payments.'.$index.'.received_amount', 'El monto recibido solo aplica al método efectivo.');
            }

            $paymentTotal = Decimal::add($paymentTotal, $amount, 2);
            $payments[] = new SalePaymentData(
                paymentMethodId: $methodId,
                type: PaymentType::Charge,
                amount: $amount,
                cashSessionId: $sessionId,
                commandUuid: (string) $rawPayment['payment_uuid'],
                receivedAmount: $received,
                change: $change,
            );
        }

        if (Decimal::compare($paymentTotal, $total, 2) !== 0) {
            throw ValidationException::withMessages([
                'checkout' => 'La suma de los pagos debe coincidir exactamente con el total de la venta.',
            ]);
        }

        return new CompleteSaleCommand(
            commandUuid: (string) $data['command_uuid'],
            cashSessionId: $sessionId,
            lines: $lines,
            payments: $payments,
        );
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function articlePayload(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $articles = DB::table('articulos')
            ->leftJoin('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->whereIn('articulos.id', $ids)
            ->select([
                'articulos.id', 'articulos.nombre', 'articulos.sku', 'articulos.codigo_barras',
                'articulos.precio_venta_actual', 'articulos.stock_actual',
                'unidades_medida.codigo AS unidad_codigo', 'unidades_medida.simbolo AS unidad_simbolo',
            ])
            ->get()
            ->keyBy('id');

        $payload = [];
        foreach ($ids as $id) {
            $article = $articles->get($id);
            if ($article === null) {
                continue;
            }

            $payload[$id] = $this->articleData($article);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function articleData(object $article): array
    {
        return [
            'id' => (int) $article->id,
            'nombre' => (string) $article->nombre,
            'sku' => (string) $article->sku,
            'codigo_barras' => $article->codigo_barras,
            'precio' => $article->precio_venta_actual === null ? null : Decimal::money((string) $article->precio_venta_actual),
            'stock' => Decimal::quantity((string) $article->stock_actual),
            'stock_label' => InventoryQuantity::format((string) $article->stock_actual, $article->unidad_simbolo),
            'unidad_codigo' => $article->unidad_codigo,
            'unidad_simbolo' => $article->unidad_simbolo,
            'vendible' => $article->precio_venta_actual !== null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function activePaymentMethods(): array
    {
        return DB::table('metodos_pago')
            ->where('activo', true)
            ->orderBy('orden_presentacion')
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre', 'afecta_efectivo'])
            ->map(static fn (object $method): array => [
                'id' => (int) $method->id,
                'codigo' => (string) $method->codigo,
                'nombre' => (string) $method->nombre,
                'afecta_efectivo' => (bool) $method->afecta_efectivo,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function openCashSessions(): array
    {
        return CashSessionGuard::operationalRegisterQuery(CommercialDate::today())
            ->orderByDesc('sesiones_caja.abierta_en')
            ->get([
                'sesiones_caja.id', 'sesiones_caja.caja_id', 'cajas.nombre AS caja_nombre',
                'sesiones_caja.abierta_en',
            ])
            ->map(static fn (object $session): array => [
                'id' => (int) $session->id,
                'caja_id' => (int) $session->caja_id,
                'caja_nombre' => (string) $session->caja_nombre,
                'abierta_en' => (string) $session->abierta_en,
            ])
            ->all();
    }

    private function cashSessionNotice(): string
    {
        $open = CashSessionGuard::openRegisterQuery();
        if ((clone $open)->where('sesiones_caja.fecha_operativa', '<', CommercialDate::today())->exists()) {
            return 'No puedes completar ventas porque hay una sesión de caja anterior pendiente de cierre. Solicita a un dueño que la revise.';
        }

        return $open->exists()
            ? 'No puedes completar ventas porque la sesión de caja no tiene una jornada vigente. Solicita a un dueño que la revise.'
            : 'Debes abrir una sesión de caja vigente antes de cobrar.';
    }

    private function authorizeSales(?User $user): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, PermissionCode::SalesCreate);
    }


    private function domainMessage(InventoryDomainException $exception): string
    {
        return UserFacingError::message($exception, 'La venta no puede completarse con los datos actuales.');
    }

    private function notFoundMessage(ModelNotFoundException $exception): string
    {
        return $exception->getModel() === 'sesiones_caja'
            ? 'No hay una sesión de caja disponible para realizar ventas.'
            : 'El producto, método de pago o sesión seleccionada ya no está disponible.';
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
