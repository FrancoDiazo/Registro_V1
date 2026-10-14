<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#D50032">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Registro técnico de asistencia laboral">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <title>@yield('title', 'Asistencia') · {{ config('app.name') }}</title>
    <script type="module" src="{{ asset('js/app.js') }}"></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ auth()->check() && auth()->user()->isAdministrator() ? route('admin.dashboard') : route('dashboard') }}">Asistencia</a>
    @auth
        <div class="topbar-actions">
            <span class="user-name">{{ auth()->user()->name }}</span>
            @if (auth()->user()->isAdministrator())
                <a href="{{ route('admin.dashboard') }}">Administración</a>
            @else
                <a href="{{ route('history') }}">Mi historial</a>
            @endif
            <form method="post" action="{{ route('logout') }}">@csrf<button class="link-button" type="submit">Cerrar sesión</button></form>
        </div>
    @endauth
</header>
<main class="page">
    @if (session('status'))
        <div class="notice success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice error" role="alert">
            <p>Revisa la información ingresada.</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
<footer class="footer">MVP técnico. Antes de reemplazar el registro vigente, valida los requisitos aplicables con RR. HH. y la Dirección del Trabajo.</footer>
</body>
</html>
