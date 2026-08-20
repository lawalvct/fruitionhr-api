<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Self-service "I forgot my password" link.
 *
 * Distinct from AdminPasswordResetNotification, which mails a support-issued
 * temporary password. This one carries a single-use broker token instead, so
 * no password ever exists outside the user's own browser.
 */
class PasswordResetNotification extends Notification
{
    public function __construct(public readonly string $resetUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = (string) config('mail.brand.product');

        return (new MailMessage)
            ->subject('Reset your '.$product.' password')
            ->markdown('emails.auth.password-reset', [
                'resetUrl' => $this->resetUrl,
                'name' => Str::before(trim((string) $notifiable->name), ' ') ?: 'there',
                'email' => (string) $notifiable->email,
                // Mirrors the broker TTL that minted the token in this mail.
                'expiresInMinutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]);
    }
}
