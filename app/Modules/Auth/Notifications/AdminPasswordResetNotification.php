<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sends a support-issued temporary password.
 *
 * The plaintext password exists only on this object and in the rendered mail —
 * it is never persisted, logged or returned over the API.
 */
class AdminPasswordResetNotification extends Notification
{
    public function __construct(public readonly string $password) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = (string) config('mail.brand.product');

        return (new MailMessage)
            ->subject('Your '.$product.' password has been reset')
            ->markdown('emails.auth.password-reset-by-admin', [
                'password' => $this->password,
                'name' => Str::before(trim((string) $notifiable->name), ' ') ?: 'there',
                'email' => (string) $notifiable->email,
            ]);
    }
}
