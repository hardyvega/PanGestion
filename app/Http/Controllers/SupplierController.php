<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Http\Requests\Suppliers\AttachArticleSupplierRequest;
use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Suppliers\SupplierAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SupplierController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly SupplierAdministrationService $suppliers,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::SuppliersView);
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'activo' => in_array($request->query('activo', 'todos'), ['todos', 'activos', 'inactivos'], true)
                ? $request->query('activo', 'todos') : 'todos',
        ];

        return view('suppliers.index', [
            'user' => $user,
            'suppliers' => $this->suppliers->list($user, $filters),
            'canManage' => $this->permissions->allows($user, PermissionCode::SuppliersManage),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::SuppliersManage);

        return view('suppliers.create', [
            'user' => $user,
            'supplier' => null,
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($request->user(), $request->validated());

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor creado correctamente.');
    }

    public function show(Request $request, Proveedor $proveedor): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::SuppliersView);
        $canViewCosts = $this->permissions->allows($user, PermissionCode::InventoryCostsView);

        $availableArticles = Articulo::query()
            ->where('activo', true)
            ->whereNotExists(function ($query) use ($proveedor): void {
                $query->selectRaw('1')
                    ->from('articulo_proveedor')
                    ->whereColumn('articulo_proveedor.articulo_id', 'articulos.id')
                    ->where('articulo_proveedor.proveedor_id', $proveedor->getKey());
            })
            ->orderBy('nombre')->orderBy('id')
            ->get(['id', 'sku', 'nombre']);

        return view('suppliers.show', [
            'user' => $user,
            'supplier' => $proveedor,
            'articles' => $this->suppliers->supplierArticles($user, $proveedor),
            'costs' => $canViewCosts ? $this->suppliers->supplierCosts($user, $proveedor) : null,
            'availableArticles' => $availableArticles,
            'canManage' => $this->permissions->allows($user, PermissionCode::SuppliersManage),
            'canViewCosts' => $canViewCosts,
        ]);
    }

    public function edit(Request $request, Proveedor $proveedor): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::SuppliersManage);

        return view('suppliers.edit', [
            'user' => $user,
            'supplier' => $proveedor,
        ]);
    }

    public function update(UpdateSupplierRequest $request, Proveedor $proveedor): RedirectResponse
    {
        $supplier = $this->suppliers->update($request->user(), $proveedor, $request->validated());

        return redirect()->route('suppliers.show', $supplier)->with('success', 'Proveedor actualizado correctamente.');
    }

    public function activate(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::SuppliersManage);
        $this->suppliers->setActive($request->user(), $proveedor, true);

        return back()->with('success', 'Proveedor activado.');
    }

    public function deactivate(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::SuppliersManage);
        $this->suppliers->setActive($request->user(), $proveedor, false);

        return back()->with('success', 'Proveedor desactivado.');
    }

    public function attach(AttachArticleSupplierRequest $request, Proveedor $proveedor): RedirectResponse
    {
        $data = $request->validated();
        $this->suppliers->attachArticle(
            $request->user(),
            $proveedor,
            (int) $data['articulo_id'],
            $data['codigo_articulo_proveedor'] ?? null,
        );

        return back()->with('success', 'Artículo asociado al proveedor.');
    }

    public function detach(Request $request, Proveedor $proveedor, Articulo $articulo): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::SuppliersManage);
        $this->suppliers->detachArticle($request->user(), $proveedor, $articulo);

        return back()->with('success', 'Artículo desasociado sin alterar su historial.');
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }


}
