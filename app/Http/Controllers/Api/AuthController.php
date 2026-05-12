<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
            'organization_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'] ?? 'admin',
            'password' => $data['password'],
            'organization_id' => $data['organization_id'] ?? null,
            'status' => 'active',
        ]);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpExpiresAt = now()->addMinutes(10);

        DB::table('email_verification_otps')->insert([
            'user_id' => $user->id,
            'otp' => $otp,
            'purpose' => 'email_verification',
            'expires_at' => $otpExpiresAt,
            'attempts' => 0,
            'used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Registration successful. Please verify your email with the OTP sent.',
            'token' => $token,
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'otp_expires_at' => $otpExpiresAt->toISOString(),
                'otp_attempts' => 0,
                'updated_at' => $user->updated_at?->toISOString(),
                'created_at' => $user->created_at?->toISOString(),
                'id' => $user->id,
            ],
            'email_verified' => (bool) $user->email_verified_at,
            'otp' => (int) $otp,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are invalid.',
            ], 422);
        }

        $user->loadMissing(['organization', 'roles']);

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'token' => $user->createToken($data['device_name'] ?? 'api-token')->plainTextToken,
            'user' => $this->formatUser($user),
        ]);
    }

    public function googleLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => Str::password(32),
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $user->loadMissing(['organization', 'roles']);

        return response()->json([
            'message' => 'Google login successful.',
            'token_type' => 'Bearer',
            'token' => $user->createToken($data['device_name'] ?? 'google-api-token')->plainTextToken,
            'user' => $this->formatUser($user),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'exists:users,email'],
        ]);

        $status = Password::sendResetLink($data);

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'exists:users,email'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => __($status)])
            : response()->json(['message' => __($status)], 422);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'purpose' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $purpose = $data['purpose'] ?? 'email_verification';

        $otpRow = DB::table('email_verification_otps')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (! $otpRow) {
            return response()->json(['message' => 'OTP not found.'], 404);
        }

        if (now()->greaterThan($otpRow->expires_at)) {
            return response()->json(['message' => 'OTP has expired.'], 422);
        }

        if ((int) $otpRow->attempts >= 5) {
            return response()->json(['message' => 'OTP attempts exceeded.'], 422);
        }

        if (! hash_equals($otpRow->otp, $data['otp'])) {
            DB::table('email_verification_otps')
                ->where('id', $otpRow->id)
                ->update(['attempts' => (int) $otpRow->attempts + 1]);

            return response()->json(['message' => 'Invalid OTP.'], 422);
        }

        DB::table('email_verification_otps')
            ->where('id', $otpRow->id)
            ->update(['used_at' => now(), 'updated_at' => now()]);

        if ($purpose === 'email_verification' && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(['message' => 'OTP verified successfully.']);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $purpose = $data['purpose'] ?? 'email_verification';
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('email_verification_otps')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);

        DB::table('email_verification_otps')->insert([
            'user_id' => $user->id,
            'otp' => $otp,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'OTP resent successfully.',
            'otp' => $otp,
        ]);
    }

    public function updateProfileSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
        ]);

        $user = $request->user();
        $user->fill($data);

        if (array_key_exists('email', $data) && $data['email'] !== $user->getOriginal('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return response()->json([
            'message' => 'Profile settings updated successfully.',
            'user' => $this->formatUser($user->fresh(['organization', 'roles'])),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::min(8), 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // Revoke all other tokens, keep current session token alive.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($query) => $query->where('id', '!=', $currentTokenId))->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['organization', 'roles']);

        return response()->json([
            'user' => $this->formatUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
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