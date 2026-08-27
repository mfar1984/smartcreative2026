<?php

namespace App\Http\Controllers\Admin\Event;

use App\Http\Controllers\Controller;
use App\Models\EventTemplate;
use App\Services\AdminLogger;
use App\Services\EventTemplateRenderer;
use App\Support\EventTemplates;
use App\Support\MailSettings;
use App\Support\SmsSettings;
use App\Support\TelegramSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Settings that belong to the event module rather than to one event.
 *
 * Tabs are channels: the wording for email and the wording for SMS answer the
 * same moments but read differently, so each gets its own screen.
 */
class SettingsController extends Controller
{
    /**
     * Tab slug => label, icon and blurb.
     */
    public const TABS = [
        EventTemplates::CHANNEL_EMAIL => [
            'label' => 'Email Template',
            'icon' => 'mail',
            'description' => 'What registrants and players receive by email. Each message has its own audience, so the wording and the placeholders differ.',
        ],
        EventTemplates::CHANNEL_SMS => [
            'label' => 'SMS Template',
            'icon' => 'mobile',
            'description' => 'Short versions of the same messages. One SMS segment is 160 characters; longer messages are billed as several.',
        ],
        EventTemplates::CHANNEL_TELEGRAM => [
            'label' => 'Telegram Template',
            'icon' => 'send',
            'description' => 'What the staff group is told. A different set of moments from the other two tabs, because the office is one audience and hears about things no participant does.',
        ],
    ];

    /** Accent colour per tab, so each channel reads as its own screen. */
    private const ACCENTS = [
        EventTemplates::CHANNEL_EMAIL => 'blue',
        EventTemplates::CHANNEL_SMS => 'amber',
        EventTemplates::CHANNEL_TELEGRAM => 'purple',
    ];

    public function index(Request $request)
    {
        $tab = $this->resolveTab($request->query('tab'));

        $stored = EventTemplate::forChannel($tab);

        // Driven by the catalogue rather than by whatever rows exist, so a
        // template that has never been saved still appears, empty, instead of
        // being silently missing.
        $templates = [];

        // Driven by the catalogue for this channel, not by the participant set:
        // Telegram answers different moments.
        foreach (EventTemplates::templatesFor($tab) as $key => $definition) {
            $templates[$key] = [
                'definition' => $definition,
                'model' => $stored->get($key),
                'placeholders' => EventTemplates::placeholdersFor($key),
            ];
        }

        return view('admin.event.settings', [
            'tabs' => self::TABS,
            'activeTab' => $tab,
            'definition' => self::TABS[$tab],
            'accent' => self::ACCENTS[$tab] ?? 'blue',
            'hasSubject' => EventTemplates::hasSubject($tab),
            'templates' => $templates,
            'canUpdate' => $request->user()->hasPermission('event.settings.update'),

            // Whether anything can actually deliver on this channel. Said plainly
            // rather than letting an operator write wording that goes nowhere.
            'delivery' => match ($tab) {
                EventTemplates::CHANNEL_EMAIL => $this->emailDelivery(),
                EventTemplates::CHANNEL_TELEGRAM => $this->telegramDelivery(),
                default => $this->smsDelivery(),
            },
        ]);
    }

    public function update(Request $request, string $tab)
    {
        if (! EventTemplates::isChannel($tab)) {
            throw new NotFoundHttpException();
        }

        $isEmail = EventTemplates::hasSubject($tab);
        $keys = EventTemplates::keysFor($tab);

        $rules = [];

        foreach ($keys as $key) {
            $formKey = EventTemplates::formKey($key);

            $rules["templates.{$formKey}.body"] = ['required', 'string', 'max:10000'];
            $rules["templates.{$formKey}.is_active"] = ['boolean'];

            if ($isEmail) {
                $rules["templates.{$formKey}.subject"] = ['required', 'string', 'max:200'];
            }
        }

        $data = $request->validate($rules, $this->messages($tab));

        $changed = [];

        foreach ($keys as $key) {
            $row = $data['templates'][EventTemplates::formKey($key)];

            EventTemplate::updateOrCreate(
                ['key' => $key, 'channel' => $tab],
                [
                    'subject' => $isEmail ? $row['subject'] : null,
                    'body' => $row['body'],
                    'is_active' => (bool) ($row['is_active'] ?? false),
                ],
            );

            $changed[$key] = ($row['is_active'] ?? false) ? 'active' : 'inactive';
        }

        AdminLogger::activity(
            'event.settings.templates',
            sprintf('Updated %s templates.', self::TABS[$tab]['label']),
        );

        AdminLogger::audit(
            new EventTemplate(['key' => '*', 'channel' => $tab]),
            'templates.updated',
            null,
            $changed,
        );

        return redirect()
            ->route('admin.event.settings', ['tab' => $tab])
            ->with('status', sprintf('%ss saved.', self::TABS[$tab]['label']));
    }

