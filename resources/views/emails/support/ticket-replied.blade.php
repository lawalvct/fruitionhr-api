<x-mail::message>
<x-slot:preheader>
Our support team has replied to {{ $reference }} — {{ $subject }}
</x-slot:preheader>

# We have replied to your ticket

Hi {{ $name }},

Our support team has answered **{{ $subject }}**.

<x-mail::panel>
{{ $body }}
</x-mail::panel>

<x-mail::button :url="$ticketUrl" color="primary">
View the full conversation
</x-mail::button>

<x-mail::details :rows="['Ticket' => $reference, 'Subject' => $subject]" />

Reply from the ticket page and we will pick it straight back up.

Regards,<br>
The {{ config('mail.brand.product') }} Support Team

<x-slot:subcopy>
Replying to this email will not reach us — use the ticket page so your message stays with the rest of the conversation.
</x-slot:subcopy>
</x-mail::message>
