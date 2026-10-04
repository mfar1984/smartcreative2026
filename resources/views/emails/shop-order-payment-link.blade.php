{{--
    Payment link for a shop order.

    Same layout as emails/shop-order-bank-transfer.blade.php, so the two read as one
    family. $orderUrl is the signed GET confirmation page: it is never the POST pay
    route, which a mail client following links would turn into a 405.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pay for your order</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">

        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">Pay for your order</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            Order {{ $order->reference }} &middot; placed {{ \App\Support\LocalTime::format($order->created_at) }}
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
            Hello {{ $order->customer_name }}, your order is held for you but has not been paid for
            yet. You can pay by card or online banking using the button below.
        </p>

        {{-- The amount first. It is the one number they need. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:#eff6ff;border-radius:6px;">
            <tr>
                <td style="padding:16px;">
                    <p style="margin:0 0 2px;font-size:13px;color:#1e40af;">Amount to pay</p>
                    <p style="margin:0;font-size:24px;font-weight:bold;color:#1e3a8a;">{{ $order->grandTotalLabel() }}</p>
                </td>
            </tr>
        </table>

        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0;">
            <tr>
                <td style="border-radius:6px;background-color:#2563eb;">
                    <a href="{{ $orderUrl }}"
                       style="display:inline-block;padding:12px 24px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">
                        Pay {{ $order->grandTotalLabel() }} for order {{ $order->reference }}
                    </a>
                </td>
            </tr>
        </table>

        <p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#374151;">
            The link opens your order, where a Pay button takes you to our payment provider's own
            page. Nothing is charged until you complete the payment there, and we never see your
            card number.
        </p>

        <p style="margin:16px 0 0;font-size:12px;color:#9ca3af;line-height:1.6;">
            This link is unique to your order and valid for 30 days, so please do not forward it.
            If the button does not work, copy this address into your browser:<br>
            <span style="word-break:break-all;color:#6b7280;">{{ $orderUrl }}</span>
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

        @if (filled($collectionSummary))
            <p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#374151;background-color:#f9fafb;border-radius:6px;padding:16px;">
                This order is collected in person, not posted: {{ $collectionSummary }}. Please bring
                your identity card. We confirm the collection details once your payment is received.
            </p>
        @endif

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            {{ config('app.name') }} &middot; order {{ $order->reference }}
        </p>
    </div>
</body>
</html>
