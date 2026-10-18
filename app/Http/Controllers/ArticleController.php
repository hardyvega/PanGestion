<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Http\Requests\Articles\StoreArticleRequest;
use App\Http\Requests\Articles\UpdateArticlePriceRequest;
use App\Http\Requests\Articles\UpdateArticleRequest;
use App\Models\Articulo;
use App\Models\CategoriaArticulo;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Articles\ArticleAdministrationService;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Suppliers\SupplierAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class ArticleController extends Controller
{
    private const TYPES = [
        'elaborado' => 'Elaborado',
        'reventa' => 'Reventa',
        'materia_prima' => 'Materia prima',
        'insumo_operacional' => 'Insumo operacional',
    ];

    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly ArticleAdministrationService $articles,
        private readonly SupplierAdministrationService $suppliers,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ArticlesView);

        $query = Articulo::query()->with(['categoria', 'unidad']);
        $term = trim((string) $request->query('q', ''));
        $active = $request->query('activo', 'todos');

        if ($term !== '') {
            $query->where(function ($builder) use ($term): void {
                $builder->where('nombre', 'ilike', '%'.addcslashes($term, '\\%_').'%')
                    ->orWhere('sku', 'ilike', '%'.addcslashes($term, '\\%_').'%')
                    ->orWhere('codigo_barras', 'ilike', '%'.addcslashes($term, '\\%_').'%');
                if (ctype_digit($term)) {
                    $builder->orWhere('id', (int) $term);
                }
            });
        }

        if ($active === 'activos') {
            $query->where('activo', true);
        } elseif ($active === 'inactivos') {
            $query->where('activo', false);
        }

        if (is_string($request->query('tipo')) && array_key_exists($request->query('tipo'), self::TYPES)) {
            $query->where('tipo_articulo', $request->query('tipo'));
        }

        if (is_numeric($request->query('categoria')) && (int) $request->query('categoria') > 0) {
            $query->where('categoria_articulo_id', (int) $request->query('categoria'));
        }

        if ($request->boolean('stock_bajo')) {
            $query->whereColumn('stock_actual', '<=', 'stock_minimo');
        }

        $articles = $query->orderBy('nombre')->orderBy('id')->paginate(15)->withQueryString();

        return view('articles.index', [
            'user' => $user,
            'articles' => $articles,
            'canManage' => $this->permissions->allows($user, PermissionCode::ArticlesManage),
            'categories' => $this->categories(),
            'types' => self::TYPES,
            'filters' => [
                'q' => $term,
                'activo' => $active,
                'tipo' => $request->query('tipo', ''),
                'categoria' => $request->query('categoria', ''),
                'stock_bajo' => $request->boolean('stock_bajo'),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ArticlesManage);

        return view('articles.create', $this->formData($user));
    }

    public function store(StoreArticleRequest $request): RedirectResponse
    {
        $article = $this->articles->create($request->user(), $request->validated());

        return redirect()->route('articles.show', $article)->with('success', 'Artículo creado correctamente.');
    }

    public function show(Request $request, Articulo $articulo): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ArticlesView);
        $articulo->load(['categoria', 'unidad', 'historialPrecios.usuario']);
        $canViewSuppliers = $this->permissions->allows($user, PermissionCode::SuppliersView);
        $canViewCosts = $this->permissions->allows($user, PermissionCode::InventoryCostsView);

        return view('articles.show', [
            'user' => $user,
            'article' => $articulo,
            'imageUrl' => $articulo->imagen_ruta === null ? null : Storage::disk('public')->url($articulo->imagen_ruta),
            'canManage' => $this->permissions->allows($user, PermissionCode::ArticlesManage),
            'articleSuppliers' => $canViewSuppliers ? $this->suppliers->articleSuppliers($user, $articulo) : collect(),
            'articleCosts' => $canViewCosts ? $this->suppliers->articleCosts($user, $articulo) : null,
            'currentCost' => $canViewCosts ? $this->suppliers->currentCost($user, $articulo) : null,
            'canViewSuppliers' => $canViewSuppliers,
            'canViewCosts' => $canViewCosts,
        ]);
    }

    public function edit(Request $request, Articulo $articulo): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::ArticlesManage);

        return view('articles.edit', [
            ...$this->formData($user),
            'article' => $articulo->load(['categoria', 'unidad']),
            'imageUrl' => $articulo->imagen_ruta === null ? null : Storage::disk('public')->url($articulo->imagen_ruta),
        ]);
    }

    public function update(UpdateArticleRequest $request, Articulo $articulo): RedirectResponse
    {
        $article = $this->articles->update($request->user(), $articulo, $request->validated());

        return redirect()->route('articles.show', $article)->with('success', 'Artículo actualizado correctamente.');
    }

    public function activate(Request $request, Articulo $articulo): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::ArticlesManage);
        $this->articles->setActive($request->user(), $articulo, true);

        return back()->with('success', 'Artículo activado.');
    }

    public function deactivate(Request $request, Articulo $articulo): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::ArticlesManage);
        $this->articles->setActive($request->user(), $articulo, false);

        return back()->with('success', 'Artículo desactivado.');
    }

    public function updatePrice(UpdateArticlePriceRequest $request, Articulo $articulo): RedirectResponse
    {
        $article = $this->articles->updatePrice($request->user(), $articulo, $request->validated());

        return redirect()->route('articles.show', $article)->with('success', 'Precio vigente actualizado.');
    }

    /** @return array<string, mixed> */
    private function formData(User $user): array
    {
        return [
            'user' => $user,
            'categories' => $this->categories(),
            'units' => UnidadMedida::query()->where('activo', true)->orderBy('nombre')->orderBy('id')->get(),
            'types' => self::TYPES,
        ];
    }

    private function categories()
    {
        return CategoriaArticulo::query()->where('activo', true)->orderBy('nombre')->orderBy('id')->get();
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }


}
