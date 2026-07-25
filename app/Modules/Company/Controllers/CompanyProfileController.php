<?php

namespace App\Modules\Company\Controllers;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The current tenant's own branding (logo). Company structure lives in the sibling resource controllers. */
class CompanyProfileController extends Controller
{
    public function uploadLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPANY_MANAGE), 403);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 409, 'No tenant context.');

        $disk = Storage::disk('local');

        if ($tenant->logo_path && $disk->exists($tenant->logo_path)) {
            $disk->delete($tenant->logo_path);
        }

        $path = $request->file('logo')->store("tenants/{$tenant->id}/branding", 'local');
        $tenant->update(['logo_path' => $path]);

        return response()->json(['data' => ['logo_url' => '/api/v1/company/logo']]);
    }

    /**
     * Branding, not company-structure data — every authenticated tenant user
     * should see their own employer's logo (sidebar, etc.), not just those
     * with company.view. The `tenant` middleware already scopes this to the
     * caller's own tenant.
     */
    public function showLogo(): StreamedResponse
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant?->logo_path && Storage::disk('local')->exists($tenant->logo_path), 404);

        return Storage::disk('local')->response($tenant->logo_path);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPANY_MANAGE), 403);

        $tenant = app(CurrentTenant::class)->get();
        abort_if($tenant === null, 409, 'No tenant context.');

        $disk = Storage::disk('local');

        if ($tenant->logo_path && $disk->exists($tenant->logo_path)) {
            $disk->delete($tenant->logo_path);
        }

        $tenant->update(['logo_path' => null]);

        return response()->json(['data' => ['logo_url' => null]]);
    }
}
