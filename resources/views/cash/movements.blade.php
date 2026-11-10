@extends('layouts.authenticated', ['title' => 'Movimientos de caja | Almapan'])

@section('page')
    <section class="cash-page" aria-labelledby="cash-movements-title">
        <header class="cash-page-header">
            <div><p class="eyebrow">Caja</p><h1 id="cash-movements-title">Movimientos de caja</h1><p class="cash-intro">Historial append-only con origen y causalidad visibles.</p></div>
            <a class="secondary-button inline-button" href="{{ route('cash.index') }}">Volver a caja</a>
        </header>
        <section class="cash-panel">
            <form class="cash-filter-grid" method="GET" action="{{ route('cash.movements') }}">
                <label class="field-group"><span>Desde</span><input class="form-input" type="date" name="fecha_desde" value="{{ $filters['fecha_desde'] ?? '' }}"></label>
                <label class="field-group"><span>Hasta</span><input class="form-input" type="date" name="fecha_hasta" value="{{ $filters['fecha_hasta'] ?? '' }}"></label>
                <label class="field-group"><span>Tipo</span><select class="form-input" name="tipo"><option value="">Todos</option>@foreach (['ingreso' => 'Ingreso', 'egreso' => 'Egreso', 'retiro' => 'Retiro'] as $value => $label)<option value="{{ $value }}" @selected(($filters['tipo'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
                <label class="field-group"><span>Origen</span><select class="form-input" name="origen"><option value="">Todos</option>@foreach (['manual' => 'Manual', 'venta' => 'Venta', 'pedido' => 'Pedido', 'devolucion' => 'Devolución', 'cierre' => 'Cierre definitivo'] as $value => $label)<option value="{{ $value }}" @selected(($filters['origen'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
                <label class="field-group"><span>Caja</span><select class="form-input" name="caja_id"><option value="">Todas</option>@foreach ($registers as $register)<option value="{{ $register->id }}" @selected((string) ($filters['caja_id'] ?? '') === (string) $register->id)>{{ $register->nombre }}</option>@endforeach</select></label>
                <label class="field-group"><span>Usuario</span><select class="form-input" name="usuario_id"><option value="">Todos</option>@foreach ($users as $filterUser)<option value="{{ $filterUser->id }}" @selected((string) ($filters['usuario_id'] ?? '') === (string) $filterUser->id)>{{ $filterUser->nombre }} {{ $filterUser->apellido }}</option>@endforeach</select></label>
                <button class="primary-button cash-filter-button" type="submit">Filtrar</button>
            </form>
        </section>
        <section class="cash-panel">
            @if ($movements->isEmpty())
                <p class="empty-state">No hay movimientos para los filtros seleccionados.</p>
            @else
                <div class="cash-table-wrap"><table class="cash-table"><thead><tr><th>Fecha</th><th>Caja</th><th>Tipo</th><th>Origen</th><th>Usuario</th><th>Concepto</th><th class="cash-number">Monto</th></tr></thead><tbody>
                    @foreach ($movements as $movement)
                        <tr><td data-label="Fecha">{{ \Carbon\CarbonImmutable::parse($movement['occurred_at'])->timezone('America/Santiago')->format('d/m/Y H:i') }}</td><td data-label="Caja">{{ $movement['cash_register_name'] }}</td><td data-label="Tipo">{{ ucfirst($movement['type']) }}</td><td data-label="Origen">{{ $movement['is_refund'] ? 'Devolución · '.$movement['source_label'] : $movement['source_label'] }}</td><td data-label="Usuario">{{ $movement['user_name'] }}</td><td data-label="Concepto">{{ $movement['concept'] }}</td><td data-label="Monto" class="cash-number {{ in_array($movement['type'], ['egreso', 'retiro'], true) ? 'amount-negative' : '' }}">{{ \App\Support\Money::clp($movement['amount']) }}</td></tr>
                    @endforeach
                </tbody></table></div>
                <div class="cash-pagination">{{ $movements->links() }}</div>
            @endif
        </section>
    </section>
@endsection
