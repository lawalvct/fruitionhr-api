<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class EssInvitationNotification extends Notification
{
    public function __construct(
        public readonly string $setupUrl,
        public readonly string $companyName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->companyName.' has invited you to '.config('mail.brand.product'))
            ->markdown('emails.auth.ess-invitation', [
                'setupUrl' => $this->setupUrl,
                'company' => $this->companyName,
                'name' => Str::before(trim((string) $notifiable->name), ' ') ?: 'there',
                'email' => (string) $notifiable->email,
                // Mirrors the password broker TTL that minted the setup token.
                'expiresInMinutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]);
    }
}
