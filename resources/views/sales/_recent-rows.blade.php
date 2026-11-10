@forelse ($rows as $row)
    <li class="pos-recent-sale">
        <div class="pos-recent-heading">
            <span><strong>N.º {{ $row['number'] }}</strong> · {{ $row['time'] }}</span>
            <strong>{{ $row['total'] }}</strong>
        </div>
        <p class="pos-recent-products">{{ $row['products'] ?: 'Sin detalle de productos' }}@if ($row['more']) · y más @endif</p>
        <p class="pos-recent-meta">{{ $row['operator'] }} · {{ $row['payment'] }}</p>
    </li>
@empty
    <li class="pos-recent-empty">Todavía no hay ventas completadas en esta jornada.</li>
@endforelse
