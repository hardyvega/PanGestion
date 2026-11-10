@php($openedAt = $session->abierta_en->timezone('America/Santiago'))
<div class="cash-panel-heading">
    <div class="cash-session-details">
        <h2 id="cash-current-title">{{ $registerName ?? $session->caja?->nombre ?? 'Caja' }}</h2>
        <p>Abierta por {{ $openedBy ?? (trim($session->usuarioApertura?->nombre.' '.$session->usuarioApertura?->apellido) ?: 'Usuario') }}</p>
        <p>Jornada: @if ($session->fecha_operativa)<time datetime="{{ $session->fecha_operativa->format('Y-m-d') }}">{{ $session->fecha_operativa->format('d/m/Y') }}</time>@else No registrada @endif</p>
        <p>Apertura real: <time datetime="{{ $openedAt->toIso8601String() }}">{{ $openedAt->format('d/m/Y H:i:s') }}</time> (America/Santiago)</p>
    </div>
    <span class="cash-status {{ $session->cerrada_en ? 'is-closed' : 'is-open' }}">{{ $session->cerrada_en ? 'Cerrada' : 'Abierta' }}</span>
</div>
