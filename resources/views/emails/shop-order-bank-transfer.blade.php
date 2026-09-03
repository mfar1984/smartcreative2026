<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complete your bank transfer</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">

        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">Complete your bank transfer</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            Order {{ $order->reference }} &middot; placed {{ $order->created_at->format('d M Y, g:i a') }}
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
            Hello {{ $order->customer_name }}, thank you for your order. It is held for you and
            will be released once we have checked the payment against our account.
        </p>

        {{-- The amount first. It is the one number they have to get right. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:#eff6ff;border-radius:6px;">
            <tr>
                <td style="padding:16px;">
                    <p style="margin:0 0 2px;font-size:13px;color:#1e40af;">Amount to transfer</p>
                    <p style="margin:0;font-size:24px;font-weight:bold;color:#1e3a8a;">{{ $order->grandTotalLabel() }}</p>
                    <p style="margin:8px 0 0;font-size:13px;color:#1e40af;">
                        Please quote <strong>{{ $order->reference }}</strong> as the payment reference.
                    </p>
                </td>
            </tr>
        </table>

        @if ($bankAccount)
            <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">Transfer to</h2>

            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
                <tr>
                    <td style="padding:8px 0;color:#6b7280;width:150px;vertical-align:top;">Account name</td>
                    <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $bankAccount['name'] }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Bank</td>
                    <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $bankAccount['bank'] }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;color:#6b7280;vertical-align:top;">Account number</td>
                    <td style="padding:8px 0;color:#111827;font-weight:bold;">{{ $bankAccount['number'] }}</td>
                </tr>
            </table>
        @else
            {{-- The account details were switched off between the order being placed and
                 this email going out. Said plainly rather than leaving a blank space
                 where the account should be. --}}
            <p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#92400e;background-color:#fffbeb;border-radius:6px;padding:16px;">
                Our bank details are not available in this email. Please contact us and we will
                send them to you directly.
            </p>
        @endif

        @if (filled($bankNote))
            <p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#374151;background-color:#f9fafb;border-radius:6px;padding:16px;">{{ $bankNote }}</p>
        @endif

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">Then send us the receipt</h2>

        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#374151;">
            Nothing tells us automatically that a transfer has arrived, so please upload your
            receipt using the button below. We check it against our account and confirm your
            order by email.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0">
            <tr>
                <td style="border-radius:6px;background-color:#2563eb;">
                    <a href="{{ $receiptUrl }}"
                       style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">
                        Upload your receipt
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:16px 0 0;font-size:12px;color:#9ca3af;line-height:1.6;">
            This link is unique to your order, so please do not forward it. If the button does
            not work, copy this address into your browser:<br>
            <span style="word-break:break-all;color:#6b7280;">{{ $receiptUrl }}</span>
        </p>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">What you ordered</h2>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
            @foreach ($order->items as $item)
                <tr>
                    <td style="padding:8px 0;color:#374151;border-bottom:1px solid #f3f4f6;">
                        {{ $item->name }}@if ($item->variant_label) <span style="color:#6b7280;">({{ $item->variant_label }})</span>@endif
                        <span style="color:#6b7280;">&times; {{ $item->quantity }}</span>
                    </td>
                    <td style="padding:8px 0;color:#111827;text-align:right;white-space:nowrap;border-bottom:1px solid #f3f4f6;">
                        {{ App\Support\PaymentFigures::money((float) $item->line_total) }}
                    </td>
                </tr>
            @endforeach
            <tr>
                <td style="padding:8px 0;color:#6b7280;">{{ $order->isOffline() ? 'Collection' : 'Delivery' }}</td>
                <td style="padding:8px 0;color:#111827;text-align:right;white-space:nowrap;">
                    {{ $order->isOffline() ? 'No charge' : $order->shippingTotalLabel() }}
                </td>
            </tr>
            <tr>
                <td style="padding:12px 0 0;font-weight:bold;color:#111827;border-top:2px solid #e5e7eb;">Total</td>
                <td style="padding:12px 0 0;font-weight:bold;color:#111827;text-align:right;white-space:nowrap;border-top:2px solid #e5e7eb;">
                    {{ $order->grandTotalLabel() }}
                </td>
            </tr>
        </table>

        @if ($order->isOffline())
            <p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#374151;background-color:#f9fafb;border-radius:6px;padding:16px;">
                This order is collected in person, not posted. We send you the place, the date
                and the time once your payment is confirmed. Please bring your identity card.
            </p>
        @endif

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            {{ config('app.name') }} &middot; order {{ $order->reference }}
        </p>
    </div>
</body>
</html>
