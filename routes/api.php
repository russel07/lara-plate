<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LicenseActivationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RbacController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\MediaController;
use Illuminate\Support\Facades\Route;

// ==========================================
// CENTRAL ROUTES
// ==========================================
Route::post('/central/register', [AuthController::class, 'register']);
Route::post('/central/login', [AuthController::class, 'login']);
Route::post('/central/google/login', [AuthController::class, 'googleLogin']);
Route::post('/central/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/central/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/central/verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('/central/resend-otp', [AuthController::class, 'resendOtp']);
    Route::get('/central/me', [AuthController::class, 'me']);
    Route::post('/central/logout', [AuthController::class, 'logout']);
    Route::get('/central/activity-logs', [ActivityLogController::class, 'index']);
});

// Organization Management (Super Admin and Admin)
Route::middleware(['auth:sanctum', 'token.expired', 'audit'])->group(function () {
    Route::post('/central/organizations', [OrganizationController::class, 'store']);
    Route::put('/central/organizations/{id}', [OrganizationController::class, 'update']);
    Route::delete('/central/organizations/{id}', [OrganizationController::class, 'destroy']);
    Route::post('/central/verify-slug', [OrganizationController::class, 'verifySlug']);
});

Route::middleware(['auth:sanctum', 'permission:manage_roles'])->prefix('rbac')->group(function () {
    Route::get('roles', [RbacController::class, 'roles']);
    Route::get('permissions', [RbacController::class, 'permissions']);
    Route::post('roles/{role}/permissions', [RbacController::class, 'syncRolePermissions']);
});

Route::middleware(['auth:sanctum', 'permission:manage_users'])->prefix('rbac')->group(function () {
    Route::post('users/{user}/roles', [RbacController::class, 'syncUserRoles']);
});

// These routes DO use the 'tenant' middleware to identify the organization.
Route::group([
    'middleware' => ['tenant'],
], function () {
    // Public Tenant Routes
    Route::get('/', [ OrganizationController::class, 'index']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/google/login', [AuthController::class, 'googleLogin']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/activate-license', LicenseActivationController::class);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware(['auth:sanctum', 'token.expired', 'license', 'audit'])->group(function () {  
        Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile-settings', [AuthController::class, 'updateProfileSettings']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Media Management
        Route::middleware(['permission:manage_media'])->group(function () {
            Route::post('/media/upload', [MediaController::class, 'upload']);
            Route::get('/media', [MediaController::class, 'index']);
            Route::get('/media/{id}', [MediaController::class, 'show']);
            Route::put('/media/{id}', [MediaController::class, 'update']);
            Route::delete('/media/{id}', [MediaController::class, 'destroy']);
        });

        // File serve (accessible by authenticated users)
        Route::get('/file/{hash}', [MediaController::class, 'serve']);
     });

     // Organization Management (Super Admin and Admin)
    Route::middleware(['auth:sanctum', 'token.expired', 'audit', 'role:admin,superadmin'])->group(function () {
        // Add tenant-specific routes here
    });
});