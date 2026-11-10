@extends('layouts.authenticated', ['title' => 'Nueva venta | Almapan'])

@section('page')
    @php
        $hasCashSession = $cashSessions !== [];
        $cashSessionNotice = $cashSessionNotice ?? 'Debes abrir una sesión de caja vigente antes de cobrar.';
        $recentSales = $recentSales ?? null;
        $firstSessionId = $cashSessions[0]['id'] ?? null;
        $oldPayments = is_array($initialPayments) ? $initialPayments : [];
        $cashNavigation = collect(app(\App\Services\Navigation\NavigationBuilder::class)->forUser($user))
            ->flatMap(fn (array $group) => $group['items'])
            ->contains(fn (array $item) => $item['href'] === route('cash.index'));
    @endphp

    <section class="sales-page" id="sales-pos" aria-labelledby="sales-title"
        data-search-url="{{ route('sales.search') }}"
        data-store-url="{{ route('sales.store') }}"
        data-csrf-token="{{ csrf_token() }}"
        data-command-uuid="{{ $commandUuid }}"
        data-cash-session-notice="{{ $hasCashSession ? '' : $cashSessionNotice }}"
        data-initial-cart="{{ json_encode($initialCart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
        data-initial-lines="{{ json_encode($initialLines, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
        data-initial-payments="{{ json_encode($oldPayments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
        <div class="sales-page-header">
            <h1 id="sales-title">Nueva venta</h1>
            <a class="secondary-button inline-button" href="{{ route('inicio') }}">Volver al inicio</a>
        </div>

        @if ($errors->any())
            <div class="form-alert sales-alert" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if (! $hasCashSession)
            <div class="pos-cash-notice" role="status">
                <span>{{ $cashSessionNotice }}</span>
                @if ($cashNavigation)
                    <a href="{{ route('cash.index') }}">Ir a Caja</a>
                @endif
            </div>
        @endif

        <a class="pos-cart-shortcut" href="#cart-title" data-cart-shortcut>
            <span>{{ $hasCashSession ? 'Ver carrito y cobrar' : 'Ver carrito' }}</span><strong data-mobile-total>$0</strong>
        </a>

        <div class="sales-layout">
            <section class="sales-block product-panel" aria-labelledby="product-search-title">
                <div class="sales-block-heading">
                    <div>
                        <h2 id="product-search-title">Agregar productos</h2>
                    </div>
                </div>

                <form class="sales-search" data-search-form novalidate>
                    <label for="product-search">Buscar por nombre, SKU, ID o código de barras</label>
                    <div class="sales-search-row">
                        <input id="product-search" class="form-input" type="search" autocomplete="off" inputmode="search" placeholder="Buscar por nombre, SKU, ID o código de barras" data-search-input autofocus>
                        <button type="submit" class="primary-button sales-search-button">Buscar</button>
                    </div>
                </form>

                <div class="sales-feedback" data-search-feedback role="status" aria-live="polite"></div>
                <div class="product-results" data-product-results aria-live="polite"></div>

                <div
                    @if ($recentSales !== null) data-pos-recent-url="{{ route('sales.recent', $recentSales['session']) }}" @endif>
                    <section class="pos-recent" aria-labelledby="pos-recent-title">
                        <h2 id="pos-recent-title">Ventas recientes</h2>
                        @if ($recentSales !== null)
                            <ul class="pos-recent-list" aria-label="Ventas de esta jornada" tabindex="0" data-pos-recent-list>
                                @include('sales._recent-rows', ['rows' => $recentSales['rows']])
                            </ul>
                            <p class="pos-recent-status" role="status" data-pos-recent-status></p>
                        @else
                            <p class="pos-recent-empty">Caja principal necesita una sesión vigente para mostrar las ventas de la jornada.</p>
                        @endif
                    </section>

                    @if ($recentSales !== null)
                        <section class="pos-recent pos-collected-panel" aria-labelledby="pos-collected-title" data-pos-collected>
                            <h2 id="pos-collected-title">Resumen de cobros</h2>
                            <dl class="pos-collected">
                                <div>
                                    <dt>Total cobrado en efectivo</dt>
                                    <dd data-pos-collected-cash>{{ $recentSales['collected']['cash'] }}</dd>
                                </div>
                                <div>
                                    <dt>Total cobrado por medios digitales</dt>
                                    <dd data-pos-collected-digital>{{ $recentSales['collected']['digital'] }}</dd>
                                </div>
                            </dl>
                        </section>
                    @endif
                </div>
            </section>

            <section class="sales-block cart-panel" aria-labelledby="cart-title">
                <div class="sales-block-heading">
                    <div>
                        <h2 id="cart-title" tabindex="-1">Carrito</h2>
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

                <div class="cart-summary" role="status" aria-live="polite" aria-atomic="true">
                    <span>Total de la venta</span>
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
                            <h3>Pago <span class="pos-payment-mode" data-payment-mode></span></h3>
                            <button type="button" class="secondary-button compact-button" data-add-payment @disabled(! $hasCashSession)>Agregar pago</button>
                        </div>
                        <div class="payment-lines" data-payment-lines>
                            <div class="sales-empty sales-empty-small">Agrega el total de la venta a uno o más medios.</div>
                        </div>
                        <div class="payment-totals" aria-live="polite" aria-atomic="true">
                            <div class="sr-only"><span>Total</span><strong data-payment-total>$0</strong></div>
                            <div><span>Importe distribuido</span><strong data-paid-total>$0</strong></div>
                            <div><span>Por distribuir</span><strong data-payment-balance>$0</strong></div>
                        </div>
                    </div>

                    <p class="pos-checkout-status" data-checkout-help role="status" aria-live="polite">{{ $hasCashSession ? 'Agrega un producto para comenzar.' : $cashSessionNotice }}</p>
                    <button type="submit" class="primary-button checkout-button" data-checkout-button disabled>Completar venta</button>
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
