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
        $this->get('/login')->assertOk();
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
        $user = User::factory()->inactive()->create(['password' => 'Valid-Password-123!']);

        $this->post('/login', ['email' => $user->email, 'password' => 'Valid-Password-123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_active_worker_can_log_in_and_reaches_the_attendance_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'Valid-Password-123!']);
        Employee::factory()->create(['user_id' => $user->id]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Valid-Password-123!'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_worker_dashboard_requires_an_employee_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertForbidden();
    }
}
