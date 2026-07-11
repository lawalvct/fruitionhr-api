<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationCodeNotification extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your FruitionHR email')
            ->greeting('Welcome to FruitionHR, '.$notifiable->name.'!')
            ->line('Use this verification code to activate your company workspace:')
            ->line($this->code)
            ->line('The code expires in 10 minutes. If you did not create this account, you can ignore this email.');
    }
}
