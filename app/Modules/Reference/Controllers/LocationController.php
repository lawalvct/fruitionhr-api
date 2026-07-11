<?php

namespace App\Modules\Reference\Controllers;

use App\Modules\Reference\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class LocationController extends Controller
{
    public function countries(): JsonResponse
    {
        $countries = Cache::remember('reference.countries', now()->addDay(), fn () => Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'iso3', 'phone_code', 'currency_code'])
        );

        return response()->json(['data' => $countries]);
    }

    public function states(Country $country): JsonResponse
    {
        $states = Cache::remember('reference.states.'.$country->code, now()->addDay(), fn () => $country->states()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type'])
        );

        return response()->json(['data' => $states]);
    }
}
