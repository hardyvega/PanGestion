<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Enums\ReceiptOriginType;
use App\Http\Requests\Inventory\StoreReceiptLineRequest;
use App\Http\Requests\Inventory\StoreReceiptRequest;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\RecepcionMercaderia;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\ReceiptService;
use App\Support\UserFacingError;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

final class ReceiptController extends Controller
{
    public function __construct(private readonly EffectivePermissionResolver $permissions, private readonly ReceiptService $receipts)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::InventoryReceiptsView);
        $query = DB::table('recepciones_mercaderia as r')->leftJoin('proveedores as p', 'p.id', '=', 'r.proveedor_id')->select('r.*', 'p.nombre as proveedor_nombre');
        if ($request->filled('estado') && in_array($request->query('estado'), ['borrador', 'confirmada', 'anulada'], true)) $query->where('r.estado', $request->query('estado'));
        if ($request->filled('q') && is_numeric($request->query('q'))) $query->where('r.id', (int) $request->query('q'));
        $receipts = $query->orderByDesc('r.created_at')->orderByDesc('r.id')->paginate(15)->withQueryString();
        return view('receipts.index', [...$this->pageData($user), 'receipts' => $receipts, 'canCreate' => $this->permissions->allows($user, PermissionCode::InventoryReceiptsCreate), 'filters' => ['q' => $request->query('q', ''), 'estado' => $request->query('estado', 'todos')]]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::InventoryReceiptsCreate);
        return view('receipts.create', [...$this->pageData($user), 'suppliers' => Proveedor::query()->where('activo', true)->orderBy('nombre')->get(), 'contributors' => User::query()->where('activo', true)->orderBy('nombre')->orderBy('apellido')->get(['id', 'nombre', 'apellido']), 'origins' => ReceiptOriginType::cases()]);
    }

    public function store(StoreReceiptRequest $request): RedirectResponse
    {
        $data = $request->validated();
        try {
            $receipt = $this->receipts->createDraft($request->user(), (string) Str::uuid(), ReceiptOriginType::from($data['tipo_origen']), $data['proveedor_id'] ?? null, $data['usuario_aportante_id'] ?? null, $data['origen_detalle'] ?? null, $request->commercialInstant('ocurrido_en'), $data['motivo_ajuste_ocurrido_en'] ?? null, $data['observacion'] ?? null);
            return redirect()->route('receipts.show', $receipt)->with('success', 'Borrador de recepción creado.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(UserFacingError::message($exception));
        }
    }

    public function show(Request $request, RecepcionMercaderia $recepcion): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::InventoryReceiptsView);
        $lines = $recepcion->detalles()->orderBy('id')->get();
        $articles = Articulo::query()->with('unidad')->where('activo', true)->orderBy('nombre')->get(['id', 'sku', 'nombre', 'unidad_id']);
        return view('receipts.show', [...$this->pageData($user), 'receipt' => $recepcion->load('detalles'), 'lines' => $lines, 'articles' => $articles, 'canEdit' => $this->permissions->allows($user, PermissionCode::InventoryReceiptsEdit), 'canConfirm' => $this->permissions->allows($user, PermissionCode::InventoryReceiptsConfirm) && $this->permissions->allows($user, PermissionCode::InventoryCostsRegister), 'canAnnul' => $this->permissions->allows($user, PermissionCode::InventoryReceiptsAnnul), 'confirmationUuid' => (string) Str::uuid(), 'origins' => ReceiptOriginType::cases()]);
    }

    public function addLine(StoreReceiptLineRequest $request, RecepcionMercaderia $recepcion): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->receipts->addLine($request->user(), (int) $recepcion->getKey(), (int) $data['articulo_id'], $data['cantidad'], $data['costo_unitario'] ?? null, $data['motivo_costo_cero'] ?? null);
            return back()->with('success', 'Línea agregada al borrador.');
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(UserFacingError::message($exception));
        }
    }

    public function removeLine(Request $request, RecepcionMercaderia $recepcion, int $detalle): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::InventoryReceiptsEdit);
        try {
            $this->receipts->removeLine($request->user(), (int) $recepcion->getKey(), $detalle);
            return back()->with('success', 'Línea retirada del borrador.');
        } catch (Throwable $exception) {
            return back()->withErrors(UserFacingError::message($exception));
        }
    }

    public function confirm(Request $request, RecepcionMercaderia $recepcion): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::InventoryReceiptsConfirm);
        $this->authorize($request->user(), PermissionCode::InventoryCostsRegister);
        try {
            $this->receipts->confirm($request->user(), (int) $recepcion->getKey(), (string) ($request->input('command_uuid') ?: Str::uuid()));
            return back()->with('success', 'Recepción confirmada: stock, ledger y costo actualizado por ReceiptService.');
        } catch (Throwable $exception) {
            return back()->withErrors(UserFacingError::message($exception));
        }
    }

    public function annul(Request $request, RecepcionMercaderia $recepcion): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::InventoryReceiptsAnnul);
        $motivo = trim((string) $request->input('motivo_anulacion'));
        if ($motivo === '') return back()->withErrors('La anulación requiere un motivo.');
        try {
            $this->receipts->annul($request->user(), (int) $recepcion->getKey(), (string) ($request->input('command_uuid') ?: Str::uuid()), $motivo);
            return back()->with('success', 'Recepción anulada mediante movimiento compensatorio.');
        } catch (Throwable $exception) {
            return back()->withErrors(UserFacingError::message($exception));
        }
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }

    /** @return array{user: User} */
    private function pageData(User $user): array
    {
        return ['user' => $user];
    }
}
