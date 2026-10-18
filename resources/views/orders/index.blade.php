@extends('layouts.authenticated', ['title' => 'Pedidos | Almapan'])

@section('page')
    <section class="orders-page" data-orders-page>
        <header class="orders-page-header">
            <div>
                <p class="eyebrow">Operación</p>
                <h1>Pedidos</h1>
                <p class="orders-intro">Seguimiento de pedidos desde la creación hasta la entrega.</p>
            </div>
            @if ($canCreate)
                <a class="primary-button inline-button" href="{{ route('orders.create') }}">Crear pedido</a>
            @endif
        </header>

        @if (session('success'))
            <div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->has('order'))
            <div class="form-alert" role="alert">{{ $errors->first('order') }}</div>
        @endif

        <div class="order-summary-grid" aria-label="Resumen de estados">
            @foreach ([
                'pendiente' => 'Pendientes',
                'confirmado' => 'Confirmados',
                'en_preparacion' => 'En preparación',
                'listo' => 'Listos',
                'entregado' => 'Entregados',
                'cancelado' => 'Cancelados',
            ] as $key => $label)
                <a class="order-summary-card order-status-{{ $key }}" href="{{ route('orders.index', array_merge(request()->query(), ['status' => $key])) }}">
                    <span>{{ $label }}</span>
                    <strong>{{ $summary[$key] ?? 0 }}</strong>
                </a>
            @endforeach
        </div>

        <section class="orders-panel">
            <form class="orders-filters" method="GET" action="{{ route('orders.index') }}">
                <div class="orders-filters-heading">
                    <h2>Buscar y filtrar</h2>
                    <a class="text-button" href="{{ route('orders.index') }}">Limpiar</a>
                </div>
                <div class="orders-filter-grid">
                    <label class="field-group">
                        <span>Estado</span>
                        <select class="form-input" name="status">
                            <option value="">Todos</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ ucfirst(str_replace('_', ' ', $status->value)) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field-group">
                        <span>Desde</span>
                        <input class="form-input" type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                    </label>
                    <label class="field-group">
                        <span>Hasta</span>
                        <input class="form-input" type="date" name="date_until" value="{{ $filters['date_until'] ?? '' }}">
                    </label>
                    <label class="field-group orders-filter-search">
                        <span>Pedido, cliente o artículo</span>
                        <input class="form-input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="ID, nombre o SKU">
                    </label>
                    <button class="secondary-button filter-submit" type="submit">Aplicar filtros</button>
                </div>
            </form>

            @if ($orders->isEmpty())
                <div class="empty-state empty-state-inline">
                    <strong>{{ request()->hasAny(['status', 'date_from', 'date_until', 'q']) ? 'No se encontraron pedidos con estos filtros.' : 'No hay pedidos registrados.' }}</strong>
                    <p>Los pedidos nuevos aparecerán aquí con su estado y trazabilidad.</p>
                </div>
            @else
                <div class="orders-list" aria-label="Listado de pedidos">
                    @foreach ($orders as $order)
                        @php($summaryData = $orderSummaries[$order->getKey()] ?? ['total' => '0.00', 'paid' => '0.00', 'balance' => '0.00'])
                        <a class="order-card" href="{{ route('orders.show', $order) }}">
                            <div class="order-card-main">
                                <div class="order-card-titleline">
                                    <strong>Pedido #{{ $order->getKey() }}</strong>
                                    <span class="order-status-badge order-status-{{ $order->estado->value }}">{{ ucfirst(str_replace('_', ' ', $order->estado->value)) }}</span>
                                </div>
                                <span class="order-card-client">{{ $order->cliente?->nombre ?? 'Cliente no disponible' }}</span>
                                <small>{{ $order->created_at?->format('d/m/Y H:i') }} · {{ $order->detalles->count() }} línea(s)</small>
                            </div>
                            <div class="order-card-finance">
                                <span>Total</span>
                                <strong>{{ \App\Support\Money::clp((string) $summaryData['total']) }}</strong>
                                @if ($summaryData['balance'] !== '0.00')
                                    <small>Saldo {{ \App\Support\Money::clp((string) $summaryData['balance']) }}</small>
                                @else
                                    <small class="paid-note">Pagado</small>
                                @endif
                            </div>
                            <span class="order-card-arrow" aria-hidden="true">→</span>
                        </a>
                    @endforeach
                </div>
                <div class="orders-pagination">{{ $orders->links() }}</div>
            @endif
        </section>
    </section>
@endsection
