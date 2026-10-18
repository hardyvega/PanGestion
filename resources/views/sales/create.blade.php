@extends('layouts.authenticated', ['title' => 'Nueva venta | Almapan'])

@section('page')
    @php
        $hasCashSession = $cashSessions !== [];
        $firstSessionId = $cashSessions[0]['id'] ?? null;
        $oldPayments = is_array($initialPayments) ? $initialPayments : [];
    @endphp

    <section class="sales-page" id="sales-pos" aria-labelledby="sales-title"
        data-search-url="{{ route('sales.search') }}"
        data-store-url="{{ route('sales.store') }}"
        data-csrf-token="{{ csrf_token() }}"
        data-command-uuid="{{ $commandUuid }}"
        data-initial-cart="{{ json_encode($initialCart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
        data-initial-lines="{{ json_encode($initialLines, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
        data-initial-payments="{{ json_encode($oldPayments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
        <div class="sales-page-header">
            <div>
                <p class="eyebrow">Ventas</p>
                <h1 id="sales-title">Punto de venta</h1>
                <p class="sales-intro">Busca, agrega y cobra con rapidez desde una sola pantalla.</p>
            </div>
            <a class="secondary-button inline-button" href="{{ route('inicio') }}">Volver al inicio</a>
        </div>

        @if ($errors->has('checkout'))
            <div class="form-alert sales-alert" role="alert">{{ $errors->first('checkout') }}</div>
        @endif

        @if (! $hasCashSession)
            <section class="sales-block sales-block-warning" aria-labelledby="cash-session-title">
                <div class="sales-warning-icon" aria-hidden="true">!</div>
                <div>
                    <h2 id="cash-session-title">No hay una sesión de caja disponible</h2>
                    <p>No hay una sesión de caja disponible para realizar ventas.</p>
                    <p class="sales-muted">Abre una caja desde el módulo correspondiente antes de cobrar.</p>
                </div>
            </section>
        @endif

        <div class="sales-layout{{ $hasCashSession ? '' : ' is-disabled' }}">
            <section class="sales-block product-panel" aria-labelledby="product-search-title">
                <div class="sales-block-heading">
                    <div>
                        <p class="eyebrow">Catálogo</p>
                        <h2 id="product-search-title">Agregar productos</h2>
                    </div>
                    <span class="sales-hint">Enter agrega coincidencias exactas</span>
                </div>

                <form class="sales-search" data-search-form novalidate>
                    <label for="product-search">Buscar por nombre, SKU, ID o código de barras</label>
                    <div class="sales-search-row">
                        <input id="product-search" class="form-input" type="search" autocomplete="off" inputmode="search" placeholder="Ej. marraqueta o 7801234567890" data-search-input @disabled(! $hasCashSession)>
                        <button type="submit" class="primary-button sales-search-button" @disabled(! $hasCashSession)>Buscar</button>
                    </div>
                    <p class="sales-field-help">También funciona con un lector USB o Bluetooth como teclado.</p>
                </form>

                <div class="sales-feedback" data-search-feedback role="status" aria-live="polite"></div>
                <div class="product-results" data-product-results aria-live="polite"></div>
            </section>

            <section class="sales-block cart-panel" aria-labelledby="cart-title">
                <div class="sales-block-heading">
                    <div>
                        <p class="eyebrow">Carrito</p>
                        <h2 id="cart-title">Resumen de venta</h2>
                    </div>
                    <span class="cart-count" data-cart-count>0 productos</span>
                </div>

                <div class="cart-lines" data-cart-lines>
                    <div class="sales-empty" data-cart-empty>
                        <span class="sales-empty-icon" aria-hidden="true">+</span>
                        <strong>Tu carrito está vacío</strong>
                        <span>Busca un producto para comenzar.</span>
                    </div>
                </div>

                <div class="cart-summary">
                    <span>Total</span>
                    <strong data-cart-total>$0</strong>
                </div>

                <form method="POST" action="{{ route('sales.store') }}" class="checkout-form" data-checkout-form novalidate>
                    @csrf
                    <input type="hidden" name="command_uuid" value="{{ $commandUuid }}" data-command-uuid-input>
                    <input type="hidden" name="cash_session_id" value="{{ $firstSessionId ?? '' }}" data-cash-session-input>
                    <div data-line-inputs></div>
                    <div data-payment-inputs></div>

                    <div class="payment-section">
                        <div class="payment-heading">
                            <h3>Medios de pago</h3>
                            <button type="button" class="secondary-button compact-button" data-add-payment @disabled(! $hasCashSession)>Agregar pago</button>
                        </div>
                        <div class="payment-lines" data-payment-lines>
                            <div class="sales-empty sales-empty-small">Agrega el total de la venta a uno o más medios.</div>
                        </div>
                        <div class="payment-totals" aria-live="polite">
                            <div><span>Total</span><strong data-payment-total>$0</strong></div>
                            <div><span>Pagado</span><strong data-paid-total>$0</strong></div>
                            <div><span>Saldo</span><strong data-payment-balance>$0</strong></div>
                        </div>
                    </div>

                    <button type="submit" class="primary-button checkout-button" data-checkout-button @disabled(! $hasCashSession)>Cobrar venta</button>
                    <p class="sales-field-help" data-checkout-help>El servidor volverá a validar precios, stock, permisos y caja antes de confirmar.</p>
                </form>
            </section>
        </div>

        <noscript>
            <p class="form-alert">Para usar el punto de venta necesitas activar JavaScript.</p>
        </noscript>
    </section>

    <script>
        window.panGestionPaymentMethods = @json($paymentMethods);
        window.panGestionCashSessions = @json($cashSessions);
    </script>
@endsection
