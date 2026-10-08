<!DOCTYPE html>
<html lang="en">
{{--
    Printable Wi-Fi slips for the registration counter.

    A standalone page rather than part of the admin layout, because it exists to come out
    of a printer: no sidebar, no navigation, nothing that wastes a sheet. Opened in a new
    tab from the Event page.

    This is the fallback that depends on nothing. No phone, no signal, no inbox, no
    internet at the venue. It is also the one delivery route with no ordering problem: a
    slip is handed over when somebody arrives, which is necessarily after the router was
    provisioned.

    Cut along the lines, sort by team, hand over as people check in.
--}}
<head>
    <meta charset="UTF-8">
    <title>Wi-Fi slips &mdash; {{ $event->title }}</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            background: #f3f4f6;
        }

        .sheet { max-width: 1000px; margin: 0 auto; }

        .head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }

        h1 { margin: 0; font-size: 18px; }
        .muted { color: #6b7280; font-size: 12px; }

        .warn {
            margin: 12px 0 20px;
            padding: 10px 14px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1.5;
            color: #92400e;
        }

        .team {
            margin: 0 0 6px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #374151;
        }

        .group { margin-bottom: 18px; }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 8px;
        }

        /* Dashed, because these get cut apart. */
        .slip {
            border: 1px dashed #9ca3af;
            border-radius: 6px;
            padding: 10px 12px;
            background: #ffffff;
            /* Never split a single slip across two sheets. */
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .who {
            font-size: 12px;
            font-weight: bold;
            margin: 0 0 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .pair { display: flex; gap: 14px; }
        .cell { min-width: 0; }

        .label {
            margin: 0;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
        }

        /* Monospaced and spaced out: these are read off paper and typed into a phone,
           and a proportional face renders rn and m almost the same at this size. */
        .value {
            margin: 0;
            font-family: 'Courier New', Courier, monospace;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .foot { margin: 6px 0 0; font-size: 9px; color: #9ca3af; }

        .toolbar { margin-bottom: 14px; }

        .toolbar button {
            font: inherit;
            font-size: 13px;
            font-weight: bold;
            padding: 8px 16px;
            border: 0;
            border-radius: 6px;
            background: #2563eb;
            color: #fff;
            cursor: pointer;
        }

        .empty {
            padding: 32px;
            text-align: center;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: #6b7280;
            font-size: 14px;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .toolbar, .warn { display: none; }
            .slip { border-color: #6b7280; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="toolbar">
            <button type="button" onclick="window.print()">Print</button>
        </div>

        <div class="head">
            <h1>Wi-Fi logins &mdash; {{ $event->title }}</h1>
            <p class="muted">
                {{ $total }} slip{{ $total === 1 ? '' : 's' }}
                @if ($event->ends_at)
                    &middot; valid until {{ \App\Support\LocalTime::dateWallClock($event->ends_at) }}
                @endif
            </p>
        </div>

        {{-- On screen only. It is a warning to whoever prints this, not something the
             competitor receiving a slip needs to read. --}}
        <p class="warn">
            Every working login for this event is on this page. Do not leave it on a counter
            unattended, and shred what is left at the end of the day. A login that has been
            fetched by the router works right now; one that has not been fetched yet will
            fail, so run the fetch command before handing these out.
        </p>

        @forelse ($groups as $teamName => $credentials)
            <div class="group">
                <p class="team">{{ $teamName }} &middot; {{ $credentials->count() }}</p>

                <div class="grid">
                    @foreach ($credentials as $credential)
                        <div class="slip">
                            <p class="who">{{ $credential->participant?->full_name ?? 'Unnamed competitor' }}</p>

                            <div class="pair">
                                <div class="cell">
                                    <p class="label">User</p>
                                    <p class="value">{{ $credential->username }}</p>
                                </div>
                                <div class="cell">
                                    <p class="label">Pass</p>
                                    <p class="value">{{ $credential->password }}</p>
                                </div>
                            </div>

                            <p class="foot">
                                Connect to the event Wi-Fi, then type these on the page that opens.
                                @unless ($credential->isProvisioned())
                                    &middot; NOT ON ROUTER YET
                                @endunless
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div class="empty">
                <p>
                    No logins have been issued for this event yet. Turn on
                    &ldquo;Create WiFi portal user and password&rdquo; on the event, then use
                    the issue button on the Event page.
                </p>
            </div>
        @endforelse
    </div>
</body>
</html>
