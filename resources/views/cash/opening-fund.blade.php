@extends('layouts.authenticated', ['title' => 'Autorizar fondo de apertura | Almapan'])

@section('page')
    <section class="cash-page" aria-labelledby="opening-fund-title">
        <header class="cash-page-header">
            <div>
                <p class="eyebrow">Inicio de jornada</p>
                <h1 id="opening-fund-title">Autorizar fondo de apertura</h1>
            </div>
            <a class="secondary-button inline-button" href="{{ route('cash.index') }}">Volver a Caja</a>
        </header>
        @if ($errors->any())
            <div class="form-alert" role="alert">{{ $errors->first() }}</div>
        @endif
        <section class="cash-panel">
            <form class="cash-fund-form" method="POST" action="{{ route('cash.funds.opening.store') }}" data-submit-once>
                @csrf
                <input type="hidden" name="caja_id" value="{{ $register->id }}">
                <input type="hidden" name="fecha_operativa" value="{{ $today }}">
                <input type="hidden" name="clave_idempotencia" value="{{ old('clave_idempotencia', (string) \Illuminate\Support\Str::uuid()) }}">
                <p><strong>{{ $register->nombre }}</strong> · Jornada <time datetime="{{ $today }}">{{ \Carbon\CarbonImmutable::parse($today, 'UTC')->format('d/m/Y') }}</time></p>
                <p id="opening-fund-help" class="field-help">Ingresa el dinero disponible para comenzar la jornada. Esta autorización no abre Caja ni registra un ingreso.</p>
                @if ($hasExpiredFund)
                    <p class="field-help">El fondo de una jornada anterior venció sin utilizarse. Revisa el dinero disponible antes de autorizar el fondo de hoy.</p>
                @endif
                <label class="field-group">
                    <span>Fondo autorizado</span>
                    <input class="form-input" name="monto" inputmode="decimal" value="{{ old('monto') }}" aria-describedby="opening-fund-help" required>
                </label>
                <label class="field-group">
                    <span>Motivo</span>
                    <textarea class="form-input" name="motivo" maxlength="5000" rows="3" required>{{ old('motivo') }}</textarea>
                </label>
                <label class="admin-check-row">
                    <input type="checkbox" name="confirmacion" value="1" required>
                    <span>Confirmo que verifiqué el efectivo disponible para esta jornada.</span>
                </label>
                <button class="primary-button" type="submit">Autorizar fondo</button>
            </form>
        </section>
    </section>
@endsection
