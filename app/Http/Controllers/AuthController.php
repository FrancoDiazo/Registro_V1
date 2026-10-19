<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\ValidChileanRut;
use App\Support\ChileanRut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rut' => ['required', 'string', 'max:12', new ValidChileanRut()],
            'password' => ['required', 'string'],
        ]);
        $normalizedIdentifier = ChileanRut::compact($data['rut']);
        $key = $normalizedIdentifier.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'rut' => 'Demasiados intentos. Espera un momento antes de volver a intentarlo.',
            ]);
        }

        $user = User::query()->where('rut', ChileanRut::normalize($data['rut']))->first();

        if (! $user || ! Auth::attempt([
            'email' => $user->email,
            'password' => $data['password'],
            'is_active' => true,
        ], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['rut' => 'Las credenciales no son válidas.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        $destination = $request->user()->isAdministrator() ? route('admin.dashboard') : route('dashboard');

        return redirect()->intended($destination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
