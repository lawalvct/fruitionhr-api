<?php

namespace App\Core\Notifications;

use InvalidArgumentException;

/**
 * Single source of truth for the wording of system-generated in-app
 * notifications (welcome message, and future transactional messages).
 *
 * These are the platform defaults. The intent is for a super admin to override
 * the copy per platform from the admin surface later — when that lands it will
 * back onto a `notification_templates` table and be resolved here, so callers
 * (e.g. registration) never change. For now the defaults below ARE the content.
 */
class NotificationTemplates
{
    /**
     * Template copy keyed by slug. Bodies/titles support `:placeholder` tokens
     * filled in by {@see make()} (e.g. `:name`, `:company`).
     *
     * @return array<string, array{title: string, body: string, type: string, action_url: ?string}>
     */
    public static function defaults(): array
    {
        return [
            'welcome' => [
                'title' => 'Welcome to FruitionHR, :name! 🎉',
                'body' => 'Your workspace for :company is ready. Finish setting up your company to start adding your team and running payroll.',
                'type' => 'success',
                'action_url' => '/onboarding',
            ],
        ];
    }

    /**
     * Build a ready-to-send in-app notification from a template slug.
     *
     * @param  array<string, string|int>  $replace  placeholder => value (token `:key`)
     */
    public static function make(string $key, array $replace = []): SystemNotification
    {
        $template = static::defaults()[$key]
            ?? throw new InvalidArgumentException("Unknown notification template [{$key}].");

        return new SystemNotification(
            title: static::interpolate($template['title'], $replace),
            body: static::interpolate($template['body'], $replace),
            actionUrl: $template['action_url'],
            type: $template['type'],
        );
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function interpolate(string $text, array $replace): string
    {
        foreach ($replace as $token => $value) {
            $text = str_replace(':'.$token, (string) $value, $text);
        }

        return $text;
    }
}
