@extends('layouts.authenticated', ['title' => 'Venta realizada | Almapan'])

@section('page')
    <section class="sale-success-page" aria-labelledby="sale-success-title">
        <header class="sale-success-header">
            <span class="sale-success-mark" aria-hidden="true">✓</span>
            <div>
                <p class="eyebrow">Venta completada</p>
                <h1 id="sale-success-title">Venta realizada</h1>
                <p class="sale-success-number">N.º {{ $sale->numero_diario }} · {{ $sale->fecha_comercial->format('d/m/Y') }}</p>
            </div>
        </header>

        <div class="sale-success-summary">
            <span>Total</span>
            <strong>{{ \App\Support\Money::clp((string) $sale->total) }}</strong>
        </div>

        <div class="sale-payment-summary" aria-label="Medios de pago">
            @foreach ($sale->pagos as $payment)
                <div>
                    <span>{{ $payment->metodoPago->nombre }}</span>
                    <strong>{{ \App\Support\Money::clp((string) $payment->monto) }}</strong>
                </div>
            @endforeach
        </div>

        @if (\App\Support\Decimal::compare($change, '0.00', 2) > 0)
            <div class="change-callout">
                <span>Vuelto</span>
                <strong>{{ \App\Support\Money::clp($change) }}</strong>
            </div>
        @endif

        <div class="sale-success-actions">
            <a class="primary-button inline-button" href="{{ route('sales.create') }}">Nueva venta</a>
            <a class="secondary-button inline-button" href="{{ route('inicio') }}">Volver al inicio</a>
        </div>
    </section>
@endsection
