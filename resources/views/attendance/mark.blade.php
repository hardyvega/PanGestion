@extends('layouts.authenticated', ['title' => 'Asistencia | Almapan'])

@section('page')
<section class="personnel-page" aria-labelledby="attendance-title">
    <header class="personnel-page-header">
        <div>
            <p class="eyebrow">Personal / Operación</p>
            <h1 id="attendance-title">Asistencia</h1>
            <p class="personnel-intro">El inicio y cierre usan la hora del servidor y conservan la tarifa vigente como snapshot.</p>
        </div>
        @if ($canManage)
            <a class="secondary-button inline-button" href="{{ route('attendance.index') }}">Gestionar jornadas</a>
        @endif
    </header>

    @if (session('success'))
        <div class="form-alert form-alert-success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->has('personnel'))
        <div class="form-alert form-alert-error" role="alert">{{ $errors->first('personnel') }}</div>
    @endif

    @if ($workers->isEmpty())
        <section class="personnel-panel">
            @include('components.ui.empty-state', ['class' => ' empty-state-inline', 'title' => 'No hay trabajador vinculado', 'message' => 'Solicita que tu usuario sea vinculado a un trabajador antes de marcar asistencia.'])
        </section>
    @else
        <section class="attendance-cards" aria-label="Trabajadores habilitados">
            @foreach ($workers as $worker)
                @php($open = $openAttendances->firstWhere('trabajador_id', $worker->id))
                <article class="attendance-card">
                    <div class="attendance-card-heading">
                        <div>
                            <h2>{{ $worker->nombres }} {{ $worker->apellidos }}</h2>
                            <p>{{ $worker->codigo }}</p>
                        </div>
                        <span class="personnel-status {{ $open ? 'personnel-status-open' : '' }}">{{ $open ? 'Jornada abierta' : 'Sin jornada abierta' }}</span>
                    </div>
                    @if ($open)
                        <dl class="attendance-open-details">
                            <div><dt>Inicio</dt><dd>{{ $open->iniciada_en?->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</dd></div>
                        </dl>
                        <form method="POST" action="{{ route('attendance.close', $open) }}" class="attendance-action-form">
                            @csrf
                            @if ($canManage)
                                <label class="field-group"><span>Cierre manual opcional</span><input class="form-input" type="datetime-local" name="finalizada_en"></label>
                            @endif
                            <button class="primary-button" type="submit">Cerrar jornada</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('attendance.start') }}" class="attendance-action-form">
                            @csrf
                            <input type="hidden" name="trabajador_id" value="{{ $worker->id }}">
                            <button class="primary-button" type="submit">Iniciar jornada</button>
                        </form>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
</section>
@endsection
