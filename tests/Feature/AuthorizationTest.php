<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_registers_exception_handler_and_renders_login(): void
    {
        $this->assertInstanceOf(ExceptionHandler::class, app(ExceptionHandler::class));
        $this->get('/login')->assertOk()->assertSee('RUT')->assertDontSee('Correo electrónico o RUT');
        $this->get('/missing-page')->assertNotFound();
    }

    public function test_root_redirects_guests_to_login_and_signed_in_users_to_their_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');

        $worker = User::factory()->create();
        Employee::factory()->create(['user_id' => $worker->id]);
        $this->actingAs($worker)->get('/')->assertRedirect('/dashboard');

        $administrator = User::factory()->administrator()->create();
        $this->actingAs($administrator)->get('/')->assertRedirect('/admin');
    }

    public function test_workers_are_forbidden_from_administration_and_administrators_are_allowed(): void
    {
        $worker = User::factory()->create();
        Employee::factory()->create(['user_id' => $worker->id]);
        $this->actingAs($worker)->get('/admin')->assertForbidden();

        $administrator = User::factory()->administrator()->create();
        $this->actingAs($administrator)->get('/admin')->assertOk();
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create([
            'password' => 'Valid-Password-123!',
            'rut' => '12345678-5',
        ]);
        Employee::factory()->create(['user_id' => $user->id, 'employee_number' => '12345678-5']);

        $this->post('/login', ['rut' => '12.345.678-5', 'password' => 'Valid-Password-123!'])
            ->assertSessionHasErrors('rut');
        $this->assertGuest();
    }

    public function test_active_worker_cannot_log_in_using_email(): void
    {
        $user = User::factory()->create([
            'password' => 'Valid-Password-123!',
            'rut' => '12345678-5',
        ]);
        Employee::factory()->create(['user_id' => $user->id, 'employee_number' => '12345678-5']);

        $this->post('/login', ['rut' => $user->email, 'password' => 'Valid-Password-123!'])
            ->assertSessionHasErrors('rut');

        $this->assertGuest();
    }

    public function test_administrator_can_log_in_using_rut(): void
    {
        $administrator = User::factory()->administrator()->create([
            'password' => 'Valid-Password-123!',
            'rut' => '12345678-5',
        ]);

        $this->post('/login', ['rut' => '12.345.678-5', 'password' => 'Valid-Password-123!'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($administrator);
    }

    public function test_administrator_cannot_log_in_by_email_without_rut(): void
    {
        $administrator = User::factory()->administrator()->create(['password' => 'Valid-Password-123!']);

        $this->post('/login', ['rut' => $administrator->email, 'password' => 'Valid-Password-123!'])
            ->assertSessionHasErrors('rut');

        $this->assertGuest();
    }

    public function test_active_worker_can_log_in_using_rut_with_optional_separators(): void
    {
        $user = User::factory()->create([
            'password' => 'Valid-Password-123!',
            'rut' => '12345678-5',
        ]);
        Employee::factory()->create(['user_id' => $user->id, 'employee_number' => '12.345.678-5']);

        $this->post('/login', ['rut' => '123456785', 'password' => 'Valid-Password-123!'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_worker_login_uses_only_the_account_rut_not_the_employee_number_field(): void
    {
        $user = User::factory()->create([
            'password' => 'Valid-Password-123!',
            'rut' => '12345678-5',
        ]);
        Employee::factory()->create(['user_id' => $user->id, 'employee_number' => '11111111-1']);

        $this->post('/login', ['rut' => '11.111.111-1', 'password' => 'Valid-Password-123!'])
            ->assertSessionHasErrors('rut');
        $this->assertGuest();

        $this->post('/login', ['rut' => '12.345.678-5', 'password' => 'Valid-Password-123!'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_rut_login_uses_the_generic_credential_error(): void
    {
        $this->post('/login', ['rut' => '12.345.678-4', 'password' => 'Valid-Password-123!'])
            ->assertSessionHasErrors('rut');

        $this->assertGuest();
    }

    public function test_administrator_can_create_a_worker_with_a_normalized_valid_rut(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->actingAs($administrator)->post('/admin/employees', [
            'name' => 'Trabajador de prueba',
            'email' => 'trabajador-prueba@example.test',
            'employee_number' => '12.345.678-5',
            'password' => 'Initial-Password-123!',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('trabajadores', ['employee_number' => '12345678-5']);
        $this->assertDatabaseHas('usuarios', ['email' => 'trabajador-prueba@example.test', 'rut' => '12345678-5']);
    }

    public function test_worker_creation_rejects_invalid_or_duplicate_ruts(): void
    {
        $administrator = User::factory()->administrator()->create();
        $existingUser = User::factory()->create(['rut' => '12345678-5']);
        Employee::factory()->create(['user_id' => $existingUser->id, 'employee_number' => '12.345.678-5']);

        $this->actingAs($administrator)->post('/admin/employees', [
            'name' => 'Otro trabajador',
            'email' => 'otro-trabajador@example.test',
            'employee_number' => '12.345.678-4',
            'password' => 'Initial-Password-123!',
        ])->assertSessionHasErrors('employee_number');

        $this->post('/admin/employees', [
            'name' => 'Trabajador duplicado',
            'email' => 'duplicado@example.test',
            'employee_number' => '12.345.678-5',
            'password' => 'Initial-Password-123!',
        ])->assertSessionHasErrors('employee_number');
    }

    public function test_worker_creation_rejects_a_rut_already_assigned_to_an_administrator(): void
    {
        $administrator = User::factory()->administrator()->create(['rut' => '12345678-5']);

        $this->actingAs($administrator)->post('/admin/employees', [
            'name' => 'Trabajador duplicado',
            'email' => 'rut-duplicado@example.test',
            'employee_number' => '12.345.678-5',
            'password' => 'Initial-Password-123!',
        ])->assertSessionHasErrors('employee_number');
    }

    public function test_administrator_rut_assignment_command_saves_a_valid_rut(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->artisan('attendance:assign-administrator-rut')
            ->expectsQuestion('Correo de la cuenta administradora', $administrator->email)
            ->expectsQuestion('RUT de la persona administradora', '12.345.678-5')
            ->expectsOutput('RUT asociado a la cuenta administradora.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('usuarios', ['id' => $administrator->id, 'rut' => '12345678-5']);
    }

    public function test_administrator_rut_assignment_command_rejects_invalid_rut(): void
    {
        $administrator = User::factory()->administrator()->create();

        $this->artisan('attendance:assign-administrator-rut')
            ->expectsQuestion('Correo de la cuenta administradora', $administrator->email)
            ->expectsQuestion('RUT de la persona administradora', '12.345.678-4')
            ->expectsOutput('Ingresa un RUT válido, incluyendo su dígito verificador.')
            ->assertExitCode(1);

        $this->assertDatabaseHas('usuarios', ['id' => $administrator->id, 'rut' => null]);
    }

    public function test_administrator_rut_assignment_command_rejects_rut_used_by_another_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->create(['rut' => '12345678-5']);

        $this->artisan('attendance:assign-administrator-rut')
            ->expectsQuestion('Correo de la cuenta administradora', $administrator->email)
            ->expectsQuestion('RUT de la persona administradora', '12345678-5')
            ->assertExitCode(1);

        $this->assertDatabaseHas('usuarios', ['id' => $administrator->id, 'rut' => null]);
    }

    public function test_administrator_rut_assignment_command_rejects_a_legacy_employee_number_collision(): void
    {
        $administrator = User::factory()->administrator()->create();
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => '12.345.678-5']);

        $this->artisan('attendance:assign-administrator-rut')
            ->expectsQuestion('Correo de la cuenta administradora', $administrator->email)
            ->expectsQuestion('RUT de la persona administradora', '123456785')
            ->assertExitCode(1);

        $this->assertDatabaseHas('usuarios', ['id' => $administrator->id, 'rut' => null]);
    }

    public function test_administrator_rut_assignment_command_only_targets_administrators(): void
    {
        $worker = User::factory()->create();

        $this->artisan('attendance:assign-administrator-rut')
            ->expectsQuestion('Correo de la cuenta administradora', $worker->email)
            ->expectsOutput('No se encontró una cuenta administradora con ese correo.')
            ->assertExitCode(1);
    }

    public function test_backfill_copies_normalized_employee_ruts_to_user_accounts(): void
    {
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => '12.345.678-5']);

        $migration = require database_path('migrations/2026_10_19_000002_backfill_unique_user_ruts.php');
        $migration->up();

        $this->assertDatabaseHas('usuarios', ['id' => $worker->id, 'rut' => '12345678-5']);
    }

    public function test_backfill_aborts_without_writing_when_an_employee_identifier_is_not_a_valid_rut(): void
    {
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => 'LEGACY-123']);

        try {
            $migration = require database_path('migrations/2026_10_19_000002_backfill_unique_user_ruts.php');
            $migration->up();
            $this->fail('La migración debía rechazar un identificador laboral que no es un RUT válido.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no contiene un RUT válido', $exception->getMessage());
        }

        $this->assertDatabaseHas('usuarios', ['id' => $worker->id, 'rut' => null]);
    }

    public function test_backfill_aborts_when_two_employee_profiles_have_the_same_normalized_rut(): void
    {
        $first = User::factory()->create(['rut' => null]);
        $second = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $first->id, 'employee_number' => '12.345.678-5']);
        Employee::factory()->create(['user_id' => $second->id, 'employee_number' => '12345678-5']);

        try {
            $migration = require database_path('migrations/2026_10_19_000002_backfill_unique_user_ruts.php');
            $migration->up();
            $this->fail('La migración debía rechazar los RUT duplicados.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Hay RUT duplicados', $exception->getMessage());
        }

        $this->assertDatabaseHas('usuarios', ['id' => $first->id, 'rut' => null]);
        $this->assertDatabaseHas('usuarios', ['id' => $second->id, 'rut' => null]);
    }

    public function test_backfill_aborts_when_administrator_rut_conflicts_with_a_worker(): void
    {
        $administrator = User::factory()->administrator()->create(['rut' => '12345678-5']);
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => '12345678-5']);

        try {
            $migration = require database_path('migrations/2026_10_19_000002_backfill_unique_user_ruts.php');
            $migration->up();
            $this->fail('La migración debía rechazar una colisión entre administrador y trabajador.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Hay RUT duplicados', $exception->getMessage());
        }

        $this->assertDatabaseHas('usuarios', ['id' => $worker->id, 'rut' => null]);
    }

    public function test_backfill_can_be_reversed_without_clearing_administrator_ruts(): void
    {
        $administrator = User::factory()->administrator()->create(['rut' => '11111111-1']);
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => '12.345.678-5']);
        $migration = require database_path('migrations/2026_10_19_000002_backfill_unique_user_ruts.php');

        $migration->up();
        $migration->down();

        $this->assertDatabaseHas('usuarios', ['id' => $worker->id, 'rut' => null]);
        $this->assertDatabaseHas('usuarios', ['id' => $administrator->id, 'rut' => '11111111-1']);
    }

    public function test_rut_preflight_command_is_read_only_and_accepts_valid_existing_employee_ruts(): void
    {
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => '12.345.678-5']);

        $this->artisan('attendance:validate-user-ruts')
            ->expectsOutput('Validación correcta: 1 RUT de cuenta válidos y sin duplicados. No se modificaron datos.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('usuarios', ['id' => $worker->id, 'rut' => null]);
    }

    public function test_rut_preflight_command_fails_before_mutating_invalid_existing_data(): void
    {
        $worker = User::factory()->create(['rut' => null]);
        Employee::factory()->create(['user_id' => $worker->id, 'employee_number' => 'LEGACY-123']);

        $this->artisan('attendance:validate-user-ruts')
            ->assertExitCode(1);

        $this->assertDatabaseHas('usuarios', ['id' => $worker->id, 'rut' => null]);
    }

    public function test_rut_preflight_requires_all_administrator_accounts_to_have_a_rut(): void
    {
        User::factory()->administrator()->create(['rut' => null]);

        $this->artisan('attendance:validate-user-ruts')
            ->assertExitCode(1);
    }

    public function test_rut_preflight_rejects_worker_accounts_without_employee_profiles(): void
    {
        User::factory()->create(['rut' => '12345678-5']);

        $this->artisan('attendance:validate-user-ruts')
            ->assertExitCode(1);
    }

    public function test_worker_dashboard_requires_an_employee_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }
}
