<?php

namespace App\Support\Authorization;

/**
 * What a platform staff member is allowed to reach in the admin surface.
 *
 * One ability per section of the admin sidebar — the unit people actually
 * think in ("give her the blog", "he only handles support"). Owners hold every
 * ability implicitly; staff hold exactly what they have been granted.
 *
 * This is the platform counterpart to {@see Permissions}, and deliberately
 * separate from it: those are per-tenant roles handed out inside a company,
 * these are jobs inside FruitionHR itself. Nothing here is stored in Spatie's
 * tables, which are keyed on tenant_id and mean nothing for a platform user.
 */
final class PlatformAbilities
{
    public const DASHBOARD = 'dashboard';

    public const TENANTS = 'tenants';

    public const USERS = 'users';

    public const SUPPORT = 'support';

    public const BILLING = 'billing';

    public const CAREERS = 'careers';

    public const BLOG = 'blog';

    public const ACTIVITY = 'activity';

    /**
     * Managing other administrators. Never assignable: it is the power to grant
     * power, so it stays with owners. Without this rule a support agent could
     * promote themselves and the whole model would be decorative.
     */
    public const ADMINISTRATORS = 'administrators';

    /**
     * Every ability, in sidebar order, with the label the admin UI shows and a
     * plain description of what granting it actually lets someone do.
     *
     * @return list<array{key: string, label: string, description: string, assignable: bool}>
     */
    public static function catalogue(): array
    {
        return [
            [
                'key' => self::DASHBOARD,
                'label' => 'Overview',
                'description' => 'Platform-wide totals: companies, users, revenue and recent activity.',
                'assignable' => true,
            ],
            [
                'key' => self::TENANTS,
                'label' => 'Companies',
                'description' => 'View company records, and suspend or reactivate them.',
                'assignable' => true,
            ],
            [
                'key' => self::USERS,
                'label' => 'Users',
                'description' => 'Search every user, verify their email and reset their password.',
                'assignable' => true,
            ],
            [
                'key' => self::SUPPORT,
                'label' => 'Support',
                'description' => 'Work the support queue: reply to tickets, assign them and change status.',
                'assignable' => true,
            ],
            [
                'key' => self::BILLING,
                'label' => 'Billing',
                'description' => 'Edit plans and pricing, see subscriptions, and set the payment gateway.',
                'assignable' => true,
            ],
            [
                'key' => self::CAREERS,
                'label' => 'Careers',
                'description' => 'Read-only oversight of vacancies and applications across all companies.',
                'assignable' => true,
            ],
            [
                'key' => self::BLOG,
                'label' => 'Blog',
                'description' => 'Write, edit and publish posts on the marketing site.',
                'assignable' => true,
            ],
            [
                'key' => self::ACTIVITY,
                'label' => 'Activity log',
                'description' => 'Read the audit trail of everything administrators have done.',
                'assignable' => true,
            ],
            [
                'key' => self::ADMINISTRATORS,
                'label' => 'Administrators',
                'description' => 'Add administrators and decide what they can reach. Owners only.',
                'assignable' => false,
            ],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_column(self::catalogue(), 'key');
    }

    /** Abilities an owner may hand to a staff member. @return list<string> */
    public static function assignable(): array
    {
        return array_values(array_map(
            static fn (array $ability): string => $ability['key'],
            array_filter(self::catalogue(), static fn (array $ability): bool => $ability['assignable']),
        ));
    }

    /**
     * Drops anything unknown or non-assignable and removes duplicates, so a
     * hand-crafted payload cannot smuggle in an ability that does not exist.
     *
     * @param  array<int, mixed>  $abilities
     * @return list<string>
     */
    public static function sanitise(array $abilities): array
    {
        $allowed = self::assignable();

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $ability): string => (string) $ability, $abilities),
            static fn (string $ability): bool => in_array($ability, $allowed, true),
        )));
    }
}
