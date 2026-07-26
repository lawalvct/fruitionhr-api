<?php

namespace App\Modules\Auth\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DebugEmailVerificationController extends Controller
{
    public function show(Request $request): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $email = trim((string) $request->query('email'));
        $code = null;
        $message = null;

        if ($email !== '') {
            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $message = 'No user found with that email address.';
            } elseif ($user->hasVerifiedEmail()) {
                $message = 'This email is already verified.';
            } else {
                $code = Cache::get(EmailVerificationService::debugCacheKey($email));

                if ($code === null) {
                    $message = 'No active verification code for this email. It may have expired — request a new one.';
                }
            }
        }

        return view('debug.email-verification-code', [
            'email' => $email,
            'code' => $code,
            'message' => $message,
        ]);
    }
}
