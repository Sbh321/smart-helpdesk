{{ $senderName }} replied to ticket #{{ $ticketNumber }}: {{ $title }}

{!! $body !!}
@if (! empty($attachedNames))

Attached: {!! implode(', ', $attachedNames) !!}
@endif
@if (! empty($omittedNames))

Not attached to this email (too large or no longer available): {!! implode(', ', $omittedNames) !!}. Reply to this email if you need them.
@endif

Reply to this email to answer. Please keep the subject line.
