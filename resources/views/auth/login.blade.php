@extends('layouts.app')

@section('title', 'Iniciar sesión')

@section('content')
<section class="auth-card" aria-labelledby="login-heading">
    <p class="eyebrow">Acceso seguro</p>
    <h1 id="login-heading">Iniciar sesión</h1>
    <p class="muted">Para preparar el uso temporal sin conexión, inicia sesión y abre la aplicación mientras tengas internet.</p>
    <form method="post" action="{{ route('login.store') }}" class="stack">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label class="check-row"><input type="checkbox" name="remember" value="1"> Mantener sesión</label>
        <button class="button primary" type="submit">Entrar</button>
        <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
    </form>
</section>
@endsection
