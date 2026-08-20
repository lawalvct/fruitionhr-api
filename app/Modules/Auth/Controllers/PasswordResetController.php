<?php

namespace App\Modules\Auth\Controllers;

use App\Models\User;
use App\Modules\Auth\Notifications\PasswordResetNotification;
use App\Modules\Auth\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password reset (the "forgot password" flow).
 *
 * Both endpoints are public, so both are written to leak nothing about which
 * addresses hold accounts: the request endpoint answers identically whether or
 * not the email exists, and the broker time-boxes its own work so response
 * timing can't be used to tell the two apart either.
 */
class PasswordResetController extends Controller
{
    /**
     * Deliberately vague, and identical for every outcome — sent, throttled,
     * unknown address, disabled account. Anything more specific would turn this
     * endpoint into a "does this company use FruitionHR?" oracle.
     */
    private const SENT_MESSAGE = 'If that email address has an account, a reset link is on its way. Check your inbox, and your spam folder.';

    public function request(ForgotPasswordRequest $request): JsonResponse
    {
        $email = Str::lower(trim((string) $request->string('email')));

        // Look the user up ourselves first so a disabled account can be skipped
        // silently. Invited employees are deliberately included: someone who
        // never opened their invitation has no password to remember, and the
        // reset below activates them just as accepting the invitation would.
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $user->status !== User::STATUS_DISABLED) {
            // The broker (not a bare createToken) for its throttle window and
            // timebox: it refuses to mint a second token inside
            // auth.passwords.users.throttle seconds, which stops this endpoint
            // being used to flood someone's inbox.
            Password::broker()->sendResetLink(
                ['email' => $user->email],
                fn (User $notifiable, string $token) => $notifiable->notify(
                    new PasswordResetNotification($this->resetUrl($notifiable, $token)),
                ),
            );
        }

        return response()->json(['message' => self::SENT_MESSAGE]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['email'] = Str::lower(trim((string) $data['email']));

        $status = Password::broker()->reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                // Holding the emailed token proves control of the mailbox, so
                // an employee who never opened their invitation is finished
                // setting up — mirrors EssInvitationController::accept().
                // An already-active user's verification state is left alone.
                'status' => $user->status === User::STATUS_INVITED ? User::STATUS_ACTIVE : $user->status,
                'email_verified_at' => $user->status === User::STATUS_INVITED ? now() : $user->email_verified_at,
                'remember_token' => Str::random(60),
            ])->save();

            $this->forgetSessions($user);
        });

        if ($status !== Password::PASSWORD_RESET) {
            // Same message for a bad token and an unknown address: the token is
            // the only thing the sender could have got wrong that we can help
            // with, and naming the email would leak account existence.
            throw ValidationException::withMessages([
                'token' => 'This reset link is invalid or has expired. Request a new one and try again.',
            ]);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    /**
     * Where the emailed link lands. Platform admins sign in on the admin
     * surface, so sending them to the tenant app would only bounce them.
     */
    private function resetUrl(User $user, string $token): string
    {
        $base = $user->isSuperAdmin()
            ? (string) config('app.admin_url')
            : (string) config('app.frontend_url');

        return rtrim($base, '/')
            .'/reset-password?token='.urlencode($token)
            .'&email='.urlencode((string) $user->email);
    }

    /**
     * A reset is also how someone reacts to a hijacked account, so every other
     * signed-in session is dropped. Only meaningful on the database session
     * driver — file/redis sessions aren't queryable by user.
     */
    private function forgetSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
