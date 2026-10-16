<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdministratorPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_an_administrator_password_without_outputting_it(): void
    {
        $administrator = User::factory()->administrator()->create([
            'password' => 'Old-Password-123!',
            'remember_token' => 'old-remember-token',
        ]);

        $this->artisan('attendance:change-administrator-password')
            ->expectsQuestion('Correo de la cuenta administradora', $administrator->email)
            ->expectsQuestion('Nueva contraseña (mínimo 12 caracteres)', 'New-Admin-Password-456!')
            ->expectsQuestion('Confirma la nueva contraseña', 'New-Admin-Password-456!')
            ->expectsOutput('Contraseña actualizada. Las sesiones ya iniciadas no se cerraron automáticamente.')
            ->doesntExpectOutput('New-Admin-Password-456!')
            ->assertExitCode(0);

        $administrator->refresh();
        $this->assertTrue(Hash::check('New-Admin-Password-456!', $administrator->password));
        $this->assertNotSame('New-Admin-Password-456!', $administrator->password);
        $this->assertNull($administrator->remember_token);
    }

    public function test_command_rejects_non_administrator_accounts(): void
    {
        $worker = User::factory()->create();

        $this->artisan('attendance:change-administrator-password')
            ->expectsQuestion('Correo de la cuenta administradora', $worker->email)
            ->expectsQuestion('Nueva contraseña (mínimo 12 caracteres)', 'New-Admin-Password-456!')
            ->expectsQuestion('Confirma la nueva contraseña', 'New-Admin-Password-456!')
            ->expectsOutput('No se encontró una cuenta administradora con ese correo.')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('Password-Only-For-Tests-123!', $worker->fresh()->password));
    }

    public function test_command_rejects_short_or_mismatched_passwords(): void
    {
        $administrator = User::factory()->administrator()->create();
        $originalHash = $administrator->password;

        $this->artisan('attendance:change-administrator-password')
            ->expectsQuestion('Correo de la cuenta administradora', $administrator->email)
            ->expectsQuestion('Nueva contraseña (mínimo 12 caracteres)', 'short')
            ->expectsQuestion('Confirma la nueva contraseña', 'different')
            ->assertExitCode(1);

        $this->assertSame($originalHash, $administrator->fresh()->password);
    }
}
