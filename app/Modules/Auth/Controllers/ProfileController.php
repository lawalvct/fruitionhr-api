<?php

namespace App\Modules\Auth\Controllers;

use App\Models\User;
use App\Modules\Auth\Requests\UpdatePasswordRequest;
use App\Modules\Auth\Requests\UpdateProfileRequest;
use App\Modules\Auth\Resources\MeResource;
use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in user editing their OWN account (name, email, phone, timezone,
 * bio, avatar, password). Lives in the plain authenticated group — super admins
 * (no tenant) use it too, and it must work before email verification.
 */
class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, EmailVerificationService $verification): MeResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $emailChanged = $data['email'] !== $user->email;

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = $data['phone'] ?? null;
        $user->timezone = $data['timezone'] ?? null;
        $user->bio = $data['bio'] ?? null;

        // A new address is unproven — force re-verification and send a fresh code.
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $verification->send($user);
        }

        return $this->profileResource($user);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        // 'password' is a hashed cast on the User model, so this re-hashes on save.
        $user->password = $request->validated()['password'];
        $user->save();

        return response()->json(['message' => 'Password updated.']);
    }

    public function uploadAvatar(Request $request): MeResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->refresh(); // hydrate avatar_path before reading it
        $disk = Storage::disk('local');

        if ($user->avatar_path && $disk->exists($user->avatar_path)) {
            $disk->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store("users/{$user->id}/avatar", 'local');
        $user->forceFill(['avatar_path' => $path])->save();

        return $this->profileResource($user);
    }

    public function showAvatar(Request $request): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

        return Storage::disk('local')->response($user->avatar_path);
    }

    public function deleteAvatar(Request $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();
        $user->refresh(); // hydrate avatar_path before reading it
        $disk = Storage::disk('local');

        if ($user->avatar_path && $disk->exists($user->avatar_path)) {
            $disk->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => null])->save();

        return $this->profileResource($user);
    }

    /**
     * Serialise the current user as MeResource. Re-reads the full row so every
     * column (tenant_id, status, …) is present, then mirrors AuthController@me:
     * these routes run outside the tenant middleware, so establish the user's
     * own tenant context — otherwise the fail-closed tenant scope hides their
     * linked employee record.
     */
    private function profileResource(User $user): MeResource
    {
        $user->refresh();

        if ($user->tenant_id !== null && ! app(CurrentTenant::class)->check()) {
            $tenant = Tenant::query()->find($user->tenant_id);
            if ($tenant !== null) {
                app(CurrentTenant::class)->set($tenant);
            }
        }

        return new MeResource($user->loadMissing(['tenant', 'employee']));
    }
}
