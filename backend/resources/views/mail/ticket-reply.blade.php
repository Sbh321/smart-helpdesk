<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>[#{{ $ticketNumber }}] {{ $title }}</title></head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 15px; line-height: 1.5; color: #1f2933;">
    <p style="color: #52606d;">{{ $workspaceName }} support replied to ticket #{{ $ticketNumber }}: {{ $title }}</p>
    {{-- User input: escaped, line breaks kept, never rendered as Markdown or HTML. --}}
    <div style="white-space: pre-wrap;">{{ $body }}</div>
    <p style="color: #52606d; font-size: 13px;">Reply to this email to answer. Please keep the subject line.</p>
</body>
</html>
