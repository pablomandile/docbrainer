<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function withGoogleCredentials(): void
    {
        config([
            'services.google.client_id' => 'id-de-prueba.apps.googleusercontent.com',
            'services.google.client_secret' => 'secreto-de-prueba',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
        ]);
    }

    /**
     * Lo que devolvería Socialite, con el flag de verificado en el payload
     * crudo, que es donde Google lo manda.
     */
    private function googleReturns(string $id, string $email, bool $verified = true): void
    {
        $account = (new SocialiteUser)
            ->map(['id' => $id, 'email' => $email, 'name' => 'Ana Pérez', 'avatar' => 'https://foto/ana.jpg'])
            ->setRaw(['email_verified' => $verified]);

        Socialite::shouldReceive('driver')->with('google')->andReturn(
            Mockery::mock()->shouldReceive('user')->andReturn($account)->getMock(),
        );
    }

    public function test_routes_are_not_found_without_credentials()
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(route('google.redirect'))->assertNotFound();
        $this->get(route('google.callback'))->assertNotFound();
        $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleLoginEnabled', false));
    }

    public function test_the_button_is_offered_with_credentials()
    {
        $this->withGoogleCredentials();

        $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleLoginEnabled', true));
        $this->get(route('google.redirect'))->assertRedirectContains('accounts.google.com');
    }

    public function test_a_new_google_account_is_created_pending_activation()
    {
        $this->withGoogleCredentials();
        $this->googleReturns('g-1', 'Ana@Example.com');

        $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-1', $user->google_id);
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->is_active);
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);

        $this->get(route('projects.index'))->assertRedirect(route('pending-approval'));
    }

    public function test_the_admin_email_enters_as_active_admin()
    {
        $this->withGoogleCredentials();
        config(['docbrainer.admin_email' => 'jefe@example.com']);
        $this->googleReturns('g-admin', 'jefe@example.com');

        $this->get(route('google.callback'));

        $user = User::where('email', 'jefe@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->is_active);
    }

    public function test_an_existing_account_is_linked_by_email()
    {
        $this->withGoogleCredentials();
        $existing = User::factory()->create(['email' => 'ana@example.com']);
        $this->googleReturns('g-1', 'ana@example.com');

        $this->get(route('google.callback'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('g-1', $existing->fresh()->google_id);
        $this->assertSame(1, User::count());
    }

    public function test_unverified_google_emails_are_rejected()
    {
        $this->withGoogleCredentials();
        User::factory()->create(['email' => 'ana@example.com']);
        $this->googleReturns('g-intruso', 'ana@example.com', verified: false);

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_new_accounts_are_rejected_when_registration_is_closed()
    {
        $this->withGoogleCredentials();
        config(['fortify.features' => array_values(array_filter(
            config('fortify.features'),
            fn ($feature) => $feature !== 'registration',
        ))]);
        $this->googleReturns('g-1', 'ana@example.com');

        $this->get(route('google.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_users_with_two_factor_still_need_the_code()
    {
        $this->withGoogleCredentials();
        $user = User::factory()->withTwoFactor()->create(['email' => 'ana@example.com']);
        $this->googleReturns('g-1', 'ana@example.com');

        $this->get(route('google.callback'))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);

        $this->assertGuest();
    }

    public function test_cancelling_in_google_goes_back_to_login_without_error()
    {
        $this->withGoogleCredentials();

        $this->get(route('google.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('error');
    }

    public function test_google_accounts_can_create_a_password_without_a_current_one()
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)
            ->from(route('user-password.edit'))
            ->put(route('user-password.update'), [
                'password' => 'nueva-clave-segura',
                'password_confirmation' => 'nueva-clave-segura',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->password);
    }

    public function test_google_accounts_can_be_deleted_without_a_password()
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)
            ->delete(route('profile.destroy'))
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh());
    }
}
