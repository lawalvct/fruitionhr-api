<?php

namespace App\Modules\Reference\Controllers;

use App\Modules\Reference\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function countries(): JsonResponse
    {
        $countries = Cache::remember('reference.countries.v2', now()->addDay(), fn () => DB::table('countries')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'iso3', 'phone_code', 'currency_code'])
            ->map(fn (object $country): array => (array) $country)
            ->all()
        );

        return response()->json(['data' => $countries]);
    }

    public function states(Country $country): JsonResponse
    {
        $states = Cache::remember('reference.states.v2.'.$country->code, now()->addDay(), fn () => DB::table('states')
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type'])
            ->map(fn (object $state): array => (array) $state)
            ->all()
        );

        return response()->json(['data' => $states]);
    }
}
