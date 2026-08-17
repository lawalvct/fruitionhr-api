<?php

namespace App\Modules\Recruitment\Controllers;

use App\Modules\Recruitment\Models\Vacancy;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a company's logo to anonymous visitors on the careers site.
 *
 * The authenticated logo route cannot be reused here — it resolves the logo
 * from the caller's own tenant context, which a public visitor does not have.
 *
 * Access is deliberately narrow: a logo is only served for a company that is
 * currently advertising a public vacancy. A company using FruitionHR privately
 * should not have its branding, or its use of the product, discoverable by
 * guessing slugs.
 */
class PublicCompanyLogoController extends Controller
{
    public function __invoke(string $slug): StreamedResponse
    {
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->whereNotNull('logo_path')
            ->whereHas('vacancies', fn (Builder $query) => $this->publiclyAdvertised($query))
            ->first();

        abort_unless(
            $tenant !== null && Storage::disk('local')->exists($tenant->logo_path),
            404,
        );

        return Storage::disk('local')->response(
            $tenant->logo_path,
            null,
            // Logos change rarely and this is on every careers card, so let
            // browsers and any CDN hold on to it.
            ['Cache-Control' => 'public, max-age=86400'],
        );
    }

    /**
     * Mirrors PublicVacancyController::publicQuery() — a logo is public for
     * exactly as long as a vacancy is.
     *
     * @param  Builder<Vacancy>  $query
     */
    private function publiclyAdvertised(Builder $query): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where('visibility', Vacancy::VISIBILITY_PUBLIC)
            ->where('status', Vacancy::STATUS_OPEN)
            ->whereNotNull('public_slug')
            ->where(fn (Builder $q) => $q->whereNull('opens_at')->orWhereDate('opens_at', '<=', today()))
            ->where(fn (Builder $q) => $q->whereNull('closes_at')->orWhereDate('closes_at', '>=', today()));
    }
}
