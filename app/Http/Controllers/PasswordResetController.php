<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && $user->email_verified_at) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (TransportExceptionInterface) {
                Log::warning('Password reset email delivery failed.');
            }
        }

        return back()->with(
            'status',
            'Si la cuenta existe y tiene un correo verificado, enviaremos instrucciones. Si no las recibes, contacta a administración para revisar el correo asociado.',
        );
    }

    public function edit(string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => request()->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'La contraseña fue restablecida. Ya puedes iniciar sesión.');
        }

        return back()->withErrors([
            'email' => 'El enlace no es válido o expiró. Solicita uno nuevo.',
        ]);
    }
}
