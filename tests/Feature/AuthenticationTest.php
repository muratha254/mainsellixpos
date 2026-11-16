<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Create account');
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_a_second_person_can_sign_in_while_someone_else_is_already_signed_in(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first);

        $this->get('/login')->assertOk()->assertSee($first->name);

        $response = $this->post('/login', [
            'email' => $second->email,
            'password' => 'password',
        ]);
        $response->assertSessionHasNoErrors();
        $response->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticatedAs($second);
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }
}
