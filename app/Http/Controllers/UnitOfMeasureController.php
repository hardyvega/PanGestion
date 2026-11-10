<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Articles\SaveUnitOfMeasureRequest;
use App\Models\UnidadMedida;
use App\Services\Articles\ArticleCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UnitOfMeasureController extends Controller
{
    public function __construct(private readonly ArticleCatalogService $catalog) {}

    public function index(Request $request): View
    {
        $filters = ['q' => is_string($request->query('q')) ? trim($request->query('q')) : '',
            'activo' => in_array($request->query('activo'), ['activos', 'inactivos'], true) ? $request->query('activo') : 'todos'];

        return view('catalog.index', ['user' => $request->user(), 'kind' => 'units', 'entries' => $this->catalog->units($request->user(), $filters), 'filters' => $filters]);
    }

    public function create(Request $request): View
    {
        return view('catalog.form', ['user' => $request->user(), 'kind' => 'units', 'entry' => null]);
    }

    public function store(SaveUnitOfMeasureRequest $request): RedirectResponse
    {
        $this->catalog->saveUnit($request->user(), $request->validated());

        return redirect()->route('catalog.units.index')->with('success', 'Unidad de medida creada correctamente.');
    }

    public function edit(Request $request, UnidadMedida $unidad): View
    {
        return view('catalog.form', ['user' => $request->user(), 'kind' => 'units', 'entry' => $unidad, 'unitInUse' => $unidad->articulos()->exists()]);
    }

    public function update(SaveUnitOfMeasureRequest $request, UnidadMedida $unidad): RedirectResponse
    {
        $this->catalog->saveUnit($request->user(), $request->validated(), $unidad);

        return redirect()->route('catalog.units.index')->with('success', 'Unidad de medida actualizada correctamente.');
    }

    public function activate(Request $request, UnidadMedida $unidad): RedirectResponse
    {
        $this->catalog->setActive($request->user(), $unidad, true);

        return back()->with('success', 'Unidad de medida activada.');
    }

    public function deactivate(Request $request, UnidadMedida $unidad): RedirectResponse
    {
        $this->catalog->setActive($request->user(), $unidad, false);

        return back()->with('success', 'Unidad desactivada. Los artículos asociados conservan su referencia.');
    }
}
