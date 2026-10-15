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
<body class="{{ auth()->check() && auth()->user()->role === 'worker' ? 'has-worker-nav' : '' }}">
<a class="skip-link" href="#main-content">Saltar al contenido</a>
<header class="topbar">
    <a class="product-name" href="{{ auth()->check() && auth()->user()->isAdministrator() ? route('admin.dashboard') : route('dashboard') }}">
        <span class="product-name__eyebrow">Control de asistencia</span>
        <span class="product-name__title">Registro laboral</span>
    </a>
    @auth
        <div class="topbar-actions">
            <span class="user-name">{{ auth()->user()->name }}</span>
            @if (auth()->user()->isAdministrator())
                <a class="topbar-link" href="{{ route('admin.dashboard') }}">Administración</a>
            @else
                <a class="topbar-link" href="{{ route('history') }}">Mi historial</a>
            @endif
            <a class="topbar-link" href="#account">Mi cuenta</a>
        </div>
    @endauth
</header>
@if (auth()->check() && auth()->user()->isAdministrator() && request()->routeIs('admin.*'))
    <div class="admin-shell">
        <aside class="admin-sidebar" aria-label="Navegación administrativa">
            <p class="sidebar-heading">Administración</p>
            <nav class="sidebar-nav">
                <a href="#employees"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"></circle><path d="M3.5 20c.5-3.5 2.4-5.5 5.5-5.5s5 2 5.5 5.5M16 5a3.5 3.5 0 0 1 0 6.8M17 14.5c2.1.6 3.3 2.4 3.5 5.5"></path></svg></span>Trabajadores</a>
                <a href="#schedules"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="1"></rect><path d="M7.5 3.5v3M16.5 3.5v3M3.5 9h17M8 12.5h2M13 12.5h3M8 16h2"></path></svg></span>Jornadas</a>
                <a href="#reports"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V11h4v9M10 20V5h4v15M16 20v-7h4v7M2.5 20.5h19"></path></svg></span>Reportes</a>
                <a href="#attendance-records"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5h12M8 10h12M8 15h12M8 20h12"></path><path d="m3 5 1 1 2-2M3 10l1 1 2-2M3 15l1 1 2-2M3 20l1 1 2-2"></path></svg></span>Registros</a>
                <a href="#incidents"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3.5 22 20H2L12 3.5Z"></path><path d="M12 9v5M12 17.5h.01"></path></svg></span>Incidencias</a>
                <a href="#corrections"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 4H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-9"></path><path d="m14 14 6.5-6.5a2.1 2.1 0 0 0-3-3L11 11l-1 4 4-1Z"></path></svg></span>Correcciones</a>
                <a href="#account"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 21c.6-4.1 3.1-6.3 7.5-6.3s6.9 2.2 7.5 6.3"></path></svg></span>Cuenta</a>
            </nav>
        </aside>
        <main class="page page-admin" id="main-content">
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
            @include('layouts.account')
        </main>
    </div>
@else
    <main class="page" id="main-content">
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
        @auth
            @include('layouts.account')
        @endauth
    </main>
    @auth
        @if (auth()->user()->role === 'worker')
            <nav class="worker-nav" aria-label="Navegación principal">
                <a href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif>
                    <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7v5l3 2"></path></svg></span><span>Marcación</span>
                </a>
                <a href="{{ route('history') }}" @if (request()->routeIs('history', 'attendance-events.show')) aria-current="page" @endif>
                    <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 6h11M9 12h11M9 18h11"></path><path d="M4 6h.01M4 12h.01M4 18h.01"></path></svg></span><span>Historial</span>
                </a>
                <a href="#account">
                    <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 21c.6-4.1 3.1-6.3 7.5-6.3s6.9 2.2 7.5 6.3"></path></svg></span><span>Perfil</span>
                </a>
            </nav>
        @endif
    @endauth
@endif
<footer class="footer">MVP técnico. Antes de reemplazar el registro vigente, valida los requisitos aplicables con RR. HH. y la Dirección del Trabajo.</footer>
</body>
</html>
