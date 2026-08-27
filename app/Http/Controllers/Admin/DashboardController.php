<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DashboardMetrics;
use Illuminate\Http\Request;

/**
 * The admin dashboard.
 *
 * Every widget is decided here rather than in the Blade. A role that cannot open
 * the payments screen must not read the takings off the dashboard instead, and
 * working that out in one place means a new widget cannot quietly forget to ask.
 *
 * The figures themselves come from DashboardMetrics, which reuses PaymentFigures
 * for anything involving money so this screen and the payments screen cannot
 * disagree.
 */
class DashboardController extends Controller
{
    /** Days in the trend window and in the bar chart. */
    private const TREND_DAYS = 30;

    private const BAR_DAYS = 14;

    public function __invoke(Request $request, DashboardMetrics $metrics)
    {
        $user = $request->user();

        $can = [
            'money' => $user->hasPermission('payments.view'),
            'events' => $user->hasPermission('events.view'),
            'tournaments' => $user->hasPermission('tournaments.view'),
            'unpaid' => $user->hasPermission('payments.unpaid.view'),
        ];

        /*
         | Read once, whatever is shown. The payload is a single cached array, so
         | asking for it when only half of it will be drawn costs nothing extra, and
         | it keeps every figure on screen describing the same moment.
         */
        $data = $metrics->all(self::TREND_DAYS, self::BAR_DAYS);

        return view('admin.dashboard', [
            'can' => $can,
            'trendDays' => self::TREND_DAYS,
            'barDays' => self::BAR_DAYS,
            'generatedAt' => $data['generated_at'],

            /*
             | Grouped so the view can draw two even rows instead of one grid that
             | leaves a hole. Five cards in a three column grid sits as three then
             | two, and the gap reads as something failing to load.
             */
            'cards' => collect($this->cards($data, $can))->groupBy('group'),

            'revenueSeries' => $can['money'] ? $data['revenue_series'] : [],
            'registrationSeries' => $can['events'] ? $data['registration_series'] : [],
            'paymentBreakdown' => $can['money'] ? $data['payment_breakdown'] : [],
            'topEvents' => $can['money'] ? $data['top_events'] : [],
            'upcomingEvents' => $can['events'] ? $data['upcoming_events'] : [],
        ]);
    }

    /**
     * The headline row, holding only the cards this role may see.
     *
     * Built as a list rather than four fixed slots so the grid closes up neatly for
     * a role that can see two of them instead of leaving holes.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $can
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $data, array $can): array
    {
        $cards = [];
        $window = sprintf('vs the previous %d days', self::TREND_DAYS);

        if ($can['money']) {
            $revenue = $data['revenue'];

            $cards[] = [
                'group' => 'money',
                'label' => 'Collected',
                'value' => \App\Support\PaymentFigures::money($revenue['value']),
                'note' => sprintf('Last %d days', self::TREND_DAYS),
                'accent' => 'green',
                'icon' => 'cash',
                'href' => route('admin.payments.overview'),
                'change' => $revenue['change'],
                'changeNote' => $revenue['change'] === null
                    ? 'No takings in the period before, so there is nothing to compare'
                    : $window,
            ];

            $cards[] = [
                'group' => 'money',
                'label' => 'Outstanding',
                'value' => \App\Support\PaymentFigures::money($revenue['outstanding']),
                'note' => 'Owed on entries not cancelled',
                'accent' => $revenue['outstanding'] > 0 ? 'amber' : 'green',
                'icon' => 'credit-card',
                'href' => $can['unpaid'] ? route('admin.payments.unpaid') : null,
                'change' => null,
                'changeNote' => null,
            ];
        }

        if ($can['events']) {
            $registrations = $data['registrations'];

            $cards[] = [
                'group' => 'activity',
                'label' => 'Registrations',
                'value' => number_format($registrations['value']),
                'note' => sprintf(
                    'Last %d days · %s held in total',
                    self::TREND_DAYS,
                    number_format($registrations['total']),
                ),
                'accent' => 'blue',
                'icon' => 'clipboard',
                'href' => route('admin.event.registration'),
                'change' => $registrations['change'],
                'changeNote' => $registrations['change'] === null
                    ? 'Nothing in the period before, so there is nothing to compare'
                    : $window,
            ];

            $people = $data['people'];

            $cards[] = [
                'group' => 'activity',
                'label' => 'People Entered',
                'value' => number_format($people['value']),
                'note' => sprintf('%s of them players', number_format($people['players'])),
                'accent' => 'purple',
                'icon' => 'users',
                'href' => null,
                'change' => null,
                'changeNote' => 'Named people, not entries: one team entry can carry several',
            ];
        }

        if ($can['tournaments']) {
            $tournaments = $data['tournaments'];

            $cards[] = [
                'group' => 'activity',
                'label' => 'Tournaments',
                'value' => number_format($tournaments['live']),
                'note' => sprintf(
                    'Being played now · %s in total',
                    number_format($tournaments['total']),
                ),
                'accent' => $tournaments['live'] > 0 ? 'amber' : 'blue',
                'icon' => 'trophy',
                'href' => route('admin.tournaments.index'),
                'change' => null,
                'changeNote' => sprintf('%s podium(s) published', number_format($tournaments['published'])),
            ];
        }

        return $cards;
    }
}
