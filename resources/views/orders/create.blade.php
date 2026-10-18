@extends('layouts.authenticated', ['title' => 'Crear pedido | Almapan'])

@section('page')
    <section class="orders-page" data-order-create data-article-search-url="{{ route('orders.articles.search') }}" data-client-search-url="{{ route('orders.clients.search') }}">
        <header class="orders-page-header">
            <div>
                <p class="eyebrow">Pedidos</p>
                <h1>Crear pedido</h1>
                <p class="orders-intro">Agrega artículos vendibles y, si corresponde, registra un anticipo.</p>
            </div>
            <a class="secondary-button inline-button" href="{{ route('orders.index') }}">Volver a pedidos</a>
        </header>

        @if ($errors->any())
            <div class="form-alert" role="alert">
                <strong>Revisa los datos del pedido.</strong>
                <ul class="form-error-list">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form class="order-create-layout" method="POST" action="{{ route('orders.store') }}" data-order-form>
            @csrf
            <input type="hidden" name="command_uuid" value="{{ $commandUuid }}" data-order-command-uuid>
            <input type="hidden" name="client_id" value="{{ old('client_id', $initialClientId) }}" data-client-id>

            <section class="orders-panel order-builder-panel">
                <div class="orders-panel-heading">
                    <div>
                        <h2>Cliente</h2>
                        <p>El contrato actual del pedido requiere un cliente existente.</p>
                    </div>
                </div>
                <div class="client-picker" data-client-picker>
                    <label class="field-group">
                        <span>Buscar cliente</span>
                        <input class="form-input" type="search" data-client-search placeholder="Nombre o ID" autocomplete="off">
                    </label>
                    <div class="picker-results" data-client-results></div>
                    <p class="selected-reference" data-selected-client>
                        @php($selectedClient = collect($clients)->firstWhere('id', (int) ($initialClientId ?? 0)))
                        {{ $selectedClient ? 'Seleccionado: '.$selectedClient['nombre'] : 'Selecciona un cliente para continuar.' }}
                    </p>
                </div>

                <div class="orders-panel-heading order-products-heading">
                    <div>
                        <h2>Artículos</h2>
                        <p>El precio se vuelve a resolver en el servidor al guardar.</p>
                    </div>
                </div>
                <div class="order-search-row">
                    <input class="form-input" type="search" data-order-article-search-input placeholder="ID, SKU, nombre o código de barras" autocomplete="off">
                    <button class="secondary-button" type="button" data-order-article-search>Buscar</button>
                </div>
                <p class="field-help">También funciona con lector USB o Bluetooth que escriba el código y presione Enter.</p>
                <div class="picker-results article-results" data-order-article-results></div>
                <div class="order-cart-lines" data-order-cart-lines></div>
                <div data-order-line-inputs></div>
                <div class="empty-state empty-state-inline" data-order-cart-empty>
                    <strong>Aún no hay artículos.</strong>
                    <p>Busca un producto vendible para comenzar.</p>
                </div>
            </section>

            <aside class="orders-panel order-checkout-panel">
                <div class="orders-panel-heading">
                    <div>
                        <h2>Resumen</h2>
                        <p>La entrega descontará stock solamente cuando el pedido esté listo.</p>
                    </div>
                </div>
                <div class="order-total-line"><span>Total</span><strong data-order-total>$0</strong></div>

                <div class="order-form-section">
                    <h3>Anticipo opcional</h3>
                    <p class="field-help">Requiere el permiso <code>pedidos.cobrar</code> y una sesión de caja abierta.</p>
                    <label class="field-group">
                        <span>Sesión de caja</span>
                        <select class="form-input" name="cash_session_id" data-order-cash-session>
                            <option value="">Seleccionar al registrar un anticipo</option>
                            @foreach ($cashSessions as $session)
                                <option value="{{ $session['id'] }}">{{ $session['nombre'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="order-payment-lines" data-order-payment-lines></div>
                    <div data-order-payment-inputs></div>
                    <button class="secondary-button compact-button" type="button" data-add-order-payment>Agregar anticipo</button>
                </div>

                <div class="order-form-section order-schedule-section">
                    <h3>Datos adicionales</h3>
                    <label class="field-group">
                        <span>Entrega programada</span>
                        <input class="form-input" type="datetime-local" name="scheduled_delivery_at" value="{{ old('scheduled_delivery_at') }}">
                    </label>
                    <label class="field-group">
                        <span>Observación del pedido</span>
                        <textarea class="form-input" name="observation" rows="3" maxlength="2000">{{ old('observation') }}</textarea>
                    </label>
                </div>

                <button class="primary-button order-submit-button" type="submit" data-order-submit>Crear pedido</button>
                <p class="field-help">El servidor validará cliente, artículos, precios, permisos, pagos e idempotencia.</p>
            </aside>
        </form>
    </section>

    <script>
        window.panGestionOrderArticles = @json($initialCart);
        window.panGestionOrderLines = @json($initialLines);
        window.panGestionOrderPayments = @json($initialPayments);
        window.panGestionOrderPaymentMethods = @json($paymentMethods);
    </script>
@endsection
