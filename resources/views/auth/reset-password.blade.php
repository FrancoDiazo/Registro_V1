@extends('layouts.app')

@section('title', 'Definir nueva contraseña')

@section('content')
<section class="auth-card" aria-labelledby="reset-password-heading">
    <p class="eyebrow">Recuperación de acceso</p>
    <h1 id="reset-password-heading">Definir nueva contraseña</h1>
    <p class="muted">Elige una contraseña de al menos 12 caracteres. El enlace caduca y solo puede utilizarse una vez.</p>
    <form method="post" action="{{ route('password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        <label for="password">Nueva contraseña</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
        <label for="password_confirmation">Repite la contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
        <button class="button primary" type="submit">Restablecer contraseña</button>
        <a href="{{ route('login') }}">Volver al inicio de sesión</a>
    </form>
</section>
@endsection
