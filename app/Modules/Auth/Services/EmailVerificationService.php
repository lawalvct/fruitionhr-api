<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Auth\Models\EmailVerificationCode;
use App\Modules\Auth\Notifications\EmailVerificationCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    public const CODE_TTL_MINUTES = 10;

    public const RESEND_AFTER_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    public function send(User $user, bool $enforceCooldown = false): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $existing = EmailVerificationCode::query()->where('user_id', $user->id)->first();

        if ($enforceCooldown && $existing?->sent_at?->addSeconds(self::RESEND_AFTER_SECONDS)->isFuture()) {
            throw ValidationException::withMessages([
                'code' => ['Please wait before requesting another verification code.'],
            ]);
        }

        $code = (string) random_int(100000, 999999);

        EmailVerificationCode::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'sent_at' => now(),
            ],
        );

        $user->notifyNow(new EmailVerificationCodeNotification($code));
    }

    public function verify(User $user, string $code): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $record = EmailVerificationCode::query()->where('user_id', $user->id)->first();

        if ($record === null || $record->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => ['This verification code has expired. Request a new one.'],
            ]);
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages([
                'code' => ['Too many incorrect attempts. Request a new code.'],
            ]);
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect.'],
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $record->delete();
    }
}
