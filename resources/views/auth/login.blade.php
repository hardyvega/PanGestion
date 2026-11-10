@extends('layouts.app', ['title' => 'Iniciar sesión | Almapan', 'bodyClass' => 'auth-page'])

@section('content')
    <main class="login-shell">
        <section class="login-visual" aria-label="Identidad de Almapan">
            <div class="visual-content">
                <p class="visual-kicker">El trabajo diario, más claro</p>
                <h1>Almapan</h1>
                <p class="visual-copy">Una forma cálida y ordenada de cuidar cada jornada de tu panadería.</p>
            </div>
            <div class="bread-scene" aria-hidden="true">
                <span class="bread bread-one"></span>
                <span class="bread bread-two"></span>
                <span class="bread bread-three"></span>
            </div>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-card">
                <div class="mobile-brand" aria-hidden="true">
                    <span>Almapan</span>
                </div>

                <div class="login-heading">
                    <h2 id="login-title">Bienvenido de vuelta</h2>
                    <p>Ingresa para continuar con tu jornada.</p>
                </div>

                @if (session('permission_session_notice'))
                    <div class="form-alert" role="alert">{{ session('permission_session_notice') }}</div>
                @endif

                @if ($errors->any())
                    <div class="form-alert" role="alert" aria-live="polite">
                        Revisa los datos ingresados para continuar.
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="login-form" autocomplete="off" data-login-form>
                    @csrf

                    <div class="field-group">
                        <label for="nombre_usuario">Nombre de usuario</label>
                        <input
                            id="nombre_usuario"
                            name="nombre_usuario"
                            type="text"
                            value="{{ old('nombre_usuario') }}"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            required
                            autofocus
                            @class(['form-input', 'is-invalid' => $errors->has('nombre_usuario')])
                            @if ($errors->has('nombre_usuario')) aria-describedby="nombre_usuario-error" @endif
                        >
                        @error('nombre_usuario')
                            <p id="nombre_usuario-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label for="password">Contraseña</label>
                        <div class="password-field">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                value=""
                                autocomplete="off"
                                required
                                @class(['form-input', 'is-invalid' => $errors->has('password')])
                                @if ($errors->has('password')) aria-describedby="password-error" @endif
                            >
                            <button
                                type="button"
                                class="password-toggle"
                                data-password-toggle
                                aria-label="Mostrar contraseña"
                                aria-controls="password"
                                aria-pressed="false"
                            >
                                <span class="password-toggle-icon" aria-hidden="true"></span>
                                <span data-toggle-label>Mostrar</span>
                            </button>
                        </div>
                        @error('password')
                            <p id="password-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="primary-button">Iniciar sesión</button>
                </form>

                <p class="login-note">Usa las credenciales entregadas por el administrador.</p>
            </div>
        </section>
    </main>
@endsection
