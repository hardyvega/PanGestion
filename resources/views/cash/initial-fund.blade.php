@extends('layouts.authenticated', ['title' => 'Primer fondo | Almapan'])

@section('page')
<section class="cash-page">
    <header class="cash-page-header"><div><p class="eyebrow">Configuración inicial</p><h1>Autorizar primer fondo</h1></div><a class="secondary-button inline-button" href="{{ route('cash.index') }}">Volver a Caja</a></header>
    @if($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
    <section class="cash-panel">
        <form method="POST" action="{{ route('cash.funds.store') }}" class="cash-open-form" data-submit-once>
            @csrf
            <input type="hidden" name="clave_idempotencia" value="{{ old('clave_idempotencia', (string) \Illuminate\Support\Str::uuid()) }}">
            <label class="field-group"><span>Caja</span><select class="form-input" name="caja_id" required>@foreach($registers as $register)<option value="{{ $register->id }}">{{ $register->nombre }}</option>@endforeach</select></label>
            <label class="field-group"><span>Fecha operativa destinataria</span><input type="date" class="form-input" name="fecha_operativa" value="{{ old('fecha_operativa', \App\Support\CommercialDate::today()) }}" required></label>
            <label class="field-group"><span>Fondo autorizado</span><input class="form-input" name="monto" inputmode="decimal" value="{{ old('monto') }}" required></label>
            <label class="field-group"><span>Motivo</span><textarea class="form-input" name="motivo" required>{{ old('motivo', 'Configuración inicial') }}</textarea></label>
            <label class="admin-check-row"><input type="checkbox" name="confirmacion" value="1" required><span>Confirmo personalmente este primer fondo y su fecha destinataria.</span></label>
            <button type="submit" class="primary-button">Autorizar fondo</button>
        </form>
    </section>
</section>
@endsection
