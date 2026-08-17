<?php

namespace App\Modules\Admin\Services;

use App\Core\Notifications\SystemNotification;
use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the FruitionHR team something happened on the platform.
 *
 * Recipients are chosen by ability, not by seniority: whoever can act on a
 * support ticket hears about support tickets, and nobody else. That keeps the
 * bell worth looking at, and means a content editor is never paged about a
 * failed payment they cannot do anything with.
 *
 * Database channel only. These fire from inside customer-facing requests — a
 * company opening a ticket, a gateway webhook landing — so they must not put
 * an SMTP round trip, or an SMTP failure, in the customer's path. Email can be
 * added by extending SystemNotification::via() once a queue worker is running.
 */
class PlatformAdminNotifier
{
    /**
     * @param  string  $ability  The PlatformAbilities constant for the section
     *                           this event belongs to. Only administrators who
     *                           can reach that section are told.
     */
    public function notify(
        string $ability,
        string $title,
        string $body,
        ?string $actionUrl = null,
        string $type = 'info',
    ): void {
        $recipients = $this->recipients($ability);

        if ($recipients->isEmpty()) {
            return;
        }

        // Never let telling ourselves break the thing that happened. A customer
        // opening a ticket must not see an error because our own inbox failed.
        try {
            Notification::send($recipients, new SystemNotification($title, $body, $actionUrl, $type));
        } catch (\Throwable $exception) {
            Log::error('Failed to notify platform administrators', [
                'ability' => $ability,
                'title' => $title,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Active administrators whose role grants the ability.
     *
     * Owners fall out of this naturally: their role grants everything, so they
     * match every ability without being special-cased here.
     *
     * @return Collection<int, User>
     */
    private function recipients(string $ability): Collection
    {
        $roleIds = PlatformRole::query()->get()
            ->filter(fn (PlatformRole $role): bool => $role->grants($ability))
            ->modelKeys();

        if ($roleIds === []) {
            /** @var Collection<int, User> $empty */
            $empty = User::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        return User::query()
            ->where('is_super_admin', true)
            ->whereNull('tenant_id')
            ->where('status', User::STATUS_ACTIVE)
            ->whereIn('platform_role_id', $roleIds)
            ->get();
    }
}
