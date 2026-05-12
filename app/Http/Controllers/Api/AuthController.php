<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\GoogleAuthRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Services\OtpService;
use App\Jobs\SendOtpEmailJob;
use App\Jobs\SendPasswordResetEmailJob;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Resolve the current organization from the app container or fallback to the user's organization.
     */
    private function resolveOrganization(?User $user = null): ?\App\Models\Organization
    {
        if (app()->has('currentOrganization')) {
            return app('currentOrganization');
        }

        if ($user && $user->organization_id) {
            $organization = $user->organization;

            // Keep container state consistent so app()->has('currentOrganization') is true afterwards.
            if ($organization) {
                app()->instance('currentOrganization', $organization);
            }

            return $organization;
        }

        return null;
    }

    public function register(RegisterUserRequest $request)
    {
        try {
            $validated = $request->validated();
            $otp = str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT);
            
            $userData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'] ?? null,
                'role' => $validated['role'] ?? 'admin',
                'is_admin' => ! app()->has('currentOrganization'),
                'job_title' => $validated['job_title'] ?? null,
            ];

            // If in a tenant context, assign user to the organization
            if (app()->has('currentOrganization')) {
                $userData['organization_id'] = app('currentOrganization')->id;
            }

            $user = User::create($userData);

            // Send OTP email
            try {
                $otpService = new OtpService();
                $otp = $otpService->generate($user);
                // Send OTP email
                SendOtpEmailJob::dispatch($user, $otp);
            } catch (\Exception $e) {
                // Log the error but don't fail registration
                \Log::warning('OTP email failed for user: ' . $user->email, ['error' => $e->getMessage()]);
            }

            $tokenResult = $user->createToken('api_token');
            $tokenResult->accessToken->expires_at = now()->addDays(7);
            $tokenResult->accessToken->save();
            $token = $tokenResult->plainTextToken;

            ActivityLogger::log([
                'action' => 'register',
                'module' => 'authentication',
                'description' => "User {$user->name} ({$user->email}) registered",
                'properties' => ['user_id' => $user->id, 'email' => $user->email],
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Registration successful. Please verify your email with the OTP sent.',
                'token' => $token,
                'user' => $this->formatUser($user),
                'email_verified' => false,
                'tenant' => $this->resolveOrganization($user)?->slug,
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Registration failed. Please try again.',
                'error' => $e->getCode() === '23000' ? 'Email already registered' : 'Database error',
            ], 409);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred during registration.',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Login and issue token
     */
    public function login(LoginRequest $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            $email = $request->input('email');
            $user = $email ? User::where('email', $email)->first() : null;

            ActivityLogger::log([
                'action' => 'login_failed',
                'module' => 'authentication',
                'description' => 'Login failed due to invalid credentials',
                'properties' => ['email' => $email],
                'user_id' => $user?->id,
                'organization_id' => $user?->organization_id,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user = Auth::user();
        $email_verified = true; // Assume true by default
        $otp_sent = null; // No OTP sent by default

        // Check if email is verified
        if ( ! $user->email_verified_at ) {
            // Send OTP email
            try {
                $otpService = new OtpService();
                $otp = $otpService->generate($user);
                $email_verified = false;
                $otp_sent = $otp;
                // Send OTP email
                SendOtpEmailJob::dispatch($user, $otp);
            } catch (\Exception $e) {
                // Log the error but don't fail registration
                \Log::warning('OTP email failed for user: ' . $user->email, ['error' => $e->getMessage()]);
            }
        }
        
        // Ensure the IdentifyTenant middleware has resolved the organization
        if (app()->has('currentOrganization')) {
            $currentOrganization = app('currentOrganization');
            
            // Allow superadmins to bypass tenant restrictions
            // Check if the user is NOT a superadmin AND does not belong to the resolved organization
            if ($user->role !== 'superadmin' && $user->organization_id !== $currentOrganization->id) {
                // Since Auth::attempt automatically logs them in, we must log them out
                $user->currentAccessToken()?->delete();
                Auth::guard('web')->logout();

                ActivityLogger::log([
                    'action' => 'login_failed_tenant_mismatch',
                    'module' => 'authentication',
                    'description' => "Login denied for {$user->email}: tenant mismatch",
                    'properties' => [
                        'user_id' => $user->id,
                        'expected_organization_id' => $currentOrganization->id,
                        'actual_organization_id' => $user->organization_id,
                    ],
                    'user_id' => $user->id,
                    'organization_id' => $currentOrganization->id,
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'This user does not belong to this organization.'
                ], 403);
            }
        }

        $tokenResult = $user->createToken('api_token');
        $tokenResult->accessToken->expires_at = now()->addDays(7);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        $user->loadMissing('organization');

        ActivityLogger::log([
            'action' => 'login',
            'module' => 'authentication',
            'description' => "User {$user->name} ({$user->email}) logged in",
            'properties' => ['user_id' => $user->id, 'email' => $user->email],
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'data' => [],
            'token' => $token,
            'user' => $user,
            'email_verified' => $email_verified,
            'tenant' => $this->resolveOrganization($user)?->slug,
        ], 200);
    }

    /**
     * Login or register via Google OAuth
     */
    public function googleLogin(GoogleAuthRequest $request)
    {
        $googleData = $this->verifyGoogleIdToken($request->input('id_token'));

        if (! $googleData) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid Google token.',
            ], 401);
        }

        $audience = config('services.google.client_id') ?? env('GOOGLE_CLIENT_ID');
        if (! $audience || ($googleData['aud'] ?? null) !== $audience) {
            return response()->json([
                'status' => false,
                'message' => 'Google token audience mismatch.',
            ], 401);
        }

        if (! ($googleData['email_verified'] ?? false)) {
            return response()->json([
                'status' => false,
                'message' => 'Google email is not verified.',
            ], 422);
        }

        $email = $googleData['email'];
        $user = User::where('email', $email)->first();

        if ($user) {
            if (app()->has('currentOrganization')) {
                $currentOrganization = app('currentOrganization');
                if ($user->role !== 'superadmin' && $user->organization_id !== $currentOrganization->id) {
                    return response()->json([
                        'status' => false,
                        'message' => 'This user does not belong to this organization.',
                    ], 403);
                }
            }

            if (! $user->email_verified_at) {
                $user->update(['email_verified_at' => now()]);
            }

            $user->update([
                'name' => $googleData['name'] ?? $user->name,
            ]);
        } else {
            $userData = [
                'name' => $googleData['name'] ?? $email,
                'email' => $email,
                'password' => Hash::make(Str::random(24)),
                'phone' => null,
                'job_title' => null,
                'role' => app()->has('currentOrganization') ? 'admin' : 'admin',
                'is_admin' => ! app()->has('currentOrganization'),
                'email_verified_at' => now(),
            ];

            if (app()->has('currentOrganization')) {
                $userData['organization_id'] = app('currentOrganization')->id;
            }

            $user = User::create($userData);
        }

        $tokenResult = $user->createToken($request->input('device_name', 'google_auth'));
        $tokenResult->accessToken->expires_at = now()->addDays(7);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        $user->loadMissing('organization');

        ActivityLogger::log([
            'action' => 'google_login',
            'module' => 'authentication',
            'description' => "User {$user->name} ({$user->email}) logged in with Google",
            'properties' => ['user_id' => $user->id, 'email' => $user->email],
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'data' => [],
            'token' => $token,
            'user' => $user,
            'email_verified' => true,
            'tenant' => $this->resolveOrganization($user)?->slug,
        ], 200);
    }

    private function verifyGoogleIdToken(string $idToken): ?array
    {
        $response = Http::timeout(5)->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        return is_array($payload) ? $payload : null;
    }

    

    /**
     * Logout (revoke token)
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        
        if ($token && method_exists($token, 'delete')) {
            ActivityLogger::log([
                'action' => 'logout',
                'module' => 'authentication',
                'description' => "User {$user->name} ({$user->email}) logged out",
                'properties' => ['user_id' => $user->id],
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
            ]);
            $token->delete();
        } elseif ($token) {
            // For TransientToken or other token types without delete
            ActivityLogger::log([
                'action' => 'logout',
                'module' => 'authentication',
                'description' => "User {$user->name} ({$user->email}) logged out",
                'properties' => ['user_id' => $user->id],
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Verify OTP for email verification
     */
    public function verifyOtp(VerifyOtpRequest $request, OtpService $otpService)
    {
        $validated = $request->validated();
        $user = auth()->user();
        $valid = $otpService->verify($user, $validated['otp']);
        if ( ! $valid ) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired OTP'
            ], 422);
        }

        // Mark email as verified and clear OTP
        $user->update([
            'email_verified_at' => now()
        ]);

        // Rotate token so verifyOtp returns the same shape as login.
        $user->currentAccessToken()?->delete();

        $tokenResult = $user->createToken('api_token');
        $tokenResult->accessToken->expires_at = now()->addDays(7);
        $tokenResult->accessToken->save();
        $token = $tokenResult->plainTextToken;

        $user->loadMissing('organization');

        return response()->json([
            'status' => true,
            'message' => 'Email verified successfully',
            'token' => $token,
            'user' => $user,
            'email_verified' => true,
            'tenant' => $this->resolveOrganization($user)?->slug,
        ], 200);
    }

    /**
     * Resend OTP to user email
     */
    public function resendOtp(OtpService $otpService)
    {
        $user = auth()->user();
        // Check if email already verified
        if ($user->email_verified_at) {
            return response()->json([
                'status' => false,
                'message' => 'Email is already verified',
            ], 400);
        }

        // Rate limit: allow only one resend request per minute.
        $latestOtp = EmailVerificationOtp::query()
            ->where('user_id', $user->id)
            ->where('purpose', 'email_verification')
            ->latest('created_at')
            ->first();

        $secondsSinceLastOtp = $latestOtp
            ? $latestOtp->created_at->diffInSeconds(now(), false)
            : null;

        if ($secondsSinceLastOtp !== null && $secondsSinceLastOtp >= 0 && $secondsSinceLastOtp < 120) {
            return response()->json([
                'status' => false,
                'message' => 'Please wait before requesting another OTP',
            ], 429);
        }

        $otpSent = null;

        // Keep resend behavior consistent with register/login OTP flow.
        try {
            $otpSent = $otpService->generate($user);
            SendOtpEmailJob::dispatch($user, $otpSent);
        } catch (\Exception $e) {
            \Log::warning('OTP resend failed for user: ' . $user->email, ['error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => true,
            'message' => 'OTP has been resent to your email'
        ], 200);
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        $user = User::query()->findOrFail($request->user()->id);

        return response()->json([
            'status' => true,
            'user' => $this->formatUser($user),
            'tenant' => $this->resolveOrganization($user)?->slug,
        ], 200);
    }

    /**
     * Update profile settings for the authenticated user.
     */
    public function updateProfileSettings(Request $request)
    {
        $user = $request->user();

        $allowedFields = ['name', 'email', 'phone', 'job_title', 'avatar_media_id', 'slack_user_id'];

        if (! $request->hasAny($allowedFields)) {
            return response()->json([
                'status' => false,
                'message' => 'No profile fields were provided for update.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar_media_id' => ['sometimes', 'nullable', 'integer'],
            'slack_user_id' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[UW][A-Z0-9]+$/i'],
        ]);

        if (array_key_exists('avatar_media_id', $validated) && $validated['avatar_media_id'] !== null) {
            $organizationId = $user->organization_id;

            if (! $organizationId && app()->has('currentOrganization')) {
                $organizationId = app('currentOrganization')->id;
            }

            if (! $organizationId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid avatar selected.',
                    'errors' => [
                        'avatar_media_id' => ['Profile image requires an organization context.'],
                    ],
                ], 422);
            }

            $avatarExists = Media::withoutGlobalScopes()
                ->where('id', $validated['avatar_media_id'])
                ->where('organization_id', $organizationId)
                ->where('file_type', 'image')
                ->exists();

            if (! $avatarExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid avatar selected.',
                    'errors' => [
                        'avatar_media_id' => ['The selected profile image is invalid.'],
                    ],
                ], 422);
            }
        }

        $user->fill($validated);
        $user->save();

        $user->loadMissing([
            'department:id,department_name',
            'avatarMedia:id,hash,file_type',
        ]);

        ActivityLogger::log([
            'action' => 'profile_update',
            'module' => 'authentication',
            'description' => "User {$user->name} ({$user->email}) updated profile settings",
            'properties' => [
                'user_id' => $user->id,
                'updated_fields' => array_keys($validated),
            ],
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Profile settings updated successfully.',
            'user' => array_merge($user->toArray(), [
                'avatar_url' => $user->avatarMedia?->url,
            ]),
            'tenant' => $this->resolveOrganization($user)?->slug,
        ], 200);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z\\d]).+$/',
                'different:current_password',
            ],
            'confirm_password' => ['required', 'string', 'same:new_password'],
        ], [
            'new_password.regex' => 'Password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => ['The provided current password is incorrect.'],
                ],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($validated['new_password']),
        ])->save();

        ActivityLogger::log([
            'action' => 'password_change',
            'module' => 'authentication',
            'description' => "User {$user->name} ({$user->email}) changed their password",
            'properties' => ['user_id' => $user->id],
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully.',
        ], 200);
    }

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $frontendContext = app()->has('currentOrganization') ? 'tenant' : 'central';

        $query = User::query()->where('email', $request->email);

        // In tenant context, only send reset mail for users in that tenant.
        if (app()->has('currentOrganization')) {
            $query->where('organization_id', app('currentOrganization')->id);
        }

        $user = $query->first();

        if ($user) {
            $token = Password::broker()->createToken($user);
            SendPasswordResetEmailJob::dispatch($user->id, $token, $frontendContext);
        }

        return response()->json([
            'message' => 'If your email exists in our system, a password reset link has been sent.'
        ]);
    }

    /**
    * Reset password using token
    */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z\\d]).+$/',
                'confirmed',
            ],
        ], [
            'password.regex' => 'Password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
        ]);

        if (app()->has('currentOrganization')) {
            $userExistsInTenant = User::query()
                ->where('email', $request->email)
                ->where('organization_id', app('currentOrganization')->id)
                ->exists();

            if (!$userExistsInTenant) {
                return response()->json(['message' => 'Invalid token'], 400);
            }
        }

        $status = Password::reset(
            $request->only('email','password','password_confirmation','token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            ActivityLogger::log([
                'action' => 'password_reset',
                'module' => 'authentication',
                'description' => "Password reset completed for {$request->email}",
                'properties' => ['email' => $request->email],
            ]);
            return response()->json(['message' => 'Password reset successful']);
        }

        return response()->json(['message' => 'Invalid token'], 400);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'organization_id' => $user->organization_id,
            'organization' => $user->organization ? [
                'id' => $user->organization->id,
                'name' => $user->organization->name,
                'slug' => $user->organization->slug,
            ] : null,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])->values(),
            'permissions' => $user->permissionSlugs(),
        ];
    }
}