<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_activity_logs(): void
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

        // Fetch activity logs
        $response = $this->withToken($token)
            ->getJson('/api/central/activity-logs');

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data',
                'meta' => [
                    'total_records',
                    'per_page',
                    'current_page',
                    'total_pages',
                    'has_more_pages',
                ],
            ])
            ->assertJsonPath('status', true);

        // Should have at least the registration log
            $this->assertGreaterThan(0, $response->json('meta.total_records'));
    }

    public function test_unauthenticated_user_cannot_fetch_activity_logs(): void
    {
        $this->getJson('/api/central/activity-logs')
            ->assertUnauthorized();
    }

    public function test_activity_logs_can_be_filtered_by_action(): void
    {
        // Register a user
        $response = $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $token = $response->json('token');

        // Fetch logs filtered by action
        $response = $this->withToken($token)
            ->getJson('/api/central/activity-logs?action=register');

        $response->assertOk()
            ->assertJsonPath('status', true);

        $logs = $response->json('data');
        $this->assertTrue(collect($logs)->every(fn ($log) => $log['action'] === 'register'));
    }

    public function test_activity_logs_pagination(): void
    {
        // Register a user
        $response = $this->postJson('/api/central/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone' => '+1234567890',
            'role' => 'admin',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $token = $response->json('token');

        // Fetch logs with custom limit
        $response = $this->withToken($token)
            ->getJson('/api/central/activity-logs?limit=5&page=1');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 5);
    }
}
