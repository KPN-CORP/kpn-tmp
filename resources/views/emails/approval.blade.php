<x-mail::message>
# {{ $subjectLine }}

Halo {{ $recipientName }},

{{ $bodyLine }}

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

Terima kasih,<br>
{{ config('app.name') }}
</x-mail::message>
