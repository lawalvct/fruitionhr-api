<?php

namespace App\Modules\Auth\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class EssInvitationController extends Controller
{
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! Password::broker()->tokenExists($user, $data['token'])) {
            throw ValidationException::withMessages(['token' => 'This invitation link is invalid or has expired. Ask HR to resend it.']);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ])->save();
        Password::broker()->deleteToken($user);

        return response()->json(['message' => 'Your employee account is ready. You can now sign in.']);
    }
}
