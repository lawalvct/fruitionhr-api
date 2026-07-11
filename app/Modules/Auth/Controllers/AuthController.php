<?php

namespace App\Modules\Auth\Controllers;

use App\Models\User;
use App\Modules\Auth\Requests\LoginRequest;
use App\Modules\Auth\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * SPA cookie login (Sanctum stateful). The frontend must hit
     * /sanctum/csrf-cookie first.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $throttleKey = Str::transliterate(
            Str::lower($request->string('email')).'|'.$request->ip()
        );

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ])],
            ]);
        }

        if (! Auth::guard('web')->attempt(
            $request->only('email', 'password'),
            $request->boolean('remember'),
        )) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = $request->user();

        if ($user->status !== User::STATUS_ACTIVE) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => ['This account is disabled.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(['data' => new MeResource($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): MeResource
    {
        return new MeResource($request->user()->loadMissing(['tenant', 'employee']));
    }
}
