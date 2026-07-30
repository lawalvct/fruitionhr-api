<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
            ->subject('Set up your FruitionHR employee account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->companyName.' has created an employee self-service account for you.')
            ->line('Use your email address to sign in after setting your password.')
            ->action('Set up my password', $this->setupUrl)
            ->line('This link expires in 60 minutes. If you were not expecting this invitation, contact your HR administrator.');
    }
}
