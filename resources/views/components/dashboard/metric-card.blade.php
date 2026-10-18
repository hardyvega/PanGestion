<article class="metric-card metric-card-{{ $tone ?? 'gold' }}">
    <div class="metric-card-topline">
        <span class="metric-icon" aria-hidden="true">{{ $icon ?? '·' }}</span>
        <span class="metric-period">{{ $period ?? 'Hoy' }}</span>
    </div>
    <p class="metric-label">{{ $label }}</p>
    <p class="metric-value">{{ $value }}</p>
    @if (!empty($detail))
        <p class="metric-detail">{{ $detail }}</p>
    @endif
</article>
