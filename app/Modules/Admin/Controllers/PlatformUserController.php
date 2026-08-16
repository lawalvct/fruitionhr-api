<?php

namespace App\Modules\Admin\Controllers;

use App\Models\User;
use App\Modules\Admin\Requests\ListPlatformUsersRequest;
use App\Modules\Admin\Resources\PlatformUserResource;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Admin\Services\PlatformUserService;
use App\Modules\Auth\Notifications\AdminPasswordResetNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/** Platform-wide user directory for support. */
class PlatformUserController extends Controller
{
    public function index(
        ListPlatformUsersRequest $request,
        PlatformUserService $service,
    ): AnonymousResourceCollection {
        return PlatformUserResource::collection($service->paginate($request->validated()))
            ->additional(['summary' => $service->summary()]);
    }

    public function show(int $user, PlatformUserService $service): PlatformUserResource
    {
        return new PlatformUserResource($service->find($user));
    }

    public function resetPassword(
        Request $request,
        int $user,
        PlatformUserService $service,
        PlatformActivityService $activity,
    ): PlatformUserResource {
        /** @var User $actor */
        $actor = $request->user();
        $result = $service->resetPassword($user, $actor);

        // Mail only — the plaintext never enters the response or the audit log.
        $result['user']->notify(new AdminPasswordResetNotification($result['password']));

        $activity->record(
            request: $request,
            action: 'user.password_reset',
            subjectType: 'user',
            subjectId: $result['user']->id,
            subjectLabel: $result['user']->name,
            before: [],
            after: ['emailed_to' => $result['user']->email],
        );

        return (new PlatformUserResource($result['user']))
            ->additional(['message' => 'A temporary password has been emailed to '.$result['user']->email.'.']);
    }

    public function verifyEmail(
        Request $request,
        int $user,
        PlatformUserService $service,
        PlatformActivityService $activity,
    ): PlatformUserResource {
        $result = $service->verifyEmail($user);

        $activity->record(
            request: $request,
            action: 'user.email_verified',
            subjectType: 'user',
            subjectId: $result['user']->id,
            subjectLabel: $result['user']->name,
            before: $result['before'],
            after: $result['after'],
        );

        return (new PlatformUserResource($result['user']))
            ->additional(['message' => 'Email address marked as verified.']);
    }
}
