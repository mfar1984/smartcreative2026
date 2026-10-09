<!DOCTYPE html>
<html lang="en">
{{--
    A sign in from an address this account has not used before.

    Three facts and one instruction, in that order: when, from where, with what —
    then what to do if it was not them. Nothing else, because somebody reading this
    is deciding whether to worry, and a longer message makes that decision slower.

    The time arrives already formatted on the office clock: the queue worker renders
    this and has no HTTP request to take a timezone from.
--}}
<head>
    <meta charset="UTF-8">
    <title>New sign-in to your account</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">

        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">New sign-in to your account</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            {{ config('app.name') }} admin
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
            Hello {{ $user->name }}. Your account was just used to sign in to the admin
            area from a network it has not been used from before.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
            <tr>
                <td style="padding:16px 20px 8px;">
                    <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;">When</p>
                    <p style="margin:0;font-size:15px;color:#111827;">{{ $signedInAt }}</p>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 20px;">
                    <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;">IP address</p>
                    <p style="margin:0;font-size:15px;font-family:'Courier New',Courier,monospace;color:#111827;">{{ $ipAddress }}</p>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 20px 16px;">
                    <p style="margin:0 0 4px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6b7280;">Browser</p>
                    <p style="margin:0;font-size:13px;line-height:1.5;color:#374151;word-break:break-word;">{{ $userAgent ?: 'Not recorded' }}</p>
                </td>
            </tr>
        </table>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">If this was you</h2>
        <p style="margin:0;font-size:14px;line-height:1.6;color:#374151;">
            Nothing to do. You will not be told about this network again.
        </p>

        <h2 style="margin:20px 0 8px;font-size:15px;color:#111827;">If this was not you</h2>
        <ol style="margin:0;padding-left:20px;font-size:14px;line-height:1.7;color:#374151;">
            <li>Change your password now, from Profile in the admin area.</li>
            <li>Tell whoever looks after the website, so the sign-in can be checked.</li>
        </ol>

        <p style="margin:24px 0 0;font-size:12px;line-height:1.6;color:#9ca3af;">
            Sent automatically by {{ config('app.name') }} because this sign-in came from a
            new network. It cannot be replied to.
        </p>
    </div>
</body>
</html>
