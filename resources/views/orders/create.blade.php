@extends('layouts.authenticated', ['title' => 'Crear pedido | Almapan'])

@section('page')
    @php($previous = static fn (string $key): string => is_string(old($key)) || is_int(old($key)) ? (string) old($key) : '')
    <section class="orders-page" data-order-create>
        <header class="orders-page-header">
            <div>
                <p class="eyebrow">Pedidos</p>
                <h1>Crear pedido</h1>
            </div>
            <a class="secondary-button inline-button" href="{{ route('orders.index') }}">Volver a pedidos</a>
        </header>

        @if ($errors->any())
            <div class="form-alert" role="alert">
                <strong>Revisa los datos del pedido.</strong>
                <ul class="form-error-list">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
                <p>Vuelve a ingresar el nombre y el teléfono para continuar.</p>
            </div>
        @endif

        <form class="order-create-layout" method="POST" action="{{ route('orders.store') }}" data-order-form>
            @csrf
            <input type="hidden" name="command_uuid" value="{{ $commandUuid }}">

            <div class="order-detail-main">
                <section class="orders-panel order-builder-panel" aria-labelledby="order-contact-title">
                    <div class="orders-panel-heading"><h2 id="order-contact-title">Datos de contacto</h2></div>
                    <div class="order-contact-fields">
                        <label class="field-group">
                            <span>Nombre del cliente</span>
                            <input class="form-input" type="text" name="contact_name" maxlength="150" required>
                        </label>
                        <label class="field-group">
                            <span>Teléfono de contacto</span>
                            <input class="form-input" type="tel" name="contact_phone" maxlength="50" placeholder="+56 9 1234 5678" required>
                        </label>
                    </div>
                </section>

                <section class="orders-panel order-builder-panel" aria-labelledby="order-description-title">
                    <div class="orders-panel-heading"><h2 id="order-description-title">Detalles del pedido</h2></div>
                    <label class="field-group">
                        <span>Detalle del pedido</span>
                        <textarea class="form-input" name="description" rows="4" maxlength="2000" placeholder="Ejemplo: 1 torta de chocolate para 20 personas, 2 kuchen y 1 pie de limón" required>{{ $previous('description') }}</textarea>
                    </label>
                    <label class="field-group">
                        <span>Especificaciones del pedido <small>(opcional)</small></span>
                        <textarea class="form-input" name="specifications" rows="3" maxlength="2000" placeholder="Ejemplo: escribir Feliz cumpleaños Martín. Sin nueces. Entregar fría.">{{ $previous('specifications') }}</textarea>
                    </label>
                </section>
            </div>

            <section class="orders-panel order-checkout-panel" aria-labelledby="order-checkout-title">
                <div class="orders-panel-heading"><h2 id="order-checkout-title">Precio, entrega y pago inicial</h2></div>
                <label class="field-group">
                    <span>Precio total acordado</span>
                    <input class="form-input" type="text" inputmode="numeric" name="agreed_total" maxlength="32" value="{{ $previous('agreed_total') }}" placeholder="25.000" data-order-agreed-total required>
                    <small class="field-help">Ingresa el precio en pesos, sin centavos.</small>
                </label>
                <label class="field-group">
                    <span>Fecha y hora de entrega</span>
                    <input class="form-input" type="datetime-local" name="scheduled_delivery_at" value="{{ $previous('scheduled_delivery_at') }}" required>
                    <small class="field-help">Hora local de Santiago.</small>
                </label>

                <x-ui.commercial-time-occurrence field="scheduled_delivery_at" label="Hora de entrega" />

                <div class="order-form-section">
                    <h3>Pago inicial <small>(opcional)</small></h3>
                    <p class="field-help">Puedes crear el pedido sin pago inicial.</p>
                    @if ($canCollect && $paymentMethods !== [])
                        @if ($cashSessions === [])
                            <p class="field-help">Para registrar un abono, debe haber una caja abierta.</p>
                        @endif
                        <label class="field-group">
                            <span>Caja</span>
                            <select class="form-input" name="cash_session_id" data-order-cash-session>
                                <option value="">Seleccionar al registrar un abono</option>
                                @foreach ($cashSessions as $session)
                                    <option value="{{ $session['id'] }}" @selected($previous('cash_session_id') === (string) $session['id'] || ($previous('cash_session_id') === '' && count($cashSessions) === 1))>{{ $session['nombre'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="order-payment-lines" data-order-payment-lines></div>
                        <button class="secondary-button compact-button" type="button" data-add-order-payment @disabled($cashSessions === [])>Agregar abono</button>
                    @endif
                </div>

                <div class="order-finance-summary" aria-live="polite">
                    <div><span>Total acordado</span><strong data-order-total>—</strong></div>
                    <div><span>Pago inicial</span><strong data-order-paid>$0</strong></div>
                    <div class="order-balance-row"><span>Saldo al guardar</span><strong data-order-balance>—</strong></div>
                </div>
                <p class="form-alert" data-order-form-error role="alert" hidden></p>
                <button class="primary-button order-submit-button" type="submit" data-order-submit>Crear pedido</button>
            </section>
        </form>
    </section>

    <script>
        window.panGestionOrderPayments = @json($initialPayments);
        window.panGestionOrderPaymentMethods = @json($paymentMethods);
    </script>
@endsection
