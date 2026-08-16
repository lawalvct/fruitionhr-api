<?php

namespace App\Modules\Auth\Notifications;

use App\Modules\Auth\Services\EmailVerificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class EmailVerificationCodeNotification extends Notification
{
    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $product = (string) config('mail.brand.product');

        return (new MailMessage)
            ->subject($this->code.' is your '.$product.' verification code')
            ->markdown('emails.auth.verification-code', [
                'code' => $this->code,
                'name' => Str::before(trim((string) $notifiable->name), ' ') ?: 'there',
                'company' => $this->companyName($notifiable),
                'expiresInMinutes' => EmailVerificationService::CODE_TTL_MINUTES,
            ]);
    }

    /**
     * The company name is decoration, so it must never break the send. Under
     * Model::shouldBeStrict() a plain `$notifiable->tenant` throws twice over:
     * MissingAttributeException when tenant_id was never selected, and a lazy
     * loading violation on a notifiable hydrated by the auth guard.
     */
    private function companyName(object $notifiable): ?string
    {
        if (! $notifiable instanceof Model) {
            return null;
        }

        if ($notifiable->relationLoaded('tenant')) {
            return $notifiable->getRelation('tenant')?->name;
        }

        if (($notifiable->getAttributes()['tenant_id'] ?? null) === null) {
            return null;
        }

        return $notifiable->loadMissing('tenant')->getRelation('tenant')?->name;
    }
}
