@extends('layouts.authenticated', ['title' => 'Caja | Almapan'])

@section('page')
    <section class="cash-page" aria-labelledby="cash-title">
        <header class="cash-page-header">
            <div>
                <p class="eyebrow">Operación diaria</p>
                <h1 id="cash-title">Caja</h1>
                <p class="cash-intro">Controla sesiones, efectivo físico y movimientos con trazabilidad.</p>
            </div>
            @if ($canViewMovements)
                <a class="secondary-button inline-button" href="{{ route('cash.movements') }}">Ver movimientos</a>
            @endif
        </header>

        @if (session('success'))
            <div class="form-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="form-alert" role="alert">{{ $errors->first() }}</div>
        @endif

        @if ($overview)
            @php($session = $overview['session'])
            <section class="cash-hero-panel" aria-labelledby="cash-current-title">
                <div class="cash-panel-heading">
                    <div>
                        <p class="eyebrow">Sesión seleccionada</p>
                        <h2 id="cash-current-title">{{ $session->caja?->nombre ?? 'Caja' }}</h2>
                        <p>{{ $session->cerrada_en ? 'Sesión cerrada' : 'Sesión abierta' }} · abierta por {{ $session->usuarioApertura?->nombre ?? 'Usuario' }}</p>
                    </div>
                    <span class="cash-status {{ $session->cerrada_en ? 'is-closed' : 'is-open' }}">{{ $session->cerrada_en ? 'Cerrada' : 'Abierta' }}</span>
                </div>

                <div class="cash-summary-grid">
                    <div class="cash-summary-card"><span>Monto inicial</span><strong>{{ \App\Support\Money::clp((string) $session->monto_inicial) }}</strong></div>
                    <div class="cash-summary-card cash-summary-card-highlight"><span>Monto teórico</span><strong>{{ \App\Support\Money::clp($overview['theoretical']) }}</strong></div>
                    <div class="cash-summary-card"><span>Monto declarado</span><strong>{{ $session->monto_cierre_declarado === null ? 'Pendiente' : \App\Support\Money::clp((string) $session->monto_cierre_declarado) }}</strong></div>
                    <div class="cash-summary-card"><span>Diferencia</span><strong class="{{ $overview['difference'] !== null && str_starts_with($overview['difference'], '-') ? 'amount-negative' : '' }}">{{ $overview['difference'] === null ? 'Pendiente' : \App\Support\Money::clp($overview['difference']) }}</strong></div>
                </div>

                <div class="cash-breakdown" aria-label="Resumen de movimientos">
                    <div><span>Cobros efectivo</span><strong>{{ \App\Support\Money::clp($overview['totals']['cash_collections']) }}</strong></div>
                    <div><span>Devoluciones efectivo</span><strong>{{ \App\Support\Money::clp($overview['totals']['cash_refunds']) }}</strong></div>
                    <div><span>Ingresos manuales</span><strong>{{ \App\Support\Money::clp($overview['totals']['manual_income']) }}</strong></div>
                    <div><span>Egresos manuales</span><strong>{{ \App\Support\Money::clp($overview['totals']['manual_expense']) }}</strong></div>
                    <div><span>Retiros</span><strong>{{ \App\Support\Money::clp($overview['totals']['withdrawals']) }}</strong></div>
                </div>

                <div class="cash-panel-heading cash-subheading">
                    <div><h3>Movimientos recientes</h3><p>Historial de solo lectura.</p></div>
                </div>
                @if ($overview['movements']->isEmpty())
                    <p class="empty-state">Todavía no hay movimientos en esta sesión.</p>
                @else
                    <div class="cash-table-wrap">
                        <table class="cash-table">
                            <thead><tr><th>Fecha</th><th>Tipo</th><th>Origen</th><th>Concepto</th><th class="cash-number">Monto</th></tr></thead>
                            <tbody>
                                @foreach ($overview['movements'] as $movement)
                                    <tr>
                                        <td data-label="Fecha">{{ \Carbon\CarbonImmutable::parse($movement['occurred_at'])->timezone('America/Santiago')->format('d/m/Y H:i') }}</td>
                                        <td data-label="Tipo">{{ ucfirst($movement['type']) }}</td>
                                        <td data-label="Origen">{{ $movement['is_refund'] ? 'Devolución · '.$movement['source_label'] : $movement['source_label'] }}</td>
                                        <td data-label="Concepto">{{ $movement['concept'] }}</td>
                                        <td data-label="Monto" class="cash-number {{ in_array($movement['type'], ['egreso', 'retiro'], true) ? 'amount-negative' : '' }}">{{ \App\Support\Money::clp($movement['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (! $session->cerrada_en && ($canManageMovements || $canClose))
                    <div class="cash-actions-grid">
                        @if ($canManageMovements)
                            <section class="cash-action-panel" aria-labelledby="manual-movement-title">
                                <h3 id="manual-movement-title">Registrar movimiento manual</h3>
                                <form method="POST" action="{{ route('cash.movements.store', $session) }}" data-submit-once>
                                    @csrf
                                    <label class="field-group"><span>Tipo</span><select class="form-input" name="tipo_movimiento" required><option value="ingreso">Ingreso</option><option value="egreso">Egreso</option><option value="retiro">Retiro</option></select></label>
                                    <label class="field-group"><span>Monto</span><input class="form-input" name="monto" inputmode="decimal" placeholder="0.00" required></label>
                                    <label class="field-group"><span>Concepto</span><textarea class="form-input" name="concepto" rows="3" required></textarea></label>
                                    <button class="primary-button" type="submit">Registrar movimiento</button>
                                </form>
                            </section>
                        @endif
                        @if ($canClose)
                            <section class="cash-action-panel" aria-labelledby="close-session-title">
                                <h3 id="close-session-title">Cerrar sesión</h3>
                                <p class="field-help">El monto teórico se calcula en el servidor al confirmar.</p>
                                <form method="POST" action="{{ route('cash.sessions.close', $session) }}" data-submit-once>
                                    @csrf
                                    <label class="field-group"><span>Monto declarado</span><input class="form-input" name="monto_declarado" inputmode="decimal" placeholder="0.00" required></label>
                                    <label class="field-group"><span>Observación opcional</span><textarea class="form-input" name="observacion" rows="3"></textarea></label>
                                    <button class="danger-button" type="submit">Cerrar sesión</button>
                                </form>
                            </section>
                        @endif
                    </div>
                @endif
            </section>
        @elseif ($canViewSessions)
            <section class="cash-empty-panel"><h2>No hay una sesión seleccionada</h2><p>Abre una sesión o selecciona una sesión histórica para consultar su detalle.</p></section>
        @endif

        @if ($canOpen)
            <section class="cash-panel" aria-labelledby="open-session-title">
                <div class="cash-panel-heading"><div><p class="eyebrow">Inicio de turno</p><h2 id="open-session-title">Abrir sesión de caja</h2><p>Solo se puede abrir una sesión por caja activa.</p></div></div>
                @if ($registers->isEmpty())
                    <p class="empty-state">No hay cajas activas disponibles.</p>
                @else
                    <form class="cash-open-form" method="POST" action="{{ route('cash.sessions.store') }}" data-submit-once>
                        @csrf
                        <label class="field-group"><span>Caja</span><select class="form-input" name="caja_id" required><option value="">Selecciona una caja</option>@foreach ($registers as $register)<option value="{{ $register['id'] }}">{{ $register['nombre'] }} · {{ $register['codigo'] }}</option>@endforeach</select></label>
                        <label class="field-group"><span>Monto inicial</span><input class="form-input" name="monto_inicial" inputmode="decimal" placeholder="0.00" required></label>
                        <button class="primary-button" type="submit">Abrir caja</button>
                    </form>
                @endif
            </section>
        @endif

        @if ($canViewSessions && $sessions)
            <section class="cash-panel" aria-labelledby="session-history-title">
                <div class="cash-panel-heading"><div><p class="eyebrow">Trazabilidad</p><h2 id="session-history-title">Historial de sesiones</h2><p>Consulta montos, responsables y cierres anteriores.</p></div></div>
                @if ($sessions->isEmpty())
                    <p class="empty-state">Todavía no hay sesiones registradas.</p>
                @else
                    <div class="cash-table-wrap">
                        <table class="cash-table">
                            <thead><tr><th>Caja</th><th>Apertura</th><th>Inicial</th><th>Estado</th><th>Teórico cierre</th><th>Declarado</th><th>Diferencia</th></tr></thead>
                            <tbody>
                                @foreach ($sessions as $historic)
                                    @php($historicDifference = $historic->monto_cierre_declarado === null || $historic->monto_teorico_cierre === null ? null : \App\Support\Decimal::subtract((string) $historic->monto_cierre_declarado, (string) $historic->monto_teorico_cierre, 2))
                                    <tr>
                                        <td data-label="Caja"><a class="text-button" href="{{ route('cash.index', ['session' => $historic->getKey()]) }}">{{ $historic->caja?->nombre ?? 'Caja' }}</a></td>
                                        <td data-label="Apertura">{{ $historic->abierta_en?->timezone('America/Santiago')->format('d/m/Y H:i') }}</td>
                                        <td data-label="Inicial">{{ \App\Support\Money::clp((string) $historic->monto_inicial) }}</td>
                                        <td data-label="Estado"><span class="cash-status {{ $historic->cerrada_en ? 'is-closed' : 'is-open' }}">{{ $historic->cerrada_en ? 'Cerrada' : 'Abierta' }}</span></td>
                                        <td data-label="Teórico cierre">{{ $historic->monto_teorico_cierre === null ? 'Pendiente' : \App\Support\Money::clp((string) $historic->monto_teorico_cierre) }}</td>
                                        <td data-label="Declarado">{{ $historic->monto_cierre_declarado === null ? 'Pendiente' : \App\Support\Money::clp((string) $historic->monto_cierre_declarado) }}</td>
                                        <td data-label="Diferencia" class="{{ $historicDifference !== null && str_starts_with($historicDifference, '-') ? 'amount-negative' : '' }}">{{ $historicDifference === null ? 'Pendiente' : \App\Support\Money::clp($historicDifference) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="cash-pagination">{{ $sessions->links() }}</div>
                @endif
            </section>
        @endif
    </section>
@endsection
