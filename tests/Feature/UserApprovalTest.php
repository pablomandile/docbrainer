<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class UserApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email): User
    {
        $this->post(route('register.store'), [
            'name' => 'Nuevo',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        // Fortify guarda el email en minúsculas
        return User::where('email', strtolower($email))->firstOrFail();
    }

    public function test_new_users_register_as_inactive_users()
    {
        $this->skipUnlessFortifyFeature(Features::registration());

        $user = $this->register('nuevo@example.com');

        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->is_active);
    }

    public function test_the_configured_admin_email_registers_as_active_admin()
    {
        $this->skipUnlessFortifyFeature(Features::registration());
        config(['docbrainer.admin_email' => 'jefe@example.com']);

        $user = $this->register('Jefe@Example.com');

        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_registration_ignores_role_and_active_from_the_form()
    {
        $this->skipUnlessFortifyFeature(Features::registration());

        $this->post(route('register.store'), [
            'name' => 'Pícaro',
            'email' => 'picaro@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $user = User::where('email', 'picaro@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->is_active);
    }

    public function test_inactive_users_are_sent_to_the_pending_page()
    {
        $user = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('projects.index'))
            ->assertRedirect(route('pending-approval'));
        $this->actingAs($user)->get(route('ai-settings.edit'))
            ->assertRedirect(route('pending-approval'));
        $this->actingAs($user)->get(route('pending-approval'))
            ->assertOk();
    }

    public function test_active_users_skip_the_pending_page()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.index'))->assertOk();
        $this->actingAs($user)->get(route('pending-approval'))
            ->assertRedirect(route('projects.index'));
    }

    public function test_only_admins_can_manage_users()
    {
        $user = User::factory()->create();
        $other = User::factory()->inactive()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)
            ->patch(route('admin.users.update', $other), ['is_active' => true])
            ->assertForbidden();

        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_admin_can_activate_a_user_and_change_its_role()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->inactive()->create();

        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), ['is_active' => true])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), ['role' => 'admin'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->isAdmin());
    }

    public function test_admin_cannot_lock_themselves_out()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), ['role' => 'user'])
            ->assertSessionHasErrors('user');
        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), ['is_active' => false])
            ->assertSessionHasErrors('user');

        $admin->refresh();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->is_active);
    }

    public function test_invalid_roles_are_rejected()
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), ['role' => 'superadmin'])
            ->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_USER, $user->fresh()->role);
    }
}
