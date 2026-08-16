<?php

namespace App\Core\Notifications\Controllers;

use App\Models\User;
use App\Modules\Auth\Notifications\EmailVerificationCodeNotification;
use App\Modules\Auth\Notifications\EssInvitationNotification;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Dev/test-only gallery for every outgoing email, so template changes can be
 * eyeballed without sending real mail. Nothing here touches the database —
 * the notifiables are unsaved models with relations set by hand.
 */
class MailPreviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->guard();

        $previews = $this->previews();
        $selected = (string) $request->query('email', (string) array_key_first($previews));

        if (! isset($previews[$selected])) {
            $selected = (string) array_key_first($previews);
        }

        return view('debug.mail-preview', [
            'previews' => array_map(fn (array $preview): string => $preview['label'], $previews),
            'selected' => $selected,
            'subject' => $this->build($previews[$selected])['subject'],
        ]);
    }

    public function show(Request $request, string $email): Response
    {
        $this->guard();

        $previews = $this->previews();
        abort_unless(isset($previews[$email]), 404);

        $rendered = $this->build($previews[$email]);
        $asText = $request->query('format') === 'text';

        return new Response(
            $asText ? $rendered['text'] : $rendered['html'],
            200,
            ['Content-Type' => $asText ? 'text/plain; charset=utf-8' : 'text/html; charset=utf-8'],
        );
    }

    /**
     * @return array<string, array{label: string, notifiable: User, notification: Notification}>
     */
    protected function previews(): array
    {
        $setupUrl = rtrim((string) config('app.frontend_url'), '/')
            .'/setup-account?token=preview-token-0123456789abcdef&email=chidi.okafor%40example.com';

        return [
            'verification-code' => [
                'label' => 'Email verification code (owner sign-up)',
                'notifiable' => $this->fakeUser('Adaeze Nwosu', 'adaeze@example.com', 'Fruition Foods Ltd'),
                'notification' => new EmailVerificationCodeNotification('482913'),
            ],
            'ess-invitation' => [
                'label' => 'Employee self-service invitation',
                'notifiable' => $this->fakeUser('Chidi Okafor', 'chidi.okafor@example.com', 'Fruition Foods Ltd'),
                'notification' => new EssInvitationNotification($setupUrl, 'Fruition Foods Ltd'),
            ],
        ];
    }

    /**
     * @param  array{label: string, notifiable: User, notification: Notification}  $preview
     * @return array{subject: string, html: string, text: string}
     */
    protected function build(array $preview): array
    {
        /** @var MailMessage $message */
        $message = $preview['notification']->toMail($preview['notifiable']);
        $markdown = app(Markdown::class);

        return [
            'subject' => (string) $message->subject,
            'html' => (string) $markdown->render($message->markdown, $message->data()),
            'text' => (string) $markdown->renderText($message->markdown, $message->data()),
        ];
    }

    protected function fakeUser(string $name, string $email, string $company): User
    {
        $user = new User(['name' => $name, 'email' => $email]);
        $user->setRelation('tenant', new Tenant(['name' => $company]));

        return $user;
    }

    protected function guard(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
    }
}
