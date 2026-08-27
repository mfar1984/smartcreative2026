<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test email</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">Email delivery is working</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            Sent {{ now()->format('d M Y, g:i:s a') }} from {{ $siteName }}.
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
            If you are reading this, the SMTP profile saved on the Integration screen can
            reach your inbox. Nothing else needs doing.
        </p>

        {{-- The settings are repeated so the recipient can tell which profile
             produced this, which matters when more than one is being tried. --}}
        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">Settings used</h2>
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
            @foreach ($profile as $label => $value)
                <tr>
                    <td style="padding:8px 0;color:#6b7280;width:150px;vertical-align:top;">{{ $label }}</td>
                    <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $value ?: 'not set' }}</td>
                </tr>
            @endforeach
        </table>

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            Triggered by {{ $sentBy }} from the admin area. This message carries no
            attachments and asks nothing of you; it exists only to prove delivery.
        </p>
    </div>
</body>
</html>
