@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
<section class="auth-card" aria-labelledby="login-heading">
    <p class="eyebrow">Acceso seguro</p>
    <h1 id="login-heading">Iniciar sesión</h1>
    <p class="muted">Para preparar el uso temporal sin conexión, inicia sesión y abre la aplicación mientras tengas internet.</p>
    <form method="post" action="{{ route('login.store') }}" class="stack">
        @csrf
        <label for="rut">RUT</label>
        <input id="rut" name="rut" type="text" inputmode="text" value="{{ old('rut') }}" autocomplete="username" autocapitalize="characters" spellcheck="false" aria-describedby="rut-help" required autofocus>
        <small id="rut-help" class="muted">Ingresa tu RUT con o sin puntos y guion.</small>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label class="check-row"><input type="checkbox" name="remember" value="1"> Mantener sesión</label>
        <button class="button primary" type="submit">Entrar</button>
        <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
    </form>
</section>
@endsection
