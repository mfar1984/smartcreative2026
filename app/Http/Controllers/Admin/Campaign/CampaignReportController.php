<?php

namespace App\Http\Controllers\Admin\Campaign;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Services\AdminLogger;
use App\Support\EventTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What campaigns achieved.
 *
 * Open figures come from a tracking image, which most mail clients block and Apple
 * Mail loads whether or not anybody looked. Every screen here says so rather than
 * presenting the number as a headcount, because an operator making decisions on a
 * figure they believe is exact will make worse ones than somebody who knows it is
 * a floor with noise on top.
 */
class CampaignReportController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request)
    {
        $sent = Campaign::query()->whereIn('status', [Campaign::STATUS_SENDING, Campaign::STATUS_SENT]);

        return view('admin.campaign.reports', [
            'campaigns' => $sent->clone()
                ->with(['audienceEvent', 'creator'])
                ->latest('started_at')
                ->paginate(self::PER_PAGE),

            'totals' => [
                'campaigns' => $sent->clone()->count(),
                'sent' => (int) $sent->clone()->sum('sent_count'),
                'failed' => (int) $sent->clone()->sum('failed_count'),
                'opened' => (int) $sent->clone()->sum('opened_count'),
                'clicked' => (int) $sent->clone()->sum('clicked_count'),
                'unsubscribed' => (int) $sent->clone()->sum('unsubscribed_count'),
            ],

            // Email only, since an SMS carries no image to count an open with.
            'emailSent' => (int) $sent->clone()->where('channel', EventTemplates::CHANNEL_EMAIL)->sum('sent_count'),
            'canExport' => $request->user()->hasPermission('campaigns.reports.export'),
        ]);
    }

    /**
     * One campaign in detail.
     */
    public function show(Request $request, Campaign $campaign)
    {
        $campaign->load(['audienceEvent', 'creator']);

        $status = (string) $request->query('status', '');

        return view('admin.campaign.report-show', [
            'campaign' => $campaign,

            'recipients' => $campaign->recipients()
                ->with('contact')
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByDesc('opened_at')
                ->orderBy('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),

            'statusCounts' => $campaign->recipients()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),

            'statuses' => CampaignRecipient::STATUSES,
            'filterStatus' => $status,

            // Ordered by presses so the most interesting line is first.
            'links' => $campaign->links()
                ->withCount([
                    'clicks',
                    'clicks as unique_clicks_count' => fn ($q) => $q->select(DB::raw('COUNT(DISTINCT campaign_recipient_id)')),
                ])
                ->orderByDesc('clicks_count')
                ->get(),

            'canExport' => $request->user()->hasPermission('campaigns.reports.export'),
        ]);
    }

    /**
     * One campaign's recipients as a CSV.
     */
    public function export(Campaign $campaign): StreamedResponse
    {
        AdminLogger::activity(
            'campaigns.report-export',
            sprintf('Exported the report for campaign %s.', $campaign->name),
        );

        return response()->streamDownload(function () use ($campaign) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Name', 'Address', 'Status', 'Reason',
                'Sent At', 'First Opened', 'Opens', 'First Clicked', 'Clicks', 'Unsubscribed At',
            ]);

            $campaign->recipients()->with('contact')->orderBy('id')->chunk(200, function ($rows) use ($handle) {
                foreach ($rows as $recipient) {
                    fputcsv($handle, [
                        $recipient->contact?->name ?? '',
                        $recipient->address,
                        $recipient->statusLabel(),
                        $recipient->reason ?? '',
                        $recipient->sent_at?->toDateTimeString() ?? '',
                        $recipient->opened_at?->toDateTimeString() ?? '',
                        $recipient->open_count,
                        $recipient->clicked_at?->toDateTimeString() ?? '',
                        $recipient->click_count,
                        $recipient->unsubscribed_at?->toDateTimeString() ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, sprintf('campaign-%d-report-%s.csv', $campaign->id, now()->format('Y-m-d-His')), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
