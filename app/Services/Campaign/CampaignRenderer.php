<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use Illuminate\Support\Collection;

/**
 * Turns a campaign body into the message one person receives.
 *
 * Three things are added on the way out, and each is per recipient: the links are
 * rewritten so a press can be counted, an invisible image is appended so an open
 * can be counted, and an unsubscribe line is appended because a marketing message
 * without one gets the sending domain blocked.
 *
 * The body itself is escaped. It is written by an operator, not a developer, and
 * the shell around it is HTML, so anything resembling markup arrives as the text
 * it was typed as.
 */
class CampaignRenderer
{
    /**
     * Placeholders a campaign may use.
     *
     * A short list on purpose. A campaign goes to people from many different
     * events, so anything specific to one entry would be wrong for most of the
     * list. Only what is true of every recipient is offered.
     *
     * @var array<string, string>
     */
    public const PLACEHOLDERS = [
        'name' => 'The person\'s name, or "there" when it is not known',
        'email' => 'Their email address',
        'site_name' => 'Name of this site',
        'unsubscribe_url' => 'Link that stops further messages. Added automatically if you leave it out',
    ];

    /**
     * A placeholder wrapped in its braces, ready to show on screen.
     *
     * Built here rather than in a view because Blade reads a literal '{{' as the
     * start of an echo and stops at the first '}}' it finds, which lands inside
     * the string and breaks compilation of the whole file.
     */
    public static function token(string $placeholder): string
    {
        return '{{' . $placeholder . '}}';
    }

    /**
     * Find every link in a body and record it against the campaign.
     *
     * Done once before sending rather than per recipient, so the click tracker
     * resolves by id. That is what keeps it from being an open redirect: the
     * tracker never takes a destination from the request.
     *
     * @return Collection<string, CampaignLink> keyed by the original URL
     */
    public function captureLinks(Campaign $campaign): Collection
    {
        $campaign->links()->delete();

        $urls = $this->extractUrls($campaign->body);
        $links = collect();

        foreach ($urls as $url) {
            $links[$url] = $campaign->links()->create(['url' => $url]);
        }

        return $links;
    }

    /**
     * The finished HTML for one recipient.
     *
     * @param  Collection<string, CampaignLink>  $links
     */
    public function renderEmail(Campaign $campaign, CampaignRecipient $recipient, Collection $links): string
    {
        $body = $this->substitute($campaign->body, $recipient);

        // Escaped first, then links are turned back into anchors. Doing it the
        // other way round would escape the anchors we just built.
        $body = e($body);

        $body = $this->linkify($body, $links, $recipient);

        return view('emails.campaign', [
            'body' => $body,
            'recipient' => $recipient,
            'campaign' => $campaign,
            // The finished subject, not the model's. The raw one still holds its
            // placeholders, and the document title would show them.
            'subject' => $this->subjectFor($campaign, $recipient),
            'openUrl' => route('campaign.open', $recipient->token),
            'unsubscribeUrl' => route('campaign.unsubscribe', $recipient->token),
        ])->render();
    }

    /**
     * The finished text for one SMS.
     *
     * No pixel and no rewritten links: there is nowhere to hide an image in a text
     * message, and a tracked link would eat characters that are being paid for.
     */
    public function renderSms(Campaign $campaign, CampaignRecipient $recipient): string
    {
        return $this->substitute($campaign->body, $recipient);
    }

    public function subjectFor(Campaign $campaign, CampaignRecipient $recipient): string
    {
        return $this->substitute((string) $campaign->subject, $recipient);
    }

    /**
     * Sample values, so a preview reads like a real message.
     *
     * @return array<string, string>
     */
    public function sampleValues(): array
    {
        return [
            'name' => 'Aisyah',
            'email' => 'aisyah@example.test',
            'site_name' => (string) config('app.name'),
            'unsubscribe_url' => url('/c/sample/unsubscribe'),
        ];
    }

    public function renderSample(string $text): string
    {
        $values = $this->sampleValues();

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $match) => $values[strtolower($match[1])] ?? $match[0],
            $text,
        );
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function substitute(string $text, CampaignRecipient $recipient): string
    {
        $contact = $recipient->contact;

        $values = [
            // "there" rather than an empty greeting: "Hello ," reads worse than
            // no name at all.
            'name' => $contact?->name ?: 'there',
            'email' => $recipient->address,
            'site_name' => (string) config('app.name'),
            'unsubscribe_url' => route('campaign.unsubscribe', $recipient->token),
        ];

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $match) => $values[strtolower($match[1])] ?? $match[0],
            $text,
        );
    }

    /**
     * Every http and https URL in a body, in the order they appear.
     *
     * @return array<int, string>
     */
    private function extractUrls(string $body): array
    {
        preg_match_all('#https?://[^\s<>"\'\)\]]+#i', $body, $matches);

        return array_values(array_unique(array_map(
            // Trailing punctuation belongs to the sentence, not the address.
            fn (string $url) => rtrim($url, '.,;:!?'),
            $matches[0] ?? [],
        )));
    }

    /**
     * Swap each recorded URL for its tracking address and make it clickable.
     *
     * @param  Collection<string, CampaignLink>  $links
     */
    private function linkify(string $escapedBody, Collection $links, CampaignRecipient $recipient): string
    {
        foreach ($links as $url => $link) {
            $tracked = route('campaign.click', ['token' => $recipient->token, 'link' => $link->id]);

            $escapedBody = str_replace(
                e($url),
                sprintf(
                    '<a href="%s" style="color:#1d4ed8;text-decoration:underline;">%s</a>',
                    e($tracked),
                    e($url),
                ),
                $escapedBody,
            );
        }

        return nl2br($escapedBody);
    }
}
