<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The rendered subject, not the campaign's own: that one still carries its
         placeholders and they would show in the document title. --}}
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;overflow:hidden;">

        <div style="padding:20px 24px;background-color:#1d4ed8;">
            <p style="margin:0;font-size:16px;font-weight:bold;color:#ffffff;">{{ config('app.name') }}</p>
        </div>

        {{--
            Already escaped and linkified by CampaignRenderer, so it is printed
            raw here. The escaping happened before the anchors were built; doing it
            at this point would show the markup instead of rendering it.
        --}}
        <div style="padding:24px;font-size:14px;line-height:1.65;color:#374151;">{!! $body !!}</div>

        {{--
            The unsubscribe line is appended whether or not the operator put a
            placeholder in the body. A marketing message without a way out gets
            reported as spam, and that reputation damage lands on the registration
            email as well as on this.
        --}}
        <div style="padding:16px 24px;background-color:#f9fafb;border-top:1px solid #e5e7eb;">
            <p style="margin:0 0 6px;font-size:12px;color:#9ca3af;">
                You are receiving this because you agreed to hear from
                {{ config('app.name') }} when you registered for an event.
            </p>
            <p style="margin:0;font-size:12px;color:#9ca3af;">
                <a href="{{ $unsubscribeUrl }}" style="color:#6b7280;text-decoration:underline;">
                    Stop receiving these messages
                </a>
            </p>
        </div>
    </div>

    {{--
        Open tracking. An image this small is invisible, and most mail clients
        block images by default, so a missing open does not mean the message was
        not read. The report says as much rather than treating this as a headcount.
    --}}
    <img src="{{ $openUrl }}" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;">
</body>
</html>
