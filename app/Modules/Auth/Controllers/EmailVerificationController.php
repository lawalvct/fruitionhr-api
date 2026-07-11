<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Resources\MeResource;
use App\Modules\Auth\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, EmailVerificationService $service): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);

        $service->verify($request->user(), $validated['code']);

        return response()->json([
            'message' => 'Email verified.',
            'data' => new MeResource($request->user()->fresh(['tenant', 'employee'])),
        ]);
    }

    public function resend(Request $request, EmailVerificationService $service): JsonResponse
    {
        $service->send($request->user(), enforceCooldown: true);

        return response()->json(['message' => 'A new verification code has been sent.']);
    }
}
