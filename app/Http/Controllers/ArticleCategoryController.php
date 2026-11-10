<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Articles\SaveArticleCategoryRequest;
use App\Models\CategoriaArticulo;
use App\Services\Articles\ArticleCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ArticleCategoryController extends Controller
{
    public function __construct(private readonly ArticleCatalogService $catalog) {}

    public function index(Request $request): View
    {
        $filters = ['q' => is_string($request->query('q')) ? trim($request->query('q')) : '',
            'activo' => in_array($request->query('activo'), ['activos', 'inactivos'], true) ? $request->query('activo') : 'todos'];

        return view('catalog.index', ['user' => $request->user(), 'kind' => 'categories', 'entries' => $this->catalog->categories($request->user(), $filters), 'filters' => $filters]);
    }

    public function create(Request $request): View
    {
        return view('catalog.form', ['user' => $request->user(), 'kind' => 'categories', 'entry' => null]);
    }

    public function store(SaveArticleCategoryRequest $request): RedirectResponse
    {
        $this->catalog->saveCategory($request->user(), $request->validated());

        return redirect()->route('catalog.categories.index')->with('success', 'Categoría creada correctamente.');
    }

    public function edit(Request $request, CategoriaArticulo $categoria): View
    {
        return view('catalog.form', ['user' => $request->user(), 'kind' => 'categories', 'entry' => $categoria]);
    }

    public function update(SaveArticleCategoryRequest $request, CategoriaArticulo $categoria): RedirectResponse
    {
        $this->catalog->saveCategory($request->user(), $request->validated(), $categoria);

        return redirect()->route('catalog.categories.index')->with('success', 'Categoría actualizada correctamente.');
    }

    public function activate(Request $request, CategoriaArticulo $categoria): RedirectResponse
    {
        $this->catalog->setActive($request->user(), $categoria, true);

        return back()->with('success', 'Categoría activada.');
    }

    public function deactivate(Request $request, CategoriaArticulo $categoria): RedirectResponse
    {
        $this->catalog->setActive($request->user(), $categoria, false);

        return back()->with('success', 'Categoría desactivada. Los artículos asociados conservan su referencia.');
    }
}
