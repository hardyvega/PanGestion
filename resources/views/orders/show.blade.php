@extends('layouts.authenticated', ['title' => 'Pedido #'.$order->getKey().' | Almapan'])

@section('page')
    <section class="orders-page order-detail-page" data-order-detail>
        <header class="orders-page-header">
            <div>
                <p class="eyebrow">Pedido #{{ $order->getKey() }}</p>
                <h1>{{ $order->cliente?->nombre ?? 'Cliente no disponible' }}</h1>
                <p class="orders-intro">Creado el {{ $order->created_at?->format('d/m/Y H:i') }} por {{ $order->usuarioCreador?->nombre ?? 'usuario registrado' }}.</p>
            </div>
            <a class="secondary-button inline-button" href="{{ route('orders.index') }}">Volver a pedidos</a>
        </header>

        @if (session('success'))
            <div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="form-alert" role="alert">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <div class="order-detail-topline">
            <span class="order-status-badge order-status-{{ $order->estado->value }}">{{ ucfirst(str_replace('_', ' ', $order->estado->value)) }}</span>
            @if ($order->entrega_programada_en)
                <span class="detail-meta">Entrega programada: {{ $order->entrega_programada_en->format('d/m/Y H:i') }}</span>
            @endif
            @if ($order->observacion)
                <span class="detail-meta">{{ $order->observacion }}</span>
            @endif
        </div>

        <div class="order-detail-layout">
            <div class="order-detail-main">
                <section class="orders-panel">
                    <div class="orders-panel-heading">
                        <div>
                            <h2>Artículos</h2>
                            <p>{{ $order->detalles->count() }} línea(s) con snapshots históricos.</p>
                        </div>
                    </div>
                    <div class="detail-lines">
                        @foreach ($order->detalles as $detail)
                            <div class="detail-line">
                                <div>
                                    <strong>{{ $detail->articulo_nombre_snapshot }}</strong>
                                    <small>{{ $detail->articulo_sku_snapshot }} · {{ $detail->unidad_simbolo_snapshot }} · {{ $detail->cantidad }}</small>
                                </div>
                                <div class="detail-line-price">
                                    <span>{{ \App\Support\Money::clp((string) $detail->precio_unitario) }} c/u</span>
                                    <strong>{{ \App\Support\Money::clp(\App\Support\Decimal::multiply((string) $detail->cantidad, (string) $detail->precio_unitario, 2)) }}</strong>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="orders-panel">
                    <div class="orders-panel-heading">
                        <div>
                            <h2>Pagos</h2>
                            <p>Los cobros y devoluciones permanecen trazables.</p>
                        </div>
                    </div>
                    <div class="order-finance-summary">
                        <div><span>Total</span><strong>{{ \App\Support\Money::clp((string) $finance['total']) }}</strong></div>
                        <div><span>Pagado neto</span><strong>{{ \App\Support\Money::clp((string) $finance['paid']) }}</strong></div>
                        <div class="order-balance-row"><span>Saldo</span><strong>{{ \App\Support\Money::clp((string) $finance['balance']) }}</strong></div>
                    </div>
                    @if ($order->pagos->isEmpty())
                        <p class="empty-inline">No hay pagos registrados.</p>
                    @else
                        <div class="payment-history-list">
                            @foreach ($order->pagos as $payment)
                                <div class="payment-history-row">
                                    <div>
                                        <strong>{{ $payment->metodoPago?->nombre ?? 'Método no disponible' }}</strong>
                                        <small>{{ $payment->tipo->value === 'cobro' ? 'Cobro' : 'Devolución' }} · {{ $payment->ocurrido_en?->format('d/m/Y H:i') }}</small>
                                    </div>
                                    <strong class="{{ $payment->tipo->value === 'devolucion' ? 'amount-negative' : '' }}">{{ $payment->tipo->value === 'devolucion' ? '-' : '' }}{{ \App\Support\Money::clp((string) $payment->monto) }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <aside class="order-detail-side">
                <section class="orders-panel order-actions-panel">
                    <div class="orders-panel-heading">
                        <div>
                            <h2>Acciones</h2>
                            <p>Disponibles según estado y permisos efectivos.</p>
                        </div>
                    </div>

                    @if ($order->estado === \App\Enums\OrderStatus::Pending && $canManage)
                        <form method="POST" action="{{ route('orders.confirm', $order) }}" data-submit-once>
                            @csrf
                            <input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="primary-button action-button" type="submit">Confirmar pedido</button>
                        </form>
                    @elseif ($order->estado === \App\Enums\OrderStatus::Confirmed && $canManage)
                        <form method="POST" action="{{ route('orders.preparation', $order) }}" data-submit-once>
                            @csrf
                            <input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="primary-button action-button" type="submit">Pasar a preparación</button>
                        </form>
                    @elseif ($order->estado === \App\Enums\OrderStatus::InPreparation && $canManage)
                        <form method="POST" action="{{ route('orders.ready', $order) }}" data-submit-once>
                            @csrf
                            <input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="primary-button action-button" type="submit">Marcar como listo</button>
                        </form>
                    @elseif ($order->estado === \App\Enums\OrderStatus::Ready && $canDeliver)
                        <button class="primary-button action-button" type="button" data-open-delivery>Entregar pedido</button>
                    @elseif ($order->estado === \App\Enums\OrderStatus::Delivered)
                        <p class="action-complete">Pedido entregado{{ $order->entregado_en ? ' el '.$order->entregado_en->format('d/m/Y H:i') : '' }}.</p>
                    @elseif ($order->estado === \App\Enums\OrderStatus::Cancelled)
                        <p class="action-complete">Pedido cancelado{{ $order->cancelado_en ? ' el '.$order->cancelado_en->format('d/m/Y H:i') : '' }}.</p>
                    @else
                        <p class="action-complete">No hay acciones disponibles para este usuario y estado.</p>
                    @endif

                    @if (in_array($order->estado, [\App\Enums\OrderStatus::Pending, \App\Enums\OrderStatus::Confirmed, \App\Enums\OrderStatus::InPreparation, \App\Enums\OrderStatus::Ready], true) && $canCancel)
                        <button class="danger-button action-button" type="button" data-open-cancel>Cancelar pedido</button>
                    @endif
                </section>

                <section class="orders-panel order-history-panel">
                    <div class="orders-panel-heading">
                        <div>
                            <h2>Historial</h2>
                            <p>Registro append-only.</p>
                        </div>
                    </div>
                    <ol class="order-history-list">
                        @foreach ($order->transiciones->sortByDesc('ocurrida_en') as $transition)
                            <li>
                                <span class="history-dot" aria-hidden="true"></span>
                                <div>
                                    <strong>{{ $transition->estado_nuevo->value === 'pendiente' ? 'Pedido creado' : ucfirst(str_replace('_', ' ', $transition->estado_nuevo->value)) }}</strong>
                                    <small>{{ $transition->ocurrida_en?->format('d/m/Y H:i') }} · {{ $transition->usuario?->nombre ?? 'usuario registrado' }}</small>
                                    @if ($transition->motivo)<small>Motivo: {{ $transition->motivo }}</small>@endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </aside>
        </div>
    </section>

    <div class="order-modal" data-order-modal data-modal-name="cancel" hidden>
        <div class="order-modal-backdrop" data-close-modal></div>
        <section class="order-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
            <button class="modal-close" type="button" aria-label="Cerrar" data-close-modal>×</button>
            <h2 id="cancel-title">Cancelar pedido</h2>
            <p>El motivo quedará guardado en el historial y los pagos netos positivos se devolverán mediante el servicio existente.</p>
            <form method="POST" action="{{ route('orders.cancel', $order) }}" data-submit-once>
                @csrf
                <input type="hidden" name="command_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label class="field-group">
                    <span>Motivo obligatorio</span>
                    <textarea class="form-input" name="reason" rows="3" required maxlength="1000"></textarea>
                </label>
                <button class="danger-button" type="submit">Confirmar cancelación</button>
            </form>
        </section>
    </div>

    <div class="order-modal" data-order-modal data-modal-name="delivery" hidden>
        <div class="order-modal-backdrop" data-close-modal></div>
        <section class="order-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="delivery-title">
            <button class="modal-close" type="button" aria-label="Cerrar" data-close-modal>×</button>
            <h2 id="delivery-title">Entregar pedido</h2>
            <p>La entrega creará la venta derivada y aplicará stock, ledger, pagos y caja dentro de una sola operación.</p>
            <form method="POST" action="{{ route('orders.deliver', $order) }}" data-delivery-form data-submit-once>
                @csrf
                <input type="hidden" name="command_uuid" value="{{ $deliveryUuid }}">
                @if ($finance['balance'] !== '0.00')
                    @if ($canCollect)
                        <div class="delivery-balance-callout"><span>Saldo final</span><strong>{{ \App\Support\Money::clp((string) $finance['balance']) }}</strong></div>
                        <div class="order-delivery-payment" data-delivery-payment>
                            <input type="hidden" name="final_payment[amount]" value="{{ $finance['balance'] }}">
                            <input type="hidden" name="final_payment[payment_uuid]" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <label class="field-group">
                                <span>Método de pago</span>
                                <select class="form-input" name="final_payment[payment_method_id]" data-delivery-method required>
                                    <option value="">Seleccionar método</option>
                                    @foreach ($paymentMethods as $method)
                                        <option value="{{ $method['id'] }}" data-affects-cash="{{ $method['afecta_efectivo'] ? '1' : '0' }}">{{ $method['nombre'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field-group">
                                <span>Sesión de caja</span>
                                <select class="form-input" name="final_payment[cash_session_id]" required>
                                    <option value="">Seleccionar sesión</option>
                                    @foreach ($cashSessions as $session)
                                        <option value="{{ $session['id'] }}">{{ $session['nombre'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field-group" data-delivery-received-field hidden>
                                <span>Efectivo recibido</span>
                                <input class="form-input" type="text" inputmode="decimal" name="final_payment[received_amount]" data-delivery-received>
                            </label>
                            <p class="field-help" data-delivery-change></p>
                        </div>
                    @else
                        <div class="form-alert">El pedido tiene saldo pendiente. Para entregarlo necesitas también el permiso de cobro.</div>
                    @endif
                @else
                    <p class="action-complete">El pedido está completamente pagado. No se requiere un cobro adicional.</p>
                @endif
                <button class="primary-button" type="submit" @disabled($finance['balance'] !== '0.00' && ! $canCollect)>Confirmar entrega</button>
            </form>
        </section>
    </div>
@endsection
