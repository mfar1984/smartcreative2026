<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Services\AdminLogger;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LoggingController extends Controller
{
    public const TABS = [
        'activity' => ['label' => 'Activity Logging', 'icon' => 'activity'],
        'audit' => ['label' => 'Audit Log', 'icon' => 'clipboard'],
    ];

    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $user = $request->user();
        $canViewAudit = $user->hasPermission('logs.audit.view');

        $tab = $this->resolveTab($request->query('tab'));

        // The route guards logs.activity.view. Reading the audit trail is a
        // separate permission, so fall back rather than leak it through a URL.
        if ($tab === 'audit' && ! $canViewAudit) {
            $tab = 'activity';
        }

        $filters = $this->filters($request);

        return view('admin.settings.logging', [
            'tabs' => $canViewAudit ? self::TABS : ['activity' => self::TABS['activity']],
            'activeTab' => $tab,
            'filters' => $filters,
            'isFiltered' => collect($filters)->except('tab')->filter(fn ($value) => $value !== null && $value !== '')->isNotEmpty(),

            'activityEntries' => $tab === 'activity' ? $this->activityQuery($filters, true)->paginate(self::PER_PAGE)->withQueryString() : null,
            'levelCounts' => $tab === 'activity' ? $this->levelCounts($filters) : [],
            'categories' => $tab === 'activity' ? ActivityLog::query()->distinct()->orderBy('category')->pluck('category')->all() : [],
            'levels' => AdminLogger::LEVELS,

            'auditEntries' => $tab === 'audit' ? $this->auditQuery($filters, true)->paginate(self::PER_PAGE)->withQueryString() : null,
            'eventCounts' => $tab === 'audit' ? $this->eventCounts($filters) : [],

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

        return [
            'q' => trim((string) $request->query('q')) ?: null,
            'level' => array_key_exists((string) $level, AdminLogger::LEVELS) ? (string) $level : null,
            'category' => trim((string) $request->query('category')) ?: null,
            'event' => is_string($event) && $event !== '' ? $event : null,
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
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
            ->when($applyLevel && $filters['level'], fn ($query, $level) => $query->where('level', $level))
            ->when($filters['category'], fn ($query, $category) => $query->where('category', $category))
            ->when($filters['actor'], fn ($query, $actor) => $query->where('actor_label', $actor))
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
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
            ->when($applyEvent && $filters['event'], fn ($query, $event) => $query->where('event', $event))
            ->when($filters['actor'], fn ($query, $actor) => $query->where('actor_label', $actor))
            ->when($filters['from'], fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('created_at');
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
        $model = $tab === 'audit' ? AuditLog::query() : ActivityLog::query();

        return $model
            ->whereNotNull('actor_label')
            ->distinct()
            ->orderBy('actor_label')
            ->pluck('actor_label')
            ->all();
    }
}
