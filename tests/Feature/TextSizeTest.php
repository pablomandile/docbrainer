<?php

namespace Tests\Feature;

use App\Enums\TextSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_default_is_the_middle_option()
    {
        $this->assertSame(TextSize::Normal, TextSize::default());
        $this->assertSame(
            [TextSize::Small, TextSize::Normal, TextSize::Large],
            TextSize::cases(),
        );
    }

    public function test_guests_get_the_default_written_in_the_html()
    {
        $this->get(route('login'))->assertSee('data-text="normal"', false);
    }

    public function test_new_users_default_to_normal()
    {
        $user = User::factory()->create();

        $this->assertSame(TextSize::Normal, $user->fresh()->text_size);
    }

    public function test_guests_can_pick_a_size_and_it_lives_in_a_cookie()
    {
        $this->put(route('text-size.update'), ['text_size' => 'large'])
            ->assertCookie('text_size', 'large', encrypted: false);

        $this->withUnencryptedCookie('text_size', 'large')
            ->get(route('login'))
            ->assertSee('data-text="large"', false);
    }

    public function test_the_choice_is_saved_in_the_account_and_wins_over_the_cookie()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('text-size.update'), ['text_size' => 'small'])
            ->assertSessionHasNoErrors();

        $this->assertSame(TextSize::Small, $user->fresh()->text_size);

        // Una cookie vieja de otro valor no le gana a la cuenta
        $this->actingAs($user->fresh())
            ->withUnencryptedCookie('text_size', 'large')
            ->get(route('projects.index'))
            ->assertSee('data-text="small"', false)
            ->assertInertia(fn ($page) => $page->where('textSize', 'small'));
    }

    public function test_invalid_sizes_are_rejected()
    {
        $this->put(route('text-size.update'), ['text_size' => 'gigante'])
            ->assertSessionHasErrors('text_size');
    }

    public function test_a_garbage_cookie_falls_back_to_the_default()
    {
        $this->withUnencryptedCookie('text_size', 'enorme')
            ->get(route('login'))
            ->assertSee('data-text="normal"', false);
    }
}
