@extends('layouts.app')

@section('title', 'Recuperar contraseña')

@section('content')
<section class="auth-card" aria-labelledby="forgot-password-heading">
    <p class="eyebrow">Recuperación de acceso</p>
    <h1 id="forgot-password-heading">¿Olvidaste tu contraseña?</h1>
    <p class="muted">Ingresa el correo asociado a tu cuenta. Si existe y está verificado, enviaremos instrucciones.</p>
    <form method="post" action="{{ route('password.email') }}" class="stack">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        <button class="button primary" type="submit">Enviar instrucciones</button>
        <a href="{{ route('login') }}">Volver al inicio de sesión</a>
    </form>
</section>
@endsection
