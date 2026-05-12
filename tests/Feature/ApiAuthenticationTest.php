<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_logout_and_fetch_profile(): void
    {
        $register = $this->postJson('/api/central/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '+8801722892459',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $register->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Registration successful. Please verify your email with the OTP sent.')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.phone', '+8801722892459')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('email_verified', false);

        $login = $this->postJson('/api/central/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123!',
        ]);

        $login->assertOk()
            ->assertJsonPath('user.email', 'jane@example.com');

        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/central/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'jane@example.com');

        $this->withToken($token)
            ->postJson('/api/central/logout')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Logged out successfully');
    }

    public function test_central_web_registration_route_is_registered(): void
    {
        $this->assertTrue(Route::has('central.register'));

        $route = app('router')->getRoutes()->match(Request::create('/central/register', 'POST'));

        $this->assertSame('central.register', $route->getName());
        $this->assertSame('App\\Http\\Controllers\\Api\\AuthController@register', $route->getActionName());
    }

    public function test_central_web_registration_returns_json_validation_errors_instead_of_redirecting(): void
    {
        $response = $this->post('/central/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => '+1-555-123-4567',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('errors.password.0', 'Password must include at least one uppercase letter, one lowercase letter, one number, and one special character.');
    }
}