<a class="nav-link{{ $item['active'] ? ' is-active' : '' }}" href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif>
    <span class="nav-link-icon" aria-hidden="true">{{ $item['icon'] }}</span>
    <span class="nav-link-label">{{ $item['label'] }}</span>
</a>
