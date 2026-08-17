<?php

namespace App\Modules\Support\Notifications;

use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells a customer their ticket has an answer.
 *
 * Only ever constructed with a public reply — internal notes never reach this
 * class, and the service checks that before calling it.
 */
class SupportTicketRepliedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly SupportTicketMessage $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Re: '.$this->ticket->subject.' ['.$this->ticket->reference.']')
            ->markdown('emails.support.ticket-replied', [
                'name' => Str::before(trim((string) $notifiable->name), ' ') ?: 'there',
                'reference' => $this->ticket->reference,
                'subject' => $this->ticket->subject,
                'body' => $this->message->body,
                'ticketUrl' => rtrim((string) config('mail.brand.app_url'), '/').'/support/'.$this->ticket->id,
            ]);
    }
}
