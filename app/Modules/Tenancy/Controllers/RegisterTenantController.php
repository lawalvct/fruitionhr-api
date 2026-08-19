<?php

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Auth\Resources\MeResource;
use App\Modules\Auth\Services\EmailVerificationService;
use App\Modules\Tenancy\Actions\RegisterTenant;
use App\Modules\Tenancy\Requests\RegisterTenantRequest;
use App\Support\Http\SessionEstablished;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class RegisterTenantController extends Controller
{
    public function __invoke(
        RegisterTenantRequest $request,
        RegisterTenant $action,
        EmailVerificationService $verificationService,
    ): JsonResponse {
        $user = $action->execute($request->validated());

        Auth::guard('web')->login($user);

        SessionEstablished::assert($request, 'register');

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $verificationService->send($user);

        return response()->json(['data' => new MeResource($user)], 201);
    }
}
