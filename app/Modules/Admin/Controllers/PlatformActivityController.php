<?php

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\ListPlatformActivityRequest;
use App\Modules\Admin\Resources\PlatformActivityResource;
use App\Modules\Admin\Services\PlatformActivityService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class PlatformActivityController extends Controller
{
    public function index(
        ListPlatformActivityRequest $request,
        PlatformActivityService $service,
    ): AnonymousResourceCollection {
        return PlatformActivityResource::collection(
            $service->paginate($request->validated())
        );
    }
}
