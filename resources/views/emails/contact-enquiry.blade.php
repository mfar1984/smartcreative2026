<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New website enquiry</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">
        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">New website enquiry</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            Received {{ $contactMessage->created_at->format('d M Y, g:i a') }}
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
            <tr>
                <td style="padding:8px 0;color:#6b7280;width:150px;vertical-align:top;">Name</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $contactMessage->name }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Email</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">
                    <a href="mailto:{{ $contactMessage->email }}" style="color:#2563eb;">{{ $contactMessage->email }}</a>
                </td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Phone</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $contactMessage->phone ?: 'Not provided' }}</td>
            </tr>
            <tr>
                <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Service</td>
                <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $contactMessage->serviceLabel() }}</td>
            </tr>
        </table>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">Message</h2>
        <div style="font-size:14px;line-height:1.6;color:#374151;background-color:#f9fafb;border-radius:6px;padding:16px;white-space:pre-wrap;">{{ $contactMessage->message }}</div>

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            Reference #{{ $contactMessage->id }} &middot; submitted from {{ $contactMessage->ip_address ?: 'unknown address' }}.
            Reply directly to this email to respond to {{ $contactMessage->name }}.
        </p>
    </div>
</body>
</html>
