<?php

namespace App\Http\Controllers\Admin\Campaign;

use App\Http\Controllers\Controller;
use App\Models\CampaignTemplate;
use App\Services\AdminLogger;
use App\Services\Campaign\CampaignRenderer;
use App\Support\EventTemplates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reusable campaign wording.
 *
 * Kept apart from the event templates because those answer fixed moments decided
 * by code, and there will only ever be five of them. These are written whenever
 * somebody has something to announce.
 */
class CampaignTemplateController extends Controller
{
    private const CHANNELS = [
        EventTemplates::CHANNEL_EMAIL => 'Email Template',
        EventTemplates::CHANNEL_SMS => 'SMS Template',
    ];

    /**
     * What each tab holds, shown above its table.
     *
     * @var array<string, array<string, string>>
     */
    private const TAB_INTRO = [
        EventTemplates::CHANNEL_EMAIL => [
            'label' => 'Email Template',
            'icon' => 'mail',
            'title' => 'Email Templates',
            'description' => 'Wording for email campaigns. A campaign copies it, so editing here never rewrites a message already sent.',
            'accent' => 'blue',
        ],
        EventTemplates::CHANNEL_SMS => [
            'label' => 'SMS Template',
            'icon' => 'mobile',
            'title' => 'SMS Templates',
            'description' => 'Short wording, billed per 160-character segment. The cost of each is shown so a template cannot quietly double a bill.',
            'accent' => 'amber',
        ],
    ];

    public function index(Request $request)
    {
        $channel = array_key_exists((string) $request->query('tab'), self::CHANNELS)
            ? (string) $request->query('tab')
            : EventTemplates::CHANNEL_EMAIL;

        $search = trim((string) $request->query('q', ''));

        $counts = [
            EventTemplates::CHANNEL_EMAIL => CampaignTemplate::where('channel', EventTemplates::CHANNEL_EMAIL)->count(),
            EventTemplates::CHANNEL_SMS => CampaignTemplate::where('channel', EventTemplates::CHANNEL_SMS)->count(),
        ];

        return view('admin.campaign.templates', [
            'tabs' => collect(self::TAB_INTRO)
                ->map(fn (array $tab, string $slug) => [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'count' => $counts[$slug] ?? 0,
                ])
                ->all(),

            'activeTab' => $channel,
            'intro' => self::TAB_INTRO[$channel],
            'templates' => CampaignTemplate::where('channel', $channel)
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->get(),
            'search' => $search,
            'isFiltered' => $search !== '',
            'activeCount' => CampaignTemplate::where('channel', $channel)->where('is_active', true)->count(),

            // Three separate acts, three separate permissions. A role that may
            // reword a template is not necessarily one that may throw it away.
            'canCreate' => $request->user()->hasPermission('campaigns.templates.create'),
            'canUpdate' => $request->user()->hasPermission('campaigns.templates.update'),
            'canDelete' => $request->user()->hasPermission('campaigns.templates.delete'),
        ]);
    }

    public function create(Request $request)
    {
        $channel = array_key_exists((string) $request->query('channel'), self::CHANNELS)
            ? (string) $request->query('channel')
            : EventTemplates::CHANNEL_EMAIL;

        return view('admin.campaign.template-form', [
            'template' => new CampaignTemplate(['channel' => $channel, 'is_active' => true]),
            'channels' => self::CHANNELS,
            'placeholders' => CampaignRenderer::PLACEHOLDERS,
            'mode' => 'create',
        ]);
    }

    public function edit(CampaignTemplate $template)
    {
        return view('admin.campaign.template-form', [
            'template' => $template,
            'channels' => self::CHANNELS,
            'placeholders' => CampaignRenderer::PLACEHOLDERS,
            'mode' => 'edit',
        ]);
    }

    public function store(Request $request)
    {
        $template = CampaignTemplate::create(
            $this->validated($request) + ['created_by' => $request->user()->id]
        );

        AdminLogger::activity('campaigns.template-create', sprintf('Created campaign template %s.', $template->name));

        return redirect()
            ->route('admin.campaigns.templates', ['tab' => $template->channel])
            ->with('status', sprintf('Template %s saved.', $template->name));
    }

    public function update(Request $request, CampaignTemplate $template)
    {
        $template->update($this->validated($request));

        AdminLogger::activity('campaigns.template-update', sprintf('Updated campaign template %s.', $template->name));

        return redirect()
            ->route('admin.campaigns.templates', ['tab' => $template->channel])
            ->with('status', sprintf('Template %s updated. Campaigns already sent are unchanged.', $template->name));
    }

    public function destroy(CampaignTemplate $template)
    {
        $name = $template->name;

        // Campaigns keep their own copy of the wording, so removing a template
        // cannot rewrite what anybody already received.
        $template->delete();

        AdminLogger::activity('campaigns.template-delete', sprintf('Deleted campaign template %s.', $name));

        return redirect()
            ->route('admin.campaigns.templates')
            ->with('status', sprintf('Template %s deleted. Campaigns already sent keep their wording.', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Give the template a name so it can be found later.',
            'body.required' => 'Write the message.',
        ]);

        if ($data['channel'] !== EventTemplates::CHANNEL_EMAIL) {
            $data['subject'] = null;
        }

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
