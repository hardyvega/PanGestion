@extends('layouts.app', ['title' => $title ?? 'Inicio | Almapan', 'bodyClass' => 'authenticated-page'])

@section('content')
    <div class="app-shell">
        <div class="app-scrim" data-nav-scrim hidden></div>

        <aside class="app-sidebar" id="app-sidebar" aria-label="Navegación principal">
            <div class="sidebar-brand">
                <span class="brand-mark" aria-hidden="true">A</span>
                <span>
                    <strong>Almapan</strong>
                    <small>Operación diaria</small>
                </span>
                <button type="button" class="nav-close" data-nav-close aria-label="Cerrar navegación">×</button>
            </div>

            @php($navigation = app(\App\Services\Navigation\NavigationBuilder::class)->forUser($user))
            <nav class="app-navigation" aria-label="Módulos">
                @foreach ($navigation as $group)
                    <section class="nav-group" aria-labelledby="nav-group-{{ $loop->index }}">
                        <p class="nav-group-label" id="nav-group-{{ $loop->index }}">{{ $group['label'] }}</p>
                        <div class="nav-group-items">
                            @foreach ($group['items'] as $item)
                                @include('components.navigation.item', ['item' => $item])
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->nombre, 0, 1)) }}</span>
                    <span>
                        <strong>{{ $user->nombre }}</strong>
                        <small>{{ $user->nombre_usuario }}</small>
                    </span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-button">
                        <span aria-hidden="true">↗</span>
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </aside>

        <div class="app-main-column">
            <header class="app-topbar">
                <button type="button" class="nav-toggle" data-nav-toggle aria-controls="app-sidebar" aria-expanded="false">
                    <span class="nav-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span class="sr-only">Abrir navegación</span>
                </button>
                <div class="topbar-context">
                    <span class="topbar-kicker">Almapan</span>
                    <span class="topbar-divider" aria-hidden="true">/</span>
                    <span>{{ $title ?? 'Inicio' }}</span>
                </div>
                <div class="topbar-user">
                    <span class="avatar avatar-small" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->nombre, 0, 1)) }}</span>
                    <span class="topbar-user-name">{{ $user->nombre }}</span>
                </div>
            </header>

            <main class="app-main">
                @yield('page')
            </main>
        </div>
    </div>
@endsection
