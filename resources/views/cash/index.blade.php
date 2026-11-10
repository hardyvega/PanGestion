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
            @if ($canAuthorizeInitialFund)
                <a class="secondary-button inline-button" href="{{ route('cash.funds.create') }}">Autorizar primer fondo</a>
            @endif
            @if ($canAuthorizeOpeningFund)
                <a class="secondary-button inline-button" href="{{ route('cash.funds.opening.create') }}">Autorizar fondo de apertura</a>
            @endif
        </header>

        @if (session('success'))
            <div class="form-success" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="form-alert" role="alert">{{ $errors->first() }}</div>
        @endif

        @if ($overview)
            @php
                $session = $overview['session'];
                $today = \App\Support\CommercialDate::today();
                $sessionDate = $session->fecha_operativa?->format('Y-m-d');
                $canConfirmClose = $canClose && \App\Services\Cash\CashSessionGuard::canClose($session, $today);
                $canRecordMovement = $canManageMovements && ! $session->cerrada_en && $sessionDate === $today;
                $lateClose = $canConfirmClose && $sessionDate < $today;
                $nextFundMin = $lateClose ? $today : \Carbon\CarbonImmutable::parse($today, 'America/Santiago')->addDay()->format('Y-m-d');
            @endphp
            <section class="cash-hero-panel" aria-labelledby="cash-current-title">
                @include('cash._session-heading', [
                    'session' => $session,
                    'registerName' => $overview['summary']['opening']['cash_register_name'] ?? null,
                    'openedBy' => $overview['summary']['opening']['user_name'] ?? null,
                ])

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

                <section class="cash-subheading" aria-labelledby="cash-payment-summary"><h3 id="cash-payment-summary">Ventas y cobros de la jornada</h3>
                    <p>Ventas completadas: {{ \App\Support\Money::clp($overview['summary']['sales_total'] ?? '0.00') }}</p>
                    <div class="cash-breakdown">@foreach(($overview['summary']['methods'] ?? []) as $method)<div><span>{{ $method['name'] }} · cobros netos</span><strong>{{ \App\Support\Money::clp($method['net']) }}</strong></div>@endforeach</div>
                    <details><summary>Ver operaciones y responsables</summary><div class="cash-table-wrap"><table class="cash-table"><thead><tr><th>Origen</th><th>Operación</th><th>Responsable</th><th>Método</th><th>Monto</th></tr></thead><tbody>@foreach(($overview['summary']['payments'] ?? []) as $payment)<tr><td>{{ $payment['source'] === 'venta' ? 'Venta' : 'Pedido' }} · {{ $payment['type'] === 'devolucion' ? 'Devolución' : 'Cobro' }}</td><td>{{ $payment['operation_id'] }} · pago {{ $payment['id'] }}</td><td>{{ $payment['user_name'] }}</td><td>{{ $payment['method'] }}</td><td>{{ \App\Support\Money::clp($payment['amount']) }}</td></tr>@endforeach</tbody></table></div></details>
                </section>
                @if($overview['closing'])
                    @php($closing = $overview['closing'])
                    <section class="cash-subheading"><h3>Cierre definitivo · {{ $closing->fecha_operativa->format('d/m/Y') }}</h3>
                        <p class="{{ \App\Support\Decimal::isZero((string)$closing->diferencia) ? 'form-success' : 'form-alert' }}">{{ \App\Support\Decimal::sign((string)$closing->diferencia) < 0 ? 'FALTANTE DE CAJA' : (\App\Support\Decimal::sign((string)$closing->diferencia) > 0 ? 'SOBRANTE DE CAJA' : 'CAJA CUADRADA') }}: {{ \App\Support\Money::clp((string)$closing->diferencia) }}</p>
                        <dl><dt>Contado antes del retiro</dt><dd>{{ \App\Support\Money::clp((string)$closing->contado_antes_retiro) }}</dd><dt>Retiro final confirmado</dt><dd>{{ \App\Support\Money::clp((string)$closing->retiro_final) }}</dd><dt>Fondo físico remanente</dt><dd>{{ \App\Support\Money::clp((string)$closing->fondo_remanente) }} para {{ $closing->fecha_siguiente->format('d/m/Y') }}</dd><dt>Confirmó</dt><dd>{{ $overview['summary']['confirmer']['name'] ?? '' }} · {{ $closing->confirmado_en->timezone('America/Santiago')->format('d/m/Y H:i') }}</dd></dl>
                        @if($closing->observacion)<p>{{ $closing->observacion }}</p>@endif
                    </section>
                @endif

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
                                        <td data-label="Concepto">{{ $movement['concept'] }} · {{ $movement['user_name'] }}</td>
                                        <td data-label="Monto" class="cash-number {{ in_array($movement['type'], ['egreso', 'retiro'], true) ? 'amount-negative' : '' }}">{{ \App\Support\Money::clp($movement['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if (! $session->cerrada_en && $sessionDate === null)
                    <p class="form-alert" role="status">Esta sesión no tiene fecha operativa. Requiere revisión del dueño antes de poder cerrarse.</p>
                @endif
                @if ($canRecordMovement || $canConfirmClose)
                    <div class="cash-actions-grid">
                        @if ($canRecordMovement)
                            @include('cash._manual-movement', ['session'=>$session])
                        @endif
                        @if ($canConfirmClose)
                            <section class="cash-action-panel" aria-labelledby="close-session-title">
                                <h3 id="close-session-title">Confirmar cierre definitivo</h3>
                                @if ($lateClose)
                                    <p class="empty-state">Cierre tardío. Se conservará la jornada original. Elige hoy o una fecha futura para el próximo fondo.</p>
                                @endif
                                <form method="POST" action="{{ route('cash.sessions.close', $session) }}" data-submit-once data-cash-close data-expected="{{ $overview['theoretical'] }}">
                                    @csrf
                                    <input type="hidden" name="clave_idempotencia" value="{{ old('clave_idempotencia', (string) \Illuminate\Support\Str::uuid()) }}">
                                    <input type="hidden" name="esperado_revisado" value="{{ $overview['theoretical'] }}">
                                    <p>Fecha operativa: {{ $session->fecha_operativa?->format('d/m/Y') ?? 'Sesión anterior: requiere revisión' }}</p>
                                    <label class="field-group"><span>Efectivo contado antes del retiro final</span><input class="form-input" name="monto_declarado" value="{{ old('monto_declarado') }}" inputmode="decimal" data-close-counted required></label>
                                    <p role="status" aria-live="polite" data-close-difference>Cuadratura pendiente</p>
                                    <label class="field-group"><span>Observación del cierre</span><textarea class="form-input" name="observacion" rows="3" data-close-observation>{{ old('observacion') }}</textarea></label>
                                    <label class="field-group"><span>Fecha operativa del próximo fondo</span><input type="date" class="form-input" name="fecha_siguiente" min="{{ $nextFundMin }}" value="{{ old('fecha_siguiente', $lateClose ? '' : $nextFundMin) }}" required></label>
                                    <label class="field-group"><span>Fondo que quedará físicamente en Caja principal</span><input class="form-input" name="fondo_siguiente" value="{{ old('fondo_siguiente') }}" inputmode="decimal" data-close-fund required></label>
                                    <p role="status" aria-live="polite" data-close-withdrawal>Retiro final pendiente</p>
                                    <label class="admin-check-row"><input type="checkbox" name="confirmacion" value="1" required><span>Confirmo el efectivo contado, la realización del retiro final indicado y este cierre irreversible.</span></label>
                                    <button class="danger-button" type="submit">Confirmar cierre definitivo</button>
                                </form>
                            </section>
                        @endif
                    </div>
                @endif
            </section>
        @elseif ($operationSession)
            <section class="cash-operating" aria-labelledby="cash-current-title">
                @include('cash._session-heading', ['session' => $operationSession])
                @if($canManageMovements)@include('cash._manual-movement', ['session'=>$operationSession])@endif
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
                    @foreach($registers as $register)
                    @php($fund = $register['fund'])
                    <section class="cash-open-register"><h3>{{ $register['nombre'] }}</h3>
                    @if($register['requires_review'])
                        <p class="empty-state" role="status">Hay una sesión de caja de otra jornada o sin fecha operativa pendiente de revisión. Solicita a un dueño autorizado que la revise antes de abrir otra sesión.</p>
                    @elseif($register['has_open_session'])
                        <p class="empty-state">Ya hay una sesión de caja abierta para hoy.</p>
                    @elseif($fund)
                    <form class="cash-open-form" method="POST" action="{{ route('cash.sessions.store') }}" data-submit-once>
                        @csrf
                        <input type="hidden" name="caja_id" value="{{ $register['id'] }}">
                        <input type="hidden" name="fondo_autorizado_id" value="{{ $fund->id }}">
                        <input type="hidden" name="fecha_operativa" value="{{ $fund->fecha_operativa->format('Y-m-d') }}">
                        <input type="hidden" name="clave_idempotencia" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <p>Fondo autorizado: <strong>{{ \App\Support\Money::clp((string)$fund->monto) }}</strong></p>
                        <p>Jornada {{ $fund->fecha_operativa->format('d/m/Y') }} · autorizó {{ $fund->autorizante?->nombre }} · {{ $fund->created_at->timezone('America/Santiago')->format('d/m/Y H:i') }}</p>
                        <button class="primary-button" type="submit">Confirmar apertura</button>
                    </form>
                    @elseif ($register['codigo'] === 'principal')
                        <p class="empty-state">Caja principal todavía no tiene un fondo autorizado para hoy. Solicita la autorización a un responsable.</p>
                    @else
                        <p class="empty-state">No hay un fondo autorizado vigente y disponible para hoy.</p>
                    @endif
                    </section>
                    @endforeach
                @endif
            </section>
        @endif

        @if ($fundHistory)
            @include('cash._fund-history')
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
                                        <td data-label="Diferencia" class="{{ $historicDifference !== null && !\App\Support\Decimal::isZero($historicDifference) ? 'amount-negative' : '' }}">{{ $historicDifference === null ? 'Pendiente' : (\App\Support\Decimal::sign($historicDifference) < 0 ? 'FALTANTE ' : (\App\Support\Decimal::sign($historicDifference) > 0 ? 'SOBRANTE ' : 'CUADRADA ')).\App\Support\Money::clp($historicDifference) }}</td>
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
