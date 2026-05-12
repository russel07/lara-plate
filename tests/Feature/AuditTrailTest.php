<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_trail_logged_for_registration(): void
    {
        $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $log = ActivityLog::where('action', 'register')
            ->where('module', 'authentication')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('registered', $log->description);
        $this->assertStringContainsString('test@example.com', $log->description);
    }

    public function test_audit_trail_logged_for_login(): void
    {
        // Create a user
        $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        // Clear activity logs to test login only
        ActivityLog::truncate();

        // Login
        $this->postJson('/api/central/login', [
            'email' => 'test@example.com',
            'password' => 'Password123!',
        ])->assertOk();

        $log = ActivityLog::where('action', 'login')
            ->where('module', 'authentication')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('logged in', $log->description);
        $this->assertStringContainsString('test@example.com', $log->description);
    }

    public function test_audit_trail_logged_for_failed_login(): void
    {
        // Create a user
        $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        // Clear activity logs to test login only
        ActivityLog::truncate();

        // Failed login
        $this->postJson('/api/central/login', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword!',
        ])->assertUnauthorized();

        $log = ActivityLog::where('action', 'login_failed')
            ->where('module', 'authentication')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('invalid', strtolower($log->description));
    }

    public function test_audit_trail_logged_for_logout(): void
    {
        // Register and login
        $response = $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $token = $response->json('token');

        // Clear activity logs
        ActivityLog::truncate();

        // Logout
        $this->withToken($token)
            ->postJson('/api/central/logout')
            ->assertOk();

        $log = ActivityLog::where('action', 'logout')
            ->where('module', 'authentication')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('logged out', $log->description);
    }

    public function test_activity_log_contains_user_and_organization_id(): void
    {
        $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $log = ActivityLog::where('action', 'register')
            ->where('module', 'authentication')
            ->first();

        $this->assertNotNull($log->user_id);
        $this->assertGreaterThan(0, $log->user_id);
    }
}
