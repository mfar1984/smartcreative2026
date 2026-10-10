<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Services\AdminLogger;
use App\Services\Security\SecurityEventRecorder;
use App\Support\LocalDateRange;
use App\Support\LocalTime;
use App\Support\SecuritySettings;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LoggingController extends Controller
{
    public const TABS = [
        'activity' => ['label' => 'Activity Logging', 'icon' => 'activity'],
        'audit' => ['label' => 'Audit Log', 'icon' => 'clipboard'],
        'security' => ['label' => 'Security Log', 'icon' => 'shield'],
    ];

    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $user = $request->user();
        $canViewAudit = $user->hasPermission('logs.audit.view');
        $canViewSecurity = $user->hasPermission('logs.security.view');

        $tab = $this->resolveTab($request->query('tab'));

        // The route guards logs.activity.view. Reading the audit trail is a
        // separate permission, so fall back rather than leak it through a URL.
        if ($tab === 'audit' && ! $canViewAudit) {
            $tab = 'activity';
        }

        // The Security Log is a third permission, gated the same way for the same
        // reason: who was refused, from which address, is not something every role
        // that may read the activity list should see.
        if ($tab === 'security' && ! $canViewSecurity) {
            $tab = 'activity';
        }

        $tabs = ['activity' => self::TABS['activity']];

        if ($canViewAudit) {
            $tabs['audit'] = self::TABS['audit'];
        }

        if ($canViewSecurity) {
            $tabs['security'] = self::TABS['security'];
        }

        $filters = $this->filters($request);

        $securityEntries = $tab === 'security'
            ? $this->securityQuery($filters, true)->paginate(self::PER_PAGE)->withQueryString()
            : null;

        return view('admin.settings.logging', [
            'tabs' => $tabs,
            'activeTab' => $tab,
            'filters' => $filters,
            'isFiltered' => collect($filters)->except('tab')->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty(),

            'activityEntries' => $tab === 'activity' ? $this->activityQuery($filters, true)->paginate(self::PER_PAGE)->withQueryString() : null,
            'levelCounts' => $tab === 'activity' ? $this->levelCounts($filters) : [],
            'categories' => $tab === 'activity' ? ActivityLog::query()->distinct()->orderBy('category')->pluck('category')->all() : [],
            'levels' => AdminLogger::LEVELS,

            'auditEntries' => $tab === 'audit' ? $this->auditQuery($filters, true)->paginate(self::PER_PAGE)->withQueryString() : null,
            'eventCounts' => $tab === 'audit' ? $this->eventCounts($filters) : [],

            'securityEntries' => $securityEntries,
            'severities' => SecurityEvent::SEVERITIES,
            'severityCounts' => $tab === 'security' ? $this->severityCounts($filters) : [],
            'ipTotals' => $securityEntries !== null ? $this->ipTotals($securityEntries->pluck('ip_address')->all()) : [],
            'securityBanArmed' => SecuritySettings::securityBanEnabled(),
            'securityBanAfter' => SecuritySettings::securityBanAfterEvents(),
            'securityBanWindow' => SecuritySettings::securityBanWindowMinutes(),
            'collapseMinutes' => SecurityEventRecorder::COLLAPSE_MINUTES,

            'actors' => $this->actors($tab),
        ]);
    }

    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::TABS) ? (string) $tab : 'activity';
    }

    /**
     * Normalised filter values, so the query builders and the view agree.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $level = $request->query('level');
        $event = $request->query('event');
        $severity = $request->query('severity');

        return [
            'q' => trim((string) $request->query('q')) ?: null,
            'level' => array_key_exists((string) $level, AdminLogger::LEVELS) ? (string) $level : null,
            'category' => trim((string) $request->query('category')) ?: null,
            'event' => is_string($event) && $event !== '' ? $event : null,
            'severity' => array_key_exists((string) $severity, SecurityEvent::SEVERITIES) ? (string) $severity : null,
            'ip' => trim((string) $request->query('ip')) ?: null,
            'actor' => trim((string) $request->query('actor')) ?: null,
            'from' => $this->parseDate($request->query('from')),
            'to' => $this->parseDate($request->query('to')),
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            // Normalised to a bare Y-m-d. The day it names is a local day; which
            // UTC instants that day covers is decided later, in the display zone,
            // not here. A malformed value is rejected so a bad string cannot widen
            // the range to something nobody asked for.
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The UTC instant a local 'from' day begins at.
     *
     * The user picks a date on the office clock (App\Support\LocalTime::zone()),
     * but created_at is stored in UTC. So 'from' means the start of that day in
     * the display zone, converted to the UTC instant the column is compared on.
     */
    private function fromInstant(?string $date): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date, LocalTime::zone())->startOfDay()->utc();
    }

    /**
     * The UTC instant a local 'to' day ends at.
     *
     * End of that day in the display zone, converted to UTC, so an inclusive
     * range catches an entry made at any point of the local day — including one
     * written near local midnight that lands on a different UTC calendar day.
     */
    private function toInstant(?string $date): ?Carbon
    {
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date, LocalTime::zone())->endOfDay()->utc();
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function activityQuery(array $filters, bool $applyLevel): Builder
    {
        return ActivityLog::query()
            ->when($filters['q'], fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('description', 'like', "%{$term}%")
                    ->orWhere('action', 'like', "%{$term}%")
                    ->orWhere('actor_label', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%");
            }))
            ->when($applyLevel ? $filters['level'] : null, fn ($query, $level) => $query->where('level', $level))
            ->when($filters['category'], fn ($query, $category) => $query->where('category', $category))
            ->when($filters['actor'], fn ($query, $actor) => $query->where('actor_label', $actor))
            ->when($this->fromInstant($filters['from']), fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($this->toInstant($filters['to']), fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->latest('created_at');
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function auditQuery(array $filters, bool $applyEvent): Builder
    {
        return AuditLog::query()
            ->when($filters['q'], fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('event', 'like', "%{$term}%")
                    ->orWhere('auditable_type', 'like', "%{$term}%")
                    ->orWhere('actor_label', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%");
            }))
            ->when($applyEvent ? $filters['event'] : null, fn ($query, $event) => $query->where('event', $event))
            ->when($filters['actor'], fn ($query, $actor) => $query->where('actor_label', $actor))
            ->when($this->fromInstant($filters['from']), fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($this->toInstant($filters['to']), fn ($query, $to) => $query->where('created_at', '<=', $to))
            ->latest('created_at');
    }

    /**
     * The Security Log: refusals and noticed probes, most recent activity first.
     *
     * Filtered on last_seen_at rather than created_at, because last_seen_at is what
     * the screen shows: a row collapses repeats, so "when did this address last get
     * refused" is the question a date filter is being asked.
     *
     * The boundaries go through LocalDateRange, which CONVERTS a local day into the
     * UTC instants it spans rather than comparing a UTC column against a local date
     * string. That bug has shipped four times in this project; the two tabs above
     * carry their own copy of the same conversion, and this is the shared one.
     *
     * @param  array<string, string|null>  $filters
     */
    private function securityQuery(array $filters, bool $applySeverity): Builder
    {
        return SecurityEvent::query()
            ->when($filters['q'], fn ($query, $term) => $query->where(function ($inner) use ($term) {
                $inner->where('description', 'like', "%{$term}%")
                    ->orWhere('type', 'like', "%{$term}%")
                    ->orWhere('path', 'like', "%{$term}%")
                    ->orWhere('actor_label', 'like', "%{$term}%");
            }))
            ->when($applySeverity ? $filters['severity'] : null, fn ($query, $severity) => $query->where('severity', $severity))
            ->when($filters['ip'], fn ($query, $ip) => $query->where('ip_address', 'like', "%{$ip}%"))
            ->when($filters['actor'], fn ($query, $actor) => $query->where('actor_label', $actor))
            ->when(LocalDateRange::parse($filters['from']), fn ($query, $date) => $query->where('last_seen_at', '>=', LocalDateRange::startsAt($date)))
            ->when(LocalDateRange::parse($filters['to']), fn ($query, $date) => $query->where('last_seen_at', '<=', LocalDateRange::endsAt($date)))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');
    }

    /**
     * Counts for the severity chips, with the severity filter itself left out so
     * every chip keeps showing how many rows it would bring back.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    private function severityCounts(array $filters): array
    {
        $counts = $this->securityQuery($filters, false)
            ->reorder()
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->all();

        $result = [];

        foreach (array_keys(SecurityEvent::SEVERITIES) as $severity) {
            $result[$severity] = (int) ($counts[$severity] ?? 0);
        }

        return $result;
    }

    /**
     * How many times each address on this page has been seen in total, ever.
     *
     * The column that makes a row readable: 3 says somebody fumbled a permission,
     * 480 says somebody is walking the URL space. Summed over `hits`, not counted
     * over rows, because repeats collapse. One grouped query for the whole page,
     * filters deliberately ignored — the total is about the address, not about what
     * is currently on screen.
     *
     * @param  array<int, string|null>  $ips
     * @return array<string, int>
     */
    private function ipTotals(array $ips): array
    {
        $ips = array_values(array_unique(array_filter($ips)));

        if ($ips === []) {
            return [];
        }

        return SecurityEvent::query()
            ->whereIn('ip_address', $ips)
            ->selectRaw('ip_address, SUM(hits) as total')
            ->groupBy('ip_address')
            ->pluck('total', 'ip_address')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Counts for the level chips.
     *
     * The level filter itself is left out so every chip keeps showing how many
     * entries it would bring back.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    private function levelCounts(array $filters): array
    {
        $counts = $this->activityQuery($filters, false)
            ->reorder()
            ->selectRaw('level, COUNT(*) as total')
            ->groupBy('level')
            ->pluck('total', 'level')
            ->all();

        $result = [];

        foreach (array_keys(AdminLogger::LEVELS) as $level) {
            $result[$level] = (int) ($counts[$level] ?? 0);
        }

        return $result;
    }

    /**
     * Counts for the audit event chips, highest first.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    private function eventCounts(array $filters): array
    {
        return $this->auditQuery($filters, false)
            ->reorder()
            ->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')
            ->orderByDesc('total')
            ->pluck('total', 'event')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Distinct actor labels for the user filter.
     *
     * @return array<int, string>
     */
    private function actors(string $tab): array
    {
        $model = match ($tab) {
            'audit' => AuditLog::query(),
            'security' => SecurityEvent::query(),
            default => ActivityLog::query(),
        };

        return $model
            ->whereNotNull('actor_label')
            ->distinct()
            ->orderBy('actor_label')
            ->pluck('actor_label')
            ->all();
    }
}
