<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_request_uses_the_same_response_for_unknown_and_unverified_addresses(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'verified@example.test']);
        $user->forceFill(['email_verified_at' => null])->save();

        $unverifiedResponse = $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');
        $unknownResponse = $this->post('/forgot-password', ['email' => 'unknown@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            $unverifiedResponse->getSession()->get('status'),
            $unknownResponse->getSession()->get('status'),
        );
        Notification::assertNothingSent();
    }

    public function test_password_reset_is_sent_only_to_the_verified_account_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'verified@example.test']);
        $otherUser = User::factory()->create(['email' => 'other@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertNotSentTo($otherUser, ResetPassword::class);
    }

    public function test_password_reset_token_expires_and_can_only_be_used_once(): void
    {
        $user = User::factory()->create(['email' => 'worker@example.test']);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-Password-123!',
            'password_confirmation' => 'New-Password-123!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-Password-123!', $user->fresh()->password));
        $this->assertDatabaseMissing('tokens_restablecimiento_clave', ['email' => $user->email]);

        $this->from('/reset-password/'.$token.'?email='.urlencode($user->email))
            ->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => 'Another-Password-123!',
                'password_confirmation' => 'Another-Password-123!',
            ])->assertRedirect('/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('New-Password-123!', $user->fresh()->password));
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'worker@example.test']);
        $token = Password::createToken($user);

        $this->travel(61)->minutes();
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'New-Password-123!',
            'password_confirmation' => 'New-Password-123!',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('New-Password-123!', $user->fresh()->password));
    }
}
