<section class="cash-panel" aria-labelledby="fund-history-title">
    <div class="cash-panel-heading">
        <div>
            <h2 id="fund-history-title">Historial de fondos autorizados</h2>
            <p>Autorizaciones de Caja principal por jornada. Un fondo vencido sin usar no representa un ingreso.</p>
        </div>
    </div>
    @if ($fundHistory->isEmpty())
        <p class="empty-state">Todavía no hay fondos autorizados.</p>
    @else
        <div class="cash-table-wrap">
            <table class="cash-table">
                <thead><tr><th>Jornada</th><th>Fondo</th><th>Estado</th><th>Origen</th><th>Autorización</th></tr></thead>
                <tbody>
                    @foreach ($fundHistory as $fund)
                        @php
                            $date = $fund->fecha_operativa->format('Y-m-d');
                            $status = match (true) {
                                $fund->utilizada_en !== null => 'Utilizado',
                                $date < $fundHistoryToday => 'Vencido sin usar',
                                $date > $fundHistoryToday => 'Programado',
                                default => 'Disponible',
                            };
                            $origin = match ($fund->origen) {
                                'inicial' => 'Primer fondo',
                                'cierre' => 'Cierre diario',
                                'apertura' => 'Autorización de apertura',
                                default => 'Origen sin identificar',
                            };
                        @endphp
                        <tr>
                            <td data-label="Jornada">{{ $fund->fecha_operativa->format('d/m/Y') }}</td>
                            <td data-label="Fondo">{{ \App\Support\Money::clp((string) $fund->monto) }}</td>
                            <td data-label="Estado"><span class="cash-status {{ $status === 'Disponible' ? 'is-open' : 'is-closed' }}">{{ $status }}</span></td>
                            <td data-label="Origen">{{ $origin }}</td>
                            <td data-label="Autorización">
                                <span>{{ trim($fund->autorizante->nombre.' '.$fund->autorizante->apellido) }}<br>
                                    <small>{{ \App\Support\CommercialDate::format($fund->created_at) }}</small></span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="cash-pagination">{{ $fundHistory->links() }}</div>
    @endif
</section>
