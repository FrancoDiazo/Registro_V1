<section class="account-panel" id="account" aria-labelledby="account-heading">
    <div>
        <p class="eyebrow">Cuenta</p>
        <h2 id="account-heading">{{ auth()->user()->name }}</h2>
        <p class="muted">{{ auth()->user()->email }}</p>
    </div>
    <form method="post" action="{{ route('logout') }}">
        @csrf
        <button class="button secondary" type="submit">Cerrar sesión</button>
    </form>
</section>
