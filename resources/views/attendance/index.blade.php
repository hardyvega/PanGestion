@extends('layouts.authenticated', ['title' => 'Jornadas | Almapan'])

@section('page')
<section class="personnel-page" aria-labelledby="attendance-manage-title">
    <header class="personnel-page-header">
        <div>
            <p class="eyebrow">Personal / Control</p>
            <h1 id="attendance-manage-title">Jornadas</h1>
            <p class="personnel-intro">Corrige y consulta asistencia sin borrar historial ni afectar caja.</p>
        </div>
        <a class="secondary-button inline-button" href="{{ route('attendance.mark') }}">Marcar asistencia</a>
    </header>

    @if (session('success'))
        <div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->has('personnel'))
        <div class="form-alert form-alert-error" role="alert">{{ $errors->first('personnel') }}</div>
    @endif

    <section class="personnel-panel">
        <div class="personnel-panel-heading"><div><h2>Registrar asistencia manual</h2><p>Solo acepta periodos cerrados, no futuros y con tarifa vigente.</p></div></div>
        <form class="personnel-filter-grid" method="POST" action="{{ route('attendance.manual.store') }}">
            @csrf
            <label class="field-group"><span>Trabajador</span><select class="form-input" name="trabajador_id" required><option value="">Selecciona</option>@foreach ($workers as $worker)<option value="{{ $worker->id }}">{{ $worker->nombres }} {{ $worker->apellidos }} · {{ $worker->codigo }}</option>@endforeach</select></label>
            <label class="field-group"><span>Inicio</span><input class="form-input" type="datetime-local" name="iniciada_en" required></label>
            <label class="field-group"><span>Término</span><input class="form-input" type="datetime-local" name="finalizada_en" required></label>
            <button class="primary-button" type="submit">Registrar jornada</button>
        </form>
    </section>

    <section class="personnel-panel">
        <div class="personnel-panel-heading"><div><h2>Historial de jornadas</h2><p>{{ $sessions->total() }} registros encontrados</p></div></div>
        <form class="personnel-filter-grid" method="GET" action="{{ route('attendance.index') }}">
            <label class="field-group"><span>Buscar trabajador</span><input class="form-input" type="search" name="q" value="{{ $filters['q'] }}" placeholder="Código, nombre o apellido"></label>
            <label class="field-group"><span>Estado</span><select class="form-input" name="estado"><option value="todas" @selected($filters['estado'] === 'todas')>Todos</option><option value="abierta" @selected($filters['estado'] === 'abierta')>Abiertas</option><option value="cerrada" @selected($filters['estado'] === 'cerrada')>Cerradas</option><option value="anulada" @selected($filters['estado'] === 'anulada')>Anuladas</option></select></label>
            <button class="secondary-button filter-submit" type="submit">Aplicar filtros</button>
        </form>
        <div class="personnel-table-wrap"><table class="personnel-table"><thead><tr><th>Trabajador</th><th>Inicio / término</th><th>Estado</th><th>Pago operacional</th><th>Acciones</th></tr></thead><tbody>
            @forelse ($sessions as $session)
                <tr>
                    <td data-label="Trabajador"><strong>{{ $session->trabajador->nombres }} {{ $session->trabajador->apellidos }}</strong><small>{{ $session->trabajador->codigo }}</small></td>
                    <td data-label="Inicio / término">{{ $session->iniciada_en?->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}<small>{{ $session->finalizada_en?->setTimezone(config('app.timezone'))->format('d/m/Y H:i') ?? 'Abierta' }}</small></td>
                    <td data-label="Estado"><span class="personnel-status {{ $session->estado === \App\Enums\AttendanceStatus::Open ? 'personnel-status-open' : '' }}">{{ $session->estado->value }}</span></td>
                    <td data-label="Pago operacional">{{ $session->monto_calculado !== null ? '$'.number_format((float) $session->monto_calculado, 2, ',', '.') : '—' }}<small>{{ $session->minutos_trabajados ?? '—' }} minutos</small></td>
                    <td data-label="Acciones"><div class="personnel-row-actions">
                        @if ($session->estado === \App\Enums\AttendanceStatus::Open)
                            <form method="POST" action="{{ route('attendance.close', $session) }}">@csrf<button class="text-button" type="submit">Cerrar ahora</button></form>
                        @endif
                        @if ($session->estado !== \App\Enums\AttendanceStatus::Annulled)
                            <form method="POST" action="{{ route('attendance.annul', $session) }}" class="inline-reason-form">@csrf<input class="form-input" name="motivo" placeholder="Motivo" required><button class="danger-button" type="submit">Anular</button></form>
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="5">No hay jornadas para mostrar.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="personnel-pagination">{{ $sessions->links() }}</div>
    </section>
</section>
@endsection
