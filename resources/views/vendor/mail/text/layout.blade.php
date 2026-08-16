{!! strip_tags($header ?? '') !!}

{!! App\Core\Notifications\PlainTextMail::format(strip_tags($slot)) !!}
@isset($subcopy)

{!! App\Core\Notifications\PlainTextMail::format(strip_tags($subcopy)) !!}
@endisset

{!! strip_tags($footer ?? '') !!}
