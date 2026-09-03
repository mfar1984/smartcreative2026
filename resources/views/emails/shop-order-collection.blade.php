<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ready to collect</title>
</head>
<body style="margin:0;padding:24px;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:8px;padding:24px;">

        <h1 style="margin:0 0 4px;font-size:20px;color:#111827;">Your order is ready to collect</h1>
        <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">
            Order {{ $order->reference }} &middot; paid {{ $order->paid_at?->format('d M Y, g:i a') }}
        </p>

        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#374151;">
            Hello {{ $order->customer_name }}, your payment is confirmed. Nothing is being posted:
            this order is collected in person at the counter below.
        </p>

        {{-- Where and when, stated once and prominently. Everything else in this email
             is secondary to somebody knowing which day to turn up. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:#eff6ff;border-radius:6px;">
            <tr>
                <td style="padding:16px;">
                    <p style="margin:0 0 2px;font-size:13px;color:#1e40af;">Collect from</p>
                    <p style="margin:0;font-size:16px;font-weight:bold;color:#1e3a8a;">
                        {{ $order->collection_label ?: 'Our collection counter' }}
                    </p>

                    @if (filled($order->collection_location))
                        <p style="margin:6px 0 0;font-size:14px;color:#1e3a8a;">{{ $order->collection_location }}</p>
                    @endif

                    @if ($order->collection_at)
                        <p style="margin:10px 0 0;font-size:13px;color:#1e40af;">On</p>
                        <p style="margin:0;font-size:16px;font-weight:bold;color:#1e3a8a;">
                            {{ $order->collection_at->format('l, d F Y') }}<br>
                            {{ $order->collection_at->format('g:i a') }}
                        </p>
                    @endif
                </td>
            </tr>
        </table>

        {{-- The identity card is the whole verification, so it is called out on its own
             rather than buried in a list of instructions. --}}
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background-color:#fffbeb;border-radius:6px;margin-top:16px;">
            <tr>
                <td style="padding:16px;">
                    <p style="margin:0 0 6px;font-size:15px;font-weight:bold;color:#92400e;">
                        Please bring your identity card
                    </p>
                    <p style="margin:0;font-size:14px;line-height:1.6;color:#92400e;">
                        Our counter checks it against the number on your order before handing anything
                        over. Without it we cannot release your order, even with this email.
                        @if (filled($order->identity_card))
                            You gave us <strong>{{ $order->identity_card }}</strong>.
                        @endif
                    </p>
                </td>
            </tr>
        </table>

        <h2 style="margin:24px 0 8px;font-size:15px;color:#111827;">What to collect</h2>

        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;border-collapse:collapse;">
            @foreach ($order->items as $item)
                <tr>
                    <td style="padding:8px 0;color:#374151;border-bottom:1px solid #f3f4f6;">
                        {{ $item->name }}@if ($item->variant_label) <span style="color:#6b7280;">({{ $item->variant_label }})</span>@endif
                    </td>
                    <td style="padding:8px 0;color:#111827;text-align:right;white-space:nowrap;border-bottom:1px solid #f3f4f6;">
                        &times; {{ $item->quantity }}
                    </td>
                </tr>
            @endforeach
        </table>

        <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#374151;">
            Paid in full: {{ $order->grandTotalLabel() }} by {{ $order->methodLabel() }}. There is
            nothing further to pay at the counter.
        </p>

        <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
            {{ config('app.name') }} &middot; order {{ $order->reference }}. Keep this email, or note
            the reference, to help us find your order quickly.
        </p>
    </div>
</body>
</html>
