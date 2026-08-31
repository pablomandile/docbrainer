<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_home()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_users_are_redirected_to_projects_from_home()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect('/projects');
    }
}
