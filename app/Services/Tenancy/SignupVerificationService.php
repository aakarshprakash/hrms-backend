<?php

namespace App\Services\Tenancy;

use App\Mail\SignupCodeMail;
use App\Models\SignupVerification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email ownership before a workspace is created:
 *
 *   send(email)          → a 6-digit code is emailed (hashed at rest, short-lived);
 *   verify(email, code)  → a one-time token proving the email, valid for an hour;
 *   consume(email, token) at sign-up → the token can't be used again.
 *
 * Wrong codes are counted; after max_attempts the code is dead and a new
 * one must be sent.
 */
class SignupVerificationService
{
    /** @return array{expires_in: int, resend_in: int, dev_code?: string} */
    public function send(string $email, ?string $ip = null): array
    {
        $email = strtolower(trim($email));
        $this->assertEmailFree($email);

        $latest = SignupVerification::where('email', $email)->whereNull('consumed_at')->latest('id')->first();
        $cooldown = (int) config('billing.otp.resend_seconds', 60);
        if ($latest && ! $latest->verified_at && $latest->created_at->gt(now()->subSeconds($cooldown))) {
            $wait = $cooldown - (int) $latest->created_at->diffInSeconds(now());
            throw ValidationException::withMessages(['email' => "Please wait {$wait} seconds before asking for a new code."]);
        }

        $code = (string) random_int(100000, 999999);
        $minutes = (int) config('billing.otp.ttl_minutes', 10);

        SignupVerification::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($minutes),
            'ip' => $ip,
        ]);

        Mail::to($email)->send(new SignupCodeMail($code, $minutes));

        $result = ['expires_in' => $minutes * 60, 'resend_in' => $cooldown];

        // Local development without a real mailer: hand the code back so the flow can be tested.
        if (app()->environment('local') && config('mail.default') === 'log') {
            $result['dev_code'] = $code;
        }

        return $result;
    }

    public function verify(string $email, string $code): string
    {
        $email = strtolower(trim($email));
        $record = SignupVerification::where('email', $email)->whereNull('verified_at')->whereNull('consumed_at')->latest('id')->first();

        if (! $record || $record->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }
        if ($record->attempts >= (int) config('billing.otp.max_attempts', 5)) {
            throw ValidationException::withMessages(['code' => 'Too many wrong attempts. Request a new code.']);
        }
        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');
            $left = (int) config('billing.otp.max_attempts', 5) - $record->attempts;
            throw ValidationException::withMessages(['code' => $left > 0
                ? "That code isn’t right. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.'
                : 'Too many wrong attempts. Request a new code.']);
        }

        $token = Str::random(48);
        $record->update([
            'verified_at' => now(),
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addMinutes((int) config('billing.otp.token_ttl_minutes', 60)),
        ]);

        return $token;
    }

    /** Whether the token proves this email (without using it up). */
    public function isValid(string $email, ?string $token): bool
    {
        return $this->recordFor($email, $token) !== null;
    }

    public function consume(string $email, ?string $token): void
    {
        $record = $this->recordFor($email, $token);
        if (! $record) {
            throw ValidationException::withMessages(['verification_token' => 'Your email verification has expired. Please verify your email again.']);
        }
        $record->update(['consumed_at' => now()]);
    }

    private function recordFor(string $email, ?string $token): ?SignupVerification
    {
        if (! $token) {
            return null;
        }

        return SignupVerification::where('email', strtolower(trim($email)))
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('consumed_at')
            ->where('token_expires_at', '>', now())
            ->first();
    }

    private function assertEmailFree(string $email): void
    {
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists — sign in instead.']);
        }
    }
}
