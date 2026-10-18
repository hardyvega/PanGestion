<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PermissionCode;
use App\Http\Requests\Recipes\StoreRecipeRequest;
use App\Models\Receta;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Recipes\RecipeAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class RecipeController extends Controller
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly RecipeAdministrationService $recipes,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::RecipesView);
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'estado' => in_array($request->query('estado', 'todas'), ['todas', 'activas', 'inactivas'], true)
                ? $request->query('estado', 'todas') : 'todas',
        ];

        return view('recipes.index', [
            'user' => $user,
            'recipes' => $this->recipes->list($user, $filters),
            'canManage' => $this->permissions->allows($user, PermissionCode::RecipesManage),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::RecipesManage);

        return view('recipes.create', [
            'user' => $user,
            'products' => $this->recipes->productOptions($user),
            'components' => $this->recipes->componentOptions($user),
            'previous' => null,
        ]);
    }

    public function store(StoreRecipeRequest $request): RedirectResponse
    {
        $recipe = $this->recipes->create($request->user(), $request->validated());

        return redirect()->route('recipes.show', $recipe)->with('success', 'Receta creada como nueva versión.');
    }

    public function show(Request $request, Receta $receta): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::RecipesView);
        $recipe = $this->recipes->show($user, $receta);

        return view('recipes.show', [
            'user' => $user,
            'recipe' => $recipe,
            'canManage' => $this->permissions->allows($user, PermissionCode::RecipesManage),
            'canViewProduction' => $this->permissions->allows($user, PermissionCode::ProductionView),
        ]);
    }

    public function createVersion(Request $request, Receta $receta): View
    {
        $user = $request->user();
        $this->authorize($user, PermissionCode::RecipesManage);

        return view('recipes.create', [
            'user' => $user,
            'products' => $this->recipes->productOptions($user),
            'components' => $this->recipes->componentOptions($user),
            'previous' => $receta->load(['articulo', 'detalles.componente']),
        ]);
    }

    public function storeVersion(StoreRecipeRequest $request, Receta $receta): RedirectResponse
    {
        $recipe = $this->recipes->createVersion($request->user(), $receta, $request->validated());

        return redirect()->route('recipes.show', $recipe)->with('success', 'Nueva versión de receta creada.');
    }

    public function activate(Request $request, Receta $receta): RedirectResponse
    {
        $this->authorize($request->user(), PermissionCode::RecipesManage);
        $this->recipes->activate($request->user(), $receta);

        return back()->with('success', 'La receta quedó vigente.');
    }

    private function authorize(?User $user, PermissionCode $permission): void
    {
        abort_unless($user instanceof User, 401);
        $this->permissions->authorize($user, $permission);
    }


}
