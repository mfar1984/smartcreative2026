<!DOCTYPE html>
<html lang="en">
{{--
    One competitor's Wi-Fi login.

    The login itself is the whole message, so it is the largest thing on the page and
    everything else is arranged around it. Somebody reads this standing in a venue with a
    phone in one hand, so it has to survive being glanced at rather than read.

    Monospaced and letter-spaced, because these get typed rather than clicked. The
    alphabet they are drawn from already leaves out the characters people confuse, and the
    typeface finishes the job: a proportional font renders rn and m almost identically at
    small sizes.
--}}
<head>
    <meta charset="UTF-8">
    <title>Your Wi-Fi login</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">

        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">Your Wi-Fi login</h1>
        <p style="margin:0 0 24px;font-size:13px;color:#6b7280;">
            {{ $eventTitle }}
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
            Hello {{ $recipientName }}. This login is yours alone and works on the event
            Wi-Fi at the venue.
        </p>

        {{-- The two values, and nothing competing with them. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
            <tr>
                <td style="padding:16px 20px 8px;">
                    <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;">Username</p>
                    <p style="margin:0;font-size:24px;font-family:'Courier New',Courier,monospace;letter-spacing:3px;font-weight:bold;color:#111827;">{{ $credential->username }}</p>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 20px 16px;">
                    <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;">Password</p>
                    <p style="margin:0;font-size:24px;font-family:'Courier New',Courier,monospace;letter-spacing:3px;font-weight:bold;color:#111827;">{{ $credential->password }}</p>
                </td>
            </tr>
        </table>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">How to get online</h2>
        <ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#374151;">
            <li>Connect to the event Wi-Fi@if ($networkName), named <strong>{{ $networkName }}</strong>@endif.</li>
            <li>A login page opens by itself. If it does not, open a browser and visit any website.</li>
            <li>Type the username and password above.</li>
        </ol>

        {{-- Said plainly rather than buried in small print. Somebody who knows the login
             dies tonight will not spend tomorrow morning wondering why it stopped. --}}
        <p style="margin:24px 0 0;padding:12px 16px;background-color:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:13px;line-height:1.6;color:#92400e;">
            This login stops working after {{ $credential->expires_on->format('d M Y') }}.
            It is only for the event, so there is no need to keep it afterwards.
        </p>

        <p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#6b7280;">
            Lost it on the day? Ask at the registration counter and they can print it again.
        </p>

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            Sent by {{ config('app.name') }} because you are registered for this event.
            Do not share this login: it is tied to your name, and one login can only be
            used by one device at a time.
        </p>
    </div>
</body>
</html>
