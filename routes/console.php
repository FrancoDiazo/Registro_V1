<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

Artisan::command('about', function (): void {
    $this->comment('MVP técnico de registro de asistencia; no es un sistema oficial autorizado.');
})->purpose('Mostrar información de la aplicación');

Artisan::command('attendance:make-administrator', function (): int {
    $name = $this->ask('Nombre de la persona administradora');
    $email = strtolower((string) $this->ask('Correo electrónico'));
    $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)');
    $validator = Validator::make(compact('name', 'email', 'password'), [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'min:12', 'max:255'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }

    User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'role' => 'administrator',
    ]);
    $this->info('Cuenta administradora creada.');

    return self::SUCCESS;
})->purpose('Crear la primera cuenta administradora de forma interactiva');
