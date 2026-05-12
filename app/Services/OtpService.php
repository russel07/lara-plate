<?php

namespace App\Services;

use App\Models\EmailVerificationOtp;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    protected int $otpLength = 6;         // 6-digit OTP
    protected int $expiryMinutes = 10;     // valid for 10 minutes
    protected int $maxAttempts = 5;       // maximum verification attempts
    protected bool $hashOtp = true;       // store hashed OTP (more secure)

    /**
     * Generate an OTP for a user.
     *
     * @param User $user
     * @param string $purpose
     * @return string
     */
    public function generate(User $user, string $purpose = 'email_verification'): string
    {
        $otp = $this->createOtp();
        DB::transaction(function () use ($user, $otp, $purpose) {

            // Delete old OTPs for this user and purpose
            EmailVerificationOtp::where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->delete();
            EmailVerificationOtp::create([
                'user_id' => $user->id,
                'otp' => $this->hashOtp ? Hash::make($otp) : $otp,
                'purpose' => $purpose,
                'expires_at' => now()->addMinutes($this->expiryMinutes),
                'attempts' => 0,
            ]);
        });

        return $otp;
    }

    /**
     * Verify an OTP for a user.
     *
     * @param User $user
     * @param string $otp
     * @param string $purpose
     * @return bool
     */
    public function verify(User $user, string $otp, string $purpose = 'email_verification'): bool
    {
        $record = EmailVerificationOtp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->first();

        if (!$record) {
            return false;
        }

        if ($record->attempts >= $this->maxAttempts) {
            return false; // too many attempts
        }

        if (Carbon::now()->greaterThan($record->expires_at)) {
            return false; // expired
        }

        $valid = $this->hashOtp ? Hash::check($otp, $record->otp) : ($otp === $record->otp);

        if (!$valid) {
            $record->increment('attempts');
            return false;
        }

        // OTP verified successfully, delete it
        $record->delete();

        return true;
    }

    /**
     * Generate a random OTP string with leading zeros.
     *
     * @return string
     */
    protected function createOtp(): string
    {
        return str_pad(
            random_int(0, pow(10, $this->otpLength) - 1),
            $this->otpLength,
            '0',
            STR_PAD_LEFT
        );
    }
}