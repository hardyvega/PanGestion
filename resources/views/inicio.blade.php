@extends('layouts.authenticated', ['title' => 'Inicio | Almapan'])

@section('page')
    <section class="dashboard-page" aria-labelledby="dashboard-title">
        <div class="dashboard-header">
            <div>
                <p class="eyebrow">Resumen operativo</p>
                <h1 id="dashboard-title">Bienvenido</h1>
            </div>
            <div class="dashboard-status">
                @include('components.ui.badge', ['label' => 'Sesión activa', 'tone' => 'success'])
                <span class="dashboard-date">{{ now('America/Santiago')->format('d/m/Y') }}</span>
            </div>
        </div>

        <div class="period-toolbar" aria-label="Periodo de referencia">
            <span class="period-toolbar-label">Vista</span>
            <div class="period-options" role="group" aria-label="Seleccionar periodo">
                @foreach (['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes'] as $periodKey => $periodLabel)
                    <a class="period-option{{ $selectedPeriod === $periodKey ? ' is-active' : '' }}" href="{{ route('inicio', ['period' => $periodKey]) }}" @if ($selectedPeriod === $periodKey) aria-current="page" @endif>
                        {{ $periodLabel }}
                    </a>
                @endforeach
            </div>
            <span class="period-context">Resumen actualizado</span>
        </div>

        @if ($metrics->canViewFinancials)
            <section class="metric-grid" aria-label="Métricas principales">
                @include('components.dashboard.metric-card', [
                    'label' => 'Ventas de hoy',
                    'value' => \App\Support\Money::clp($metrics->salesToday),
                    'detail' => $metrics->salesTodayCount === 1 ? '1 venta completada' : $metrics->salesTodayCount.' ventas completadas',
                    'period' => 'Hoy',
                    'tone' => 'gold',
                    'icon' => '$',
                ])
                @include('components.dashboard.metric-card', [
                    'label' => 'Efectivo de hoy',
                    'value' => \App\Support\Money::clp($metrics->cashToday),
                    'detail' => 'Neto de cobros y devoluciones',
                    'period' => 'Hoy',
                    'tone' => 'terracotta',
                    'icon' => '$',
                ])
                @include('components.dashboard.metric-card', [
                    'label' => 'Débito de hoy',
                    'value' => \App\Support\Money::clp($metrics->debitToday),
                    'detail' => 'Neto por medio de pago',
                    'period' => 'Hoy',
                    'tone' => 'sage',
                    'icon' => '↗',
                ])
                @include('components.dashboard.metric-card', [
                    'label' => 'Ventas de esta semana',
                    'value' => \App\Support\Money::clp($metrics->salesWeek),
                    'detail' => 'Desde el lunes comercial',
                    'period' => 'Semana',
                    'tone' => 'coffee',
                    'icon' => '7',
                ])
                @include('components.dashboard.metric-card', [
                    'label' => 'Ventas de este mes',
                    'value' => \App\Support\Money::clp($metrics->salesMonth),
                    'detail' => 'Acumulado del mes actual',
                    'period' => 'Mes',
                    'tone' => 'plum',
                    'icon' => 'M',
                ])
            </section>
        @else
            <section class="dashboard-access-panel" aria-label="Acceso a métricas">
                @include('components.ui.empty-state', [
                    'icon' => '·',
                    'title' => 'Resumen disponible sin importes',
                    'message' => 'Tu usuario no tiene el permiso operativo necesario para consultar métricas financieras.',
                ])
            </section>
        @endif

        <section class="dashboard-section" aria-labelledby="quick-actions-title">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Atajos</p>
                    <h2 id="quick-actions-title">Acciones rápidas</h2>
                </div>
                <span class="section-note">Según tus permisos efectivos</span>
            </div>

            @if ($quickActions !== [])
                <div class="action-grid">
                    @foreach ($quickActions as $action)
                        @include('components.dashboard.action-card', ['action' => $action])
                    @endforeach
                </div>
            @else
                @include('components.ui.empty-state', [
                    'class' => ' empty-state-inline',
                    'title' => 'No hay acciones disponibles',
                    'message' => 'Las acciones aparecerán cuando tu usuario tenga permisos operativos asignados.',
                ])
            @endif
        </section>
    </section>
@endsection
