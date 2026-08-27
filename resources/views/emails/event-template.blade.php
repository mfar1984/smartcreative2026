<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $renderedSubject }}</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;overflow:hidden;">

        <div style="padding:20px 24px;background-color:#1d4ed8;">
            <p style="margin:0;font-size:16px;font-weight:bold;color:#ffffff;">{{ config('app.name') }}</p>
        </div>

        {{--
            The body comes from an editable template. Echoed normally so it is
            escaped: anything resembling markup arrives as the text it was typed
            as, which is what keeps an administrator from putting script into
            somebody else's inbox.

            white-space:pre-wrap preserves the line breaks the author typed,
            which is the only formatting a plain text template needs.
        --}}
        <div style="padding:24px;font-size:14px;line-height:1.65;color:#374151;white-space:pre-wrap;">{{ $body }}</div>

        <div style="padding:16px 24px;background-color:#f9fafb;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:12px;color:#9ca3af;">
                Sent by {{ config('app.name') }}. If this message reached you unexpectedly,
                reply and tell us so we can put it right.
            </p>
        </div>
    </div>
</body>
</html>
