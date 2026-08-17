<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\ActivateRequest;
use App\Modules\Admin\Requests\ListAdministratorsRequest;
use App\Modules\Admin\Requests\ReasonRequest;
use App\Modules\Admin\Requests\StoreAdministratorRequest;
use App\Modules\Admin\Requests\UpdateAdministratorRequest;
use App\Modules\Admin\Resources\PlatformAdministratorResource;
use App\Modules\Admin\Services\PlatformActivityService;
use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Auth\Services\PlatformAdministratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class PlatformAdministratorController extends Controller
{
    public function index(
        ListAdministratorsRequest $request,
        PlatformAdministratorService $service,
    ): AnonymousResourceCollection {
        return PlatformAdministratorResource::collection(
            $service->paginate($request->validated())
        );
    }

    public function store(
        StoreAdministratorRequest $request,
        PlatformAdministratorService $service,
        PlatformActivityService $activity,
    ): JsonResponse {
        $result = DB::transaction(function () use ($request, $service, $activity): array {
            $result = $service->create($request->safe()->except([
                'password_confirmation',
            ]));
            $activity->record(
                request: $request,
                action: 'administrator.created',
                subjectType: 'administrator',
                subjectId: $result['administrator']->id,
                subjectLabel: $result['administrator']->name,
                before: $result['before'],
                after: $result['after'],
            );

            return $result;
        });

        return (new PlatformAdministratorResource($result['administrator']))
            ->additional(['message' => 'Administrator created. They can sign in right away.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateAdministratorRequest $request,
        int $administrator,
        PlatformAdministratorService $service,
        PlatformActivityService $activity,
        EmailVerificationService $verification,
    ): PlatformAdministratorResource {
        $result = DB::transaction(function () use ($request, $administrator, $service, $activity): array {
            $result = $service->update($administrator, $request->validated(), $request->user());
            $activity->record(
                request: $request,
                action: 'administrator.updated',
                subjectType: 'administrator',
                subjectId: $result['administrator']->id,
                subjectLabel: $result['administrator']->name,
                before: $result['before'],
                after: $result['after'],
            );

            return $result;
        });

        if ($result['email_changed']) {
            $verification->send($result['administrator']);
        }

        return (new PlatformAdministratorResource($result['administrator']))
            ->additional([
                'message' => $result['email_changed']
                    ? 'Administrator updated. The new email address must be verified.'
                    : 'Administrator updated.',
            ]);
    }

    public function disable(
        ReasonRequest $request,
        int $administrator,
        PlatformAdministratorService $service,
        PlatformActivityService $activity,
    ): PlatformAdministratorResource {
        $result = DB::transaction(function () use ($request, $administrator, $service, $activity): array {
            $result = $service->disable($administrator, $request->user());
            $activity->record(
                request: $request,
                action: 'administrator.disabled',
                subjectType: 'administrator',
                subjectId: $result['administrator']->id,
                subjectLabel: $result['administrator']->name,
                before: $result['before'],
                after: $result['after'],
                reason: $request->validated('reason'),
            );

            return $result;
        });

        return (new PlatformAdministratorResource($result['administrator']))
            ->additional(['message' => 'Administrator disabled.']);
    }

    public function activate(
        ActivateRequest $request,
        int $administrator,
        PlatformAdministratorService $service,
        PlatformActivityService $activity,
    ): PlatformAdministratorResource {
        $result = DB::transaction(function () use ($request, $administrator, $service, $activity): array {
            $result = $service->activate($administrator);
            $activity->record(
                request: $request,
                action: 'administrator.activated',
                subjectType: 'administrator',
                subjectId: $result['administrator']->id,
                subjectLabel: $result['administrator']->name,
                before: $result['before'],
                after: $result['after'],
                reason: $request->validated('reason'),
            );

            return $result;
        });

        return (new PlatformAdministratorResource($result['administrator']))
            ->additional(['message' => 'Administrator activated.']);
    }
}
