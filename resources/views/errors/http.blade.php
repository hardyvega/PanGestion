@extends('layouts.app', ['title' => $heading.' | Almapan', 'bodyClass' => 'public-error-page'])

@section('content')
    <main class="public-error-content" aria-labelledby="error-title">
        <a class="text-button" href="{{ route('inicio') }}">Almapan</a>
        <p class="eyebrow">Error {{ $status }}</p>
        <h1 id="error-title">{{ $heading }}</h1>
        <p role="alert">{{ $message }}</p>
        <nav class="public-error-actions" aria-label="Opciones disponibles">
            <a class="primary-button inline-button" href="{{ route('inicio') }}">Volver al inicio</a>
            @if (in_array($status, [401, 419], true))
                <a class="secondary-button inline-button" href="{{ route('login') }}">Iniciar sesión</a>
            @endif
        </nav>
    </main>
@endsection
