<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Renders mail notifications for assertion without touching the database or a
 * transport — the notifiables are unsaved models, so only the view/theme stack
 * is exercised.
 */
class MailRenderer
{
    /**
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(Notification $notification, User $notifiable): array
    {
        /** @var MailMessage $message */
        $message = $notification->toMail($notifiable);
        $markdown = app(Markdown::class);

        return [
            'subject' => (string) $message->subject,
            'html' => (string) $markdown->render($message->markdown, $message->data()),
            'text' => (string) $markdown->renderText($message->markdown, $message->data()),
        ];
    }

    public static function recipient(
        string $name = 'Adaeze Nwosu',
        string $email = 'adaeze@example.test',
        ?string $company = 'Fruition Foods Ltd',
    ): User {
        $user = new User(['name' => $name, 'email' => $email]);
        $user->setRelation('tenant', $company === null ? null : new Tenant(['name' => $company]));

        return $user;
    }
}
