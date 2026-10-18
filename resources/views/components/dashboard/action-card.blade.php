    <a class="action-card{{ !empty($action['accent']) ? ' action-card-accent' : '' }}" href="{{ $action['href'] }}">
        <span class="action-card-icon" aria-hidden="true">{{ !empty($action['accent']) ? '+' : '→' }}</span>
        <span>
            <strong>{{ $action['label'] }}</strong>
            <small>{{ $action['description'] }}</small>
        </span>
        <span class="action-card-arrow" aria-hidden="true">→</span>
    </a>