    /**
     * Render one template with invented data, so the wording can be checked
     * before anyone real receives it.
     */
    public function preview(Request $request, string $tab, string $key, EventTemplateRenderer $renderer)
    {
        if (! EventTemplates::isChannel($tab) || EventTemplates::definition($key) === null) {
            throw new NotFoundHttpException();
        }

        $template = EventTemplate::lookup($key, $tab);

        if ($template === null) {
            return back()->withErrors(['preview' => 'Save this template before previewing it.']);
        }

        $rendered = $renderer->renderSample($template);

        return view('admin.event.template-preview', [
            'template' => $template,
            'definition' => EventTemplates::definition($key),
            'channelLabel' => self::TABS[$tab]['label'],
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
        ]);
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function emailDelivery(): array
    {
        // The saved profile wins over .env, so it is applied before reporting.
        MailSettings::apply();

        $profile = MailSettings::effective();
        $ready = filled($profile['Host']) || $profile['Mailer'] === 'log';

        return [
            'ready' => $ready,
            'summary' => $ready
                ? sprintf('Mail goes out through %s as %s.', $profile['Mailer'], $profile['From Address'])
                : 'No mail transport is configured.',
            'settingsRoute' => route('admin.settings.integration', ['tab' => 'email']),

            'wired' => true,
            'note' => 'These are sent. Registration wording goes out when a form is submitted, payment wording when the gateway confirms, and each message is queued, so a worker has to be running for anything to leave.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function smsDelivery(): array
    {
        return [
            'ready' => SmsSettings::isReady(),
            'summary' => SmsSettings::summary(),
            'settingsRoute' => route('admin.settings.integration', ['tab' => 'sms']),

            // Wired, but gated twice over. Saying "wired" alone would imply these
            // are going out when the alert switches may all be off.
            'wired' => SmsSettings::canSend(),
            'note' => SmsSettings::canSend()
                ? 'These are sent over SMS for whichever alerts are switched on under Integration > SMS. A template switched off here is not sent even when its alert is on.'
                : 'Nothing is texted yet. ' . SmsSettings::summary() . ' Switch the channel and its alerts on under Integration > SMS.',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(string $channel): array
    {
        $messages = [];

        foreach (EventTemplates::keysFor($channel) as $key) {
            $label = EventTemplates::definition($key)['label'];
            $formKey = EventTemplates::formKey($key);

            $messages["templates.{$formKey}.body.required"] = sprintf('%s needs a message body.', $label);

            if (EventTemplates::hasSubject($channel)) {
                $messages["templates.{$formKey}.subject.required"] = sprintf('%s needs a subject line.', $label);
            }
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramDelivery(): array
    {
        return [
            'ready' => TelegramSettings::isReady(),
            'summary' => TelegramSettings::summary(),
            'settingsRoute' => route('admin.settings.integration', ['tab' => 'telegram']),

            'wired' => TelegramSettings::canSend(),
            'note' => TelegramSettings::canSend()
                ? 'These are posted into the staff group for whichever alerts are switched on under Integration > Telegram. A template switched off here is not posted even when its alert is on.'
                : 'Nothing is posted yet. ' . TelegramSettings::summary() . ' Switch the bot and its alerts on under Integration > Telegram.',
        ];
    }

    private function resolveTab(?string $tab): string
    {
        return EventTemplates::isChannel($tab) ? (string) $tab : EventTemplates::CHANNEL_EMAIL;
    }
}
