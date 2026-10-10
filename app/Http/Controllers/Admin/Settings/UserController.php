<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreHandlerRequest;
use App\Http\Requests\Admin\StoreSponsorRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateHandlerRequest;
use App\Http\Requests\Admin\UpdateSponsorRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminLogger;
use App\Support\CouponSponsorship;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserController extends Controller
{
    /**
     * Tab slug => label and icon name understood by the admin icon component.
     */
    public const TABS = [
        'users' => ['label' => 'Users', 'icon' => 'users'],
        'handler' => ['label' => 'Handler', 'icon' => 'trophy'],
        'sponsorship' => ['label' => 'Sponsorship', 'icon' => 'cash'],
    ];

    /**
     * The permission each tab needs before it is drawn at all.
     *
     * Three separate sets on purpose: a role can be given handler management, or
     * sponsorship management, without being given administrator management. A tab
     * the role cannot see is not rendered and cannot be reached by editing the
     * query string either.
     */
    private const TAB_PERMISSIONS = [
        'users' => 'users.view',
        'handler' => 'handlers.view',
        'sponsorship' => 'sponsors.view',
    ];

    public function index(Request $request)
    {
        $tabs = array_filter(
            self::TABS,
            fn (array $tab, string $slug) => $request->user()->hasPermission(self::TAB_PERMISSIONS[$slug]),
            ARRAY_FILTER_USE_BOTH,
        );

        $activeTab = $this->resolveTab($request->query('tab'), $tabs);

        $search = trim((string) $request->query('q'));
        $sponsors = null;

        $status = $request->query('status');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : null;

        $roleId = $request->query('role');
        $roleId = is_numeric($roleId) ? (int) $roleId : null;

        if ($activeTab === 'sponsorship') {
            $sponsors = $this->sponsorRows($search, $status);
        }

        return view('admin.settings.users', [
            'tabs' => $tabs,
            'activeTab' => $activeTab,

            // Only the tab being drawn is queried, so the other lists cost nothing.
            'users' => $activeTab === 'users' ? $this->userRows($search, $status, $roleId) : null,
            'handlers' => $activeTab === 'handler' ? $this->handlerRows($search, $status) : null,
            'sponsors' => $sponsors,

            /*
             | What each sponsorship holds and what it has actually given away,
             | worked out by CouponSponsorship so this tab and the sponsor's own
             | screen can never show two different answers. That guarantee is the
             | reason this is not a clever join here: a second implementation is how
             | the two would come to disagree about somebody's money.
             */
            'sponsorFigures' => $sponsors === null ? [] : $this->sponsorFigures($sponsors),
            'sponsorBatches' => $sponsors === null ? [] : $this->sponsorBatches($sponsors),

            'roles' => Role::query()->orderBy('name')->get(),
            'search' => $search,
            'status' => $status,
            'roleId' => $roleId,
            'isFiltered' => $search !== '' || $status !== null || ($activeTab === 'users' && $roleId !== null),
            'canCreate' => $request->user()->hasPermission('users.create'),
            'canUpdate' => $request->user()->hasPermission('users.update'),
            'canDelete' => $request->user()->hasPermission('users.delete'),
            'canCreateHandler' => $request->user()->hasPermission('handlers.create'),
            'canUpdateHandler' => $request->user()->hasPermission('handlers.update'),
            'canDeleteHandler' => $request->user()->hasPermission('handlers.delete'),
            'canCreateSponsor' => $request->user()->hasPermission('sponsors.create'),
            'canUpdateSponsor' => $request->user()->hasPermission('sponsors.update'),
            'canDeleteSponsor' => $request->user()->hasPermission('sponsors.delete'),
        ]);
    }

    /**
     * The Users tab: administrator accounts, handlers and sponsors excluded.
     *
     * The exclusions are what keep the three tabs from overlapping. Without them
     * an account would appear on two lists and be editable from either, which is
     * the opposite of keeping the permissions apart.
     */
    private function userRows(string $search, ?string $status, ?int $roleId): LengthAwarePaginator
    {
        return User::query()
            ->with('role:id,name,slug,is_active')
            ->where('is_handler', false)
            ->where('is_sponsor', false)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($roleId !== null, fn ($query) => $query->where('role_id', $roleId))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();
    }

    /**
     * The Handler tab: handler accounts and the tournaments they run.
     *
     * The relation is eager loaded because the tournaments column reads it on
     * every row. Its own page name, so a page number from one tab is not carried
     * into the other.
     */
    private function handlerRows(string $search, ?string $status): LengthAwarePaginator
    {
        return User::query()
            ->with('handledTournaments')
            ->where('is_handler', true)
            // Said out loud rather than relied on: the two flags are written as
            // constants by different endpoints, so an account can only ever be one
            // of the two, and this tab must not list a sponsor even so.
            ->where('is_sponsor', false)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15, ['*'], 'handler_page')
            ->withQueryString();
    }

    /**
     * The Sponsorship tab: monitor-only accounts and what each one funded.
     *
     * What each one funded is NOT eager loaded off sponsoredAllocations any more.
     * That relation is the raw column, and a batch-level sponsorship covers blocks
     * that leave it blank — so it under-reported, and a shared-code batch has no
     * allocation for it to find at all. The figures are asked of CouponSponsorship
     * per row instead, which resolves both levels of the rule. Its own page name, so
     * a page number from one tab is not carried into another.
     */
    private function sponsorRows(string $search, ?string $status): LengthAwarePaginator
    {
        return User::query()
            ->where('is_sponsor', true)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15, ['*'], 'sponsor_page')
            ->withQueryString();
    }

    /**
     * Sponsor id => every figure for that sponsorship, CouponSponsorship's own.
     *
     * Asked once per row rather than worked out here in one clever join. A page of
     * fifteen is fifteen cheap queries, and the alternative is a second
     * implementation of the same figures — which is how this tab and the sponsor's
     * own screen would end up disagreeing about their money.
     *
     * @param  LengthAwarePaginator<int, User>  $sponsors
     * @return array<int, array<string, mixed>>
     */
    private function sponsorFigures(LengthAwarePaginator $sponsors): array
    {
        $figures = [];

        foreach ($sponsors as $sponsor) {
            $figures[$sponsor->id] = CouponSponsorship::forSponsor($sponsor);
        }

        return $figures;
    }

    /**
     * Sponsor id => the names of the coupons that sponsorship funds.
     *
     * Both halves of the rule: a batch it holds a resolved block on, and a shared
     * batch it funded outright. The column is the whole reason somebody opens this
     * tab, so it has to name the shared ones too.
     *
     * @param  LengthAwarePaginator<int, User>  $sponsors
     * @return array<int, array<int, string>>
     */
    private function sponsorBatches(LengthAwarePaginator $sponsors): array
    {
        $batches = [];

        foreach ($sponsors as $sponsor) {
            $batches[$sponsor->id] = $sponsor->sponsoredBlocks()
                ->with('coupon:id,name')
                ->get()
                ->map(fn ($block) => (string) ($block->coupon?->name ?? ''))
                ->concat($sponsor->sponsoredSharedBatches()->pluck('name'))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
        }

        return $batches;
    }

    /**
     * @param  array<string, array<string, string>>  $allowed  Tabs this role may see.
     */
    private function resolveTab(?string $tab, array $allowed): string
    {
        if (array_key_exists((string) $tab, $allowed)) {
            return (string) $tab;
        }

        // Falls back to the first tab the role may see rather than always to
        // users, because users itself can be the one that is not allowed.
        return (string) (array_key_first($allowed) ?? 'users');
    }

    /**
     * Which tab an account belongs to, decided by its flags and nothing else.
     *
     * One answer per account, so the three lists cannot overlap however the row
     * was written.
     */
    private function tabFor(User $user): string
    {
        if ($user->isHandler()) {
            return 'handler';
        }

        return $user->isSponsor() ? 'sponsorship' : 'users';
    }

    /**
     * Refuse a row that belongs to another tab.
     *
     * The three tabs are three lists over one table, so an id from one is a valid
     * route parameter on another's routes. Without this a role granted only
     * sponsorship management could edit an administrator by changing the number in
     * the URL, which would undo the whole point of keeping the permissions apart.
     */
    private function refuseCrossTab(User $user, string $tab): void
    {
        if ($this->tabFor($user) === $tab) {
            return;
        }

        throw new AccessDeniedHttpException(sprintf(
            'That account is managed on the %s tab.',
            self::TABS[$this->tabFor($user)]['label'],
        ));
    }

    public function store(StoreUserRequest $request)
    {
        $user = User::create($request->validated());

        AdminLogger::activity('users.create', sprintf('Created user %s.', $user->logLabel()));
        AdminLogger::audit($user, 'created', null, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
        ]);

        return redirect()
            ->route('admin.settings.users')
            ->with('status', sprintf('User %s created.', $user->username));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $this->refuseCrossTab($user, 'users');

        $validated = $request->validated();

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
        ];

        // Stop an administrator locking themselves out of their own session by
        // deactivating or demoting their own account from this screen.
        if ($user->is($request->user())) {
            if (! $validated['is_active']) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own account.',
                ]);
            }

            if ((int) $validated['role_id'] !== (int) $user->role_id) {
                throw ValidationException::withMessages([
                    'role_id' => 'You cannot change your own role. Ask another administrator to do it.',
                ]);
            }
        }

        // A blank password field leaves the existing password in place.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $user->update($validated);

        AdminLogger::activity('users.update', sprintf('Updated user %s.', $user->logLabel()));
        AdminLogger::audit($user, 'updated', $before, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
            'password' => array_key_exists('password', $validated) ? '[redacted]' : null,
        ]);

        return redirect()
            ->route('admin.settings.users')
            ->with('status', sprintf('User %s updated.', $user->username));
    }

    public function destroy(Request $request, User $user)
    {
        $this->refuseCrossTab($user, 'users');

        if ($user->is($request->user())) {
            return redirect()
                ->route('admin.settings.users')
                ->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Never leave the system without a usable super admin.
        if ($user->role?->isSuperAdmin() && $this->activeSuperAdminCount() <= 1) {
            return redirect()
                ->route('admin.settings.users')
                ->withErrors(['user' => 'This is the last active Super Admin. Create another one before deleting this account.']);
        }

        $label = $user->logLabel();

        AdminLogger::audit($user, 'deleted', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
        ], null);

        $user->delete();

        AdminLogger::activity('users.delete', sprintf('Deleted user %s.', $label));

        return redirect()
            ->route('admin.settings.users')
            ->with('status', 'User deleted.');
    }

    /* ---------------------------------------------------------------------
     | Handler tab
     *
     | A handler is an ordinary users row carrying the handler role with
     | is_handler set. These three endpoints are the only ones that write that
     | pair, and they write it as a constant.
     * ------------------------------------------------------------------ */

    /**
     * Create a handler account.
     *
     * The role is not a field on this form and no role is ever read from the
     * request. If it were, a role granted only handlers.create could mint a super
     * admin by posting a role_id, which is a straight privilege escalation. So the
     * role is looked up by its fixed slug and written last, where nothing in the
     * payload can reach it.
     */
    public function storeHandler(StoreHandlerRequest $request)
    {
        $role = Role::where('slug', Role::HANDLER)->firstOrFail();

        $attributes = $request->validated();
        $attributes['role_id'] = $role->id;
        $attributes['is_handler'] = true;

        $user = User::create($attributes);

        AdminLogger::activity('handlers.create', sprintf('Created handler %s.', $user->logLabel()));
        AdminLogger::audit($user, 'created', null, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_handler' => true,
            'is_active' => $user->is_active,
        ]);

        return redirect()
            ->route('admin.settings.users', ['tab' => 'handler'])
            ->with('status', sprintf('Handler %s created.', $user->username));
    }

    public function updateHandler(UpdateHandlerRequest $request, User $user)
    {
        $this->refuseCrossTab($user, 'handler');

        $validated = $request->validated();

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
        ];

        // A blank password field leaves the existing password in place.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        // Neither the role nor the flag is in the payload, so an edit here cannot
        // turn a handler into anything else.
        $user->update($validated);

        AdminLogger::activity('handlers.update', sprintf('Updated handler %s.', $user->logLabel()));
        AdminLogger::audit($user, 'updated', $before, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'password' => array_key_exists('password', $validated) ? '[redacted]' : null,
        ]);

        return redirect()
            ->route('admin.settings.users', ['tab' => 'handler'])
            ->with('status', sprintf('Handler %s updated.', $user->username));
    }

    /**
     * Delete a handler account.
     *
     * The pivot cascades, so each tournament it was assigned to simply loses that
     * handler and is otherwise untouched. How many is recorded, because that is
     * the part somebody will want to know afterwards.
     */
    public function destroyHandler(Request $request, User $user)
    {
        $this->refuseCrossTab($user, 'handler');

        $label = $user->logLabel();
        $assigned = $user->handledTournaments()->count();

        AdminLogger::audit($user, 'deleted', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_handler' => true,
            'assigned_tournaments' => $assigned,
        ], null);

        $user->delete();

        AdminLogger::activity('handlers.delete', $assigned === 0
            ? sprintf('Deleted handler %s.', $label)
            : sprintf('Deleted handler %s, unassigning %d tournament(s).', $label, $assigned));

        return redirect()
            ->route('admin.settings.users', ['tab' => 'handler'])
            ->with('status', 'Handler deleted.');
    }

    /* ---------------------------------------------------------------------
     | Sponsorship tab
     *
     | A sponsor is an ordinary users row carrying the sponsor role with
     | is_sponsor set. These three endpoints are the only ones that write that
     | pair, and they write it as a constant.
     |
     | A sponsorship account is MONITOR AND VIEW ONLY: the role holds nothing but
     | admin access and its own area, so it creates, edits and deletes nothing.
     | Which blocks of codes it funded is tagged on the coupon side by staff, not
     | here — this screen opens the account and records what was pledged.
     * ------------------------------------------------------------------ */

    /**
     * Create a sponsorship account.
     *
     * The role is not a field on this form and no role is ever read from the
     * request. If it were, a role granted only sponsors.create could mint a super
     * admin by posting a role_id, which is a straight privilege escalation. So the
     * role is looked up by its fixed slug and written last, where nothing in the
     * payload can reach it.
     */
    public function storeSponsor(StoreSponsorRequest $request)
    {
        $role = Role::where('slug', Role::SPONSOR)->firstOrFail();

        $attributes = $request->validated();
        $attributes['role_id'] = $role->id;
        $attributes['is_sponsor'] = true;

        $user = User::create($attributes);

        AdminLogger::activity('sponsors.create', sprintf('Created sponsorship account %s.', $user->logLabel()));
        AdminLogger::audit($user, 'created', null, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_sponsor' => true,
            'sponsor_committed_amount' => $user->sponsor_committed_amount,
            'is_active' => $user->is_active,
        ]);

        return redirect()
            ->route('admin.settings.users', ['tab' => 'sponsorship'])
            ->with('status', sprintf('Sponsorship account %s created.', $user->username));
    }

    public function updateSponsor(UpdateSponsorRequest $request, User $user)
    {
        $this->refuseCrossTab($user, 'sponsorship');

        $validated = $request->validated();

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'sponsor_committed_amount' => $user->sponsor_committed_amount,
            'is_active' => $user->is_active,
        ];

        // A blank password field leaves the existing password in place.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        // Neither the role nor the flag is in the payload, so an edit here cannot
        // turn a sponsor into anything else.
        $user->update($validated);

        AdminLogger::activity('sponsors.update', sprintf('Updated sponsorship account %s.', $user->logLabel()));
        AdminLogger::audit($user, 'updated', $before, [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'sponsor_committed_amount' => $user->sponsor_committed_amount,
            'is_active' => $user->is_active,
            'password' => array_key_exists('password', $validated) ? '[redacted]' : null,
        ]);

        return redirect()
            ->route('admin.settings.users', ['tab' => 'sponsorship'])
            ->with('status', sprintf('Sponsorship account %s updated.', $user->username));
    }

    /**
     * Delete a sponsorship account.
     *
     * What it funded is RELEASED rather than destroyed: sponsor_user_id is nulled by
     * the foreign key on both the blocks and the batches, so the codes, who holds
     * them and every use of them stay exactly as they are. Codes that have been
     * printed and handed out must not disappear because an account was closed. How
     * much was released is recorded, because that is the part somebody will want to
     * know afterwards — counted at both levels, since a batch-level tag releases the
     * same way and a shared batch has no block to count at all.
     */
    public function destroySponsor(Request $request, User $user)
    {
        $this->refuseCrossTab($user, 'sponsorship');

        $label = $user->logLabel();
        $blocks = $user->sponsoredAllocations()->count()
            + Coupon::query()->where('sponsor_user_id', $user->id)->count();

        AdminLogger::audit($user, 'deleted', [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_sponsor' => true,
            'sponsor_committed_amount' => $user->sponsor_committed_amount,
            'sponsored_blocks' => $blocks,
        ], null);

        $user->delete();

        AdminLogger::activity('sponsors.delete', $blocks === 0
            ? sprintf('Deleted sponsorship account %s.', $label)
            : sprintf('Deleted sponsorship account %s, releasing %d coupon block(s) or batch(es).', $label, $blocks));

        return redirect()
            ->route('admin.settings.users', ['tab' => 'sponsorship'])
            ->with('status', 'Sponsorship account deleted.');
    }

    private function activeSuperAdminCount(): int
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', Role::SUPER_ADMIN))
            ->count();
    }
}
