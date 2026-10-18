<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Http\Requests\Inventory\StoreAdjustmentRequest;
use App\Http\Requests\Inventory\StoreCountRequest;
use App\Http\Requests\Inventory\StoreWasteRequest;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\CountService;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\WasteService;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class InventoryController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryAdjustmentService $adjustments,
        private readonly CountService $counts,
        private readonly WasteService $wastes,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ArticlesView);
        $term = trim((string) $request->query('q', ''));
        $lowOnly = $request->boolean('stock_bajo');
        $query = Articulo::query()->with(['unidad', 'categoria']);

        if ($term !== '') {
            $escaped = addcslashes($term, '\\%_');
            $query->where(fn ($builder) => $builder
                ->where('nombre', 'ilike', "%{$escaped}%")
                ->orWhere('sku', 'ilike', "%{$escaped}%")
                ->orWhere('codigo_barras', 'ilike', "%{$escaped}%"));
        }

        if ($lowOnly) {
            $query->whereColumn('stock_actual', '<=', 'stock_minimo');
        }

        $articles = $query->orderBy('nombre')->orderBy('id')->paginate(15)->withQueryString();
        $recentMovements = DB::table('movimientos_inventario')->whereDate('ocurrido_en', '>=', now()->subDays(7))->count();
        $recentReceipts = DB::table('recepciones_mercaderia')->whereDate('created_at', '>=', now()->subDays(7))->count();

        return view('inventory.index', [
            ...$this->pageData($user),
            'articles' => $articles,
            'canInput' => $this->permissions->allows($user, PermissionCode::InventoryAdjustmentsInput),
            'filters' => ['q' => $term, 'stock_bajo' => $lowOnly],
            'metrics' => [
                'low' => Articulo::query()->whereColumn('stock_actual', '<=', 'stock_minimo')->count(),
                'empty' => Articulo::query()->where('stock_actual', '0.000')->count(),
                'movements' => $recentMovements,
                'receipts' => $recentReceipts,
            ],
        ]);
    }

    public function show(Request $request, Articulo $articulo): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ArticlesView);
        $articleId = (int) $articulo->getKey();
        $movements = MovimientoInventario::query()
            ->where('articulo_id', $articleId)->orderByDesc('ocurrido_en')->orderByDesc('id')->limit(30)->get();
        $receipts = DB::table('detalle_recepciones_mercaderia as d')
            ->join('recepciones_mercaderia as r', 'r.id', '=', 'd.recepcion_mercaderia_id')
            ->where('d.articulo_id', $articleId)->orderByDesc('r.ocurrido_en')->limit(20)
            ->get(['r.id', 'r.estado', 'r.ocurrido_en', 'r.tipo_origen', 'd.cantidad', 'd.costo_unitario']);
        $wastes = DB::table('mermas')->where('articulo_id', $articleId)->orderByDesc('ocurrido_en')->limit(20)->get();
        $counts = DB::table('detalle_conteos_inventario as d')
            ->join('conteos_inventario as c', 'c.id', '=', 'd.conteo_inventario_id')
            ->where('d.articulo_id', $articleId)->orderByDesc('c.created_at')->limit(20)
            ->get(['c.id', 'c.estado', 'c.created_at', 'd.cantidad_contada', 'd.stock_teorico_snapshot']);
        $daily = DB::table('stock_diario')->where('articulo_id', $articleId)->orderByDesc('fecha_comercial')->limit(20)->get();
        $canCosts = $this->permissions->allows($user, PermissionCode::InventoryCostsView);
        $costs = $canCosts ? DB::table('historial_costos_articulo')->where('articulo_id', $articleId)->orderByDesc('vigente_desde')->limit(20)->get() : collect();

        return view('inventory.show', [
            ...$this->pageData($user), 'article' => $articulo->load(['unidad', 'categoria']),
            'movements' => $movements, 'receipts' => $receipts, 'wastes' => $wastes,
            'counts' => $counts, 'daily' => $daily, 'costs' => $costs, 'canCosts' => $canCosts,
        ]);
    }

    public function movements(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::InventoryMovementsView);
        $query = DB::table('movimientos_inventario as m')->join('articulos as a', 'a.id', '=', 'm.articulo_id');
        if (is_numeric($request->query('articulo_id'))) $query->where('m.articulo_id', (int) $request->query('articulo_id'));
        if ($request->filled('tipo')) $query->where('m.tipo_movimiento', $request->query('tipo'));
        if ($request->filled('desde')) $query->whereDate('m.ocurrido_en', '>=', $request->query('desde'));
        if ($request->filled('hasta')) $query->whereDate('m.ocurrido_en', '<=', $request->query('hasta'));
        $movements = $query->orderByDesc('m.ocurrido_en')->orderByDesc('m.id')->paginate(25)->withQueryString();
        $types = DB::table('movimientos_inventario')->select('tipo_movimiento')->distinct()->orderBy('tipo_movimiento')->pluck('tipo_movimiento');

        return view('inventory.movements', [...$this->pageData($user), 'movements' => $movements, 'types' => $types, 'filters' => $request->only(['articulo_id', 'tipo', 'desde', 'hasta'])]);
    }

    public function adjustmentCreate(Request $request): View
    {
        $user = $request->user();
        $this->authorizeAny($user, [PermissionCode::InventoryAdjustmentsInput, PermissionCode::InventoryAdjustmentsOutput]);
        return view('inventory.adjustment', [...$this->pageData($user), 'articles' => $this->activeArticles(), 'canInput' => $this->permissions->allows($user, PermissionCode::InventoryAdjustmentsInput), 'canOutput' => $this->permissions->allows($user, PermissionCode::InventoryAdjustmentsOutput)]);
    }

    public function adjustmentStore(StoreAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $occurredAt = $this->date($data['ocurrido_en'] ?? null);
        try {
            $movement = $data['tipo'] === 'entrada'
                ? $this->adjustments->manualEntry($request->user(), (int) $data['articulo_id'], $data['cantidad'], (string) Str::uuid(), $data['motivo'], $occurredAt)
                : $this->adjustments->manualExit($request->user(), (int) $data['articulo_id'], $data['cantidad'], (string) Str::uuid(), $data['motivo'], $occurredAt);
            return redirect()->route('inventory.movements')->with('success', 'Ajuste registrado. Movimiento #'.$movement->getKey().'.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        }
    }

    public function counts(Request $request): View
    {
        $user = $request->user();
        $this->authorizeAny($user, [PermissionCode::InventoryCountsManage, PermissionCode::InventoryCountsConfirm]);
        $counts = DB::table('conteos_inventario as c')->join('usuarios as u', 'u.id', '=', 'c.usuario_creador_id')->select('c.*', 'u.nombre as creador')->orderByDesc('c.created_at')->paginate(15)->withQueryString();
        $details = DB::table('detalle_conteos_inventario as d')->join('articulos as a', 'a.id', '=', 'd.articulo_id')->whereIn('d.conteo_inventario_id', $counts->getCollection()->pluck('id'))->get(['d.*', 'a.stock_actual as stock_sistema'])->groupBy('conteo_inventario_id');
        return view('inventory.counts.index', [...$this->pageData($user), 'counts' => $counts, 'details' => $details, 'canCreate' => $this->permissions->allows($user, PermissionCode::InventoryCountsManage), 'canConfirm' => $this->permissions->allows($user, PermissionCode::InventoryCountsConfirm)]);
    }

    public function countCreate(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::InventoryCountsManage);
        return view('inventory.counts.create', [...$this->pageData($user), 'articles' => $this->activeArticles()]);
    }

    public function countStore(StoreCountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $lines = array_map(static fn (array $line): array => ['articleId' => (int) $line['articulo_id'], 'countedQuantity' => $line['cantidad_contada']], $data['articulos']);
        try {
            $count = $this->counts->create($request->user(), (string) Str::uuid(), $lines, $data['observacion'] ?? null);
            return redirect()->route('inventory.counts')->with('success', 'Conteo #'.$count->id.' creado en borrador.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        }
    }

    public function countConfirm(Request $request, int $conteo): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::InventoryCountsConfirm);
        try {
            $this->counts->confirm($request->user(), $conteo, (string) Str::uuid());
            return back()->with('success', 'Conteo aplicado mediante el servicio de inventario.');
        } catch (Throwable $exception) {
            return back()->withErrors($exception->getMessage());
        }
    }

    public function wastes(Request $request): View
    {
        $user = $request->user();
        $this->authorizeAny($user, [PermissionCode::InventoryWasteRegister, PermissionCode::InventoryWasteAnnul]);
        $wastes = DB::table('mermas as m')->join('articulos as a', 'a.id', '=', 'm.articulo_id')->select('m.*', 'a.nombre as articulo_nombre')->orderByDesc('m.ocurrido_en')->paginate(20)->withQueryString();
        return view('inventory.wastes.index', [...$this->pageData($user), 'wastes' => $wastes, 'canRegister' => $this->permissions->allows($user, PermissionCode::InventoryWasteRegister), 'canAnnul' => $this->permissions->allows($user, PermissionCode::InventoryWasteAnnul)]);
    }

    public function wasteCreate(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::InventoryWasteRegister);
        return view('inventory.wastes.create', [...$this->pageData($user), 'articles' => $this->activeArticles()]);
    }

    public function wasteStore(StoreWasteRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->wastes->record($request->user(), (int) $data['articulo_id'], $data['cantidad'], (string) Str::uuid(), $data['motivo'], $this->date($data['ocurrido_en'] ?? null));
            return redirect()->route('inventory.wastes')->with('success', 'Merma registrada con trazabilidad.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        }
    }

    private function activeArticles()
    {
        return Articulo::query()->with('unidad')->where('activo', true)->orderBy('nombre')->orderBy('id')->get(['id', 'sku', 'nombre', 'stock_actual', 'stock_minimo', 'unidad_id']);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        return $value === null || trim($value) === '' ? null : CarbonImmutable::parse($value, 'America/Santiago')->setTimezone('UTC');
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }

    private function authorizeAny(?User $user, array $permissions): void
    {
        abort_unless($user instanceof User, 401);
        abort_unless(collect($permissions)->contains(fn (PermissionCode $permission): bool => $this->permissions->allows($user, $permission)), 403);
    }

    /** @return array{user: User} */
    private function pageData(User $user): array
    {
        return ['user' => $user];
    }
}
