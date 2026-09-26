{{--
    Staff copy of a message written on a competitor's public profile.

    This is the office's copy and nobody else's, which is why the competitor's record
    is here in full. The website showed the sender a masked card number, a masked
    telephone and a masked address; the person reading this has to be able to identify
    who is being asked after, so it is unmasked here and only here.
--}}
@php
    $registration = $participant->registration;
    $event = $registration?->event;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Message for a competitor</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:620px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">Message for a competitor</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            Received {{ $playerMessage->created_at->format('d M Y, g:i a') }} through the player profile on the website.
        </p>

        <div style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:12px 16px;margin:0 0 20px;">
            <p style="margin:0;font-size:13px;line-height:1.6;color:#92400e;">
                The sender was not given this competitor's contact details. Decide whether to pass
                the message on before replying.
            </p>
        </div>

        <h2 style="margin:0 0 8px;font-size:15px;color:#111827;">Who it is about</h2>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
            <tr>
                <td style="padding:8px 0;color:#6b7280;width:170px;vertical-align:top;">Shown publicly as</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $publicLabel }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Full name</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $participant->full_name }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Identity card</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $participant->ic_number }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Game account</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $participant->ignLabel() }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Telephone</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $participant->phone ?: 'Not provided' }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Email</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">
                    @if ($participant->email)
                        <a href="mailto:{{ $participant->email }}" style="color:#2563eb;">{{ $participant->email }}</a>
                    @else
                        Not provided
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Gender / Race</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">
                    {{ $participant->genderLabel() }} / {{ $participant->raceLabel() }}
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Role</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $participant->roleLabel() }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Entry</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">
                    {{ $registration?->team_name ?: 'Individual entry' }}
                    @if ($registration?->reference)
                        &middot; {{ $registration->reference }}
                    @endif
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Event</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $event?->title ?? 'Unknown' }}</td>
            </tr>
        </table>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">Who wrote in</h2>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
            <tr>
                <td style="padding:8px 0;color:#6b7280;width:170px;vertical-align:top;">Name</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $playerMessage->name }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Email</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">
                    <a href="mailto:{{ $playerMessage->email }}" style="color:#2563eb;">{{ $playerMessage->email }}</a>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Telephone</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $playerMessage->phone ?: 'Not provided' }}</td>
            </tr>
        </table>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">Message</h2>
        <div style="font-size:14px;line-height:1.6;color:#374151;background-color:#f9fafb;border-radius:6px;padding:16px;white-space:pre-wrap;">{{ $playerMessage->message }}</div>

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            Reference #{{ $playerMessage->id }} &middot; submitted from {{ $playerMessage->ip_address ?: 'unknown address' }}.
            Reply directly to this email to answer {{ $playerMessage->name }}.
        </p>
    </div>
</body>
</html>
