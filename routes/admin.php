<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Event\AnalyticReportingController;
use App\Http\Controllers\Admin\Event\AttendanceController;
use App\Http\Controllers\Admin\Event\CollectionController;
use App\Http\Controllers\Admin\Campaign\AudienceController;
use App\Http\Controllers\Admin\Campaign\CampaignController;
use App\Http\Controllers\Admin\Campaign\CampaignReportController;
use App\Http\Controllers\Admin\Campaign\CampaignTemplateController;
use App\Http\Controllers\Admin\Event\IdentityCardController;
use App\Http\Controllers\Admin\Event\ParticipantController;
use App\Http\Controllers\Admin\Payment\PaymentController;
use App\Http\Controllers\Admin\Portfolio\GalleryController as PortfolioGalleryController;
use App\Http\Controllers\Admin\Portfolio\ProjectController as PortfolioProjectController;
use App\Http\Controllers\Admin\Shop\CategoryController as ShopCategoryController;
use App\Http\Controllers\Admin\Shop\OrderController as ShopOrderController;
use App\Http\Controllers\Admin\Shop\TrackingController as ShopTrackingController;
use App\Http\Controllers\Admin\Shop\ProductController as ShopProductController;
use App\Http\Controllers\Admin\Shop\SettingsController as ShopSettingsController;
use App\Http\Controllers\Admin\Event\RegistrationController as EventRegistrationController;
use App\Http\Controllers\Admin\Event\SettingsController as EventSettingsController;
use App\Http\Controllers\Admin\Event\WifiController as EventWifiController;
use App\Http\Controllers\Admin\Settings\EasyParcelController;
use App\Http\Controllers\Admin\Settings\GeneralConfigController;
use App\Http\Controllers\Admin\Settings\IntegrationController;
use App\Http\Controllers\Admin\Settings\LoggingController;
use App\Http\Controllers\Admin\Settings\RoleController;
use App\Http\Controllers\Admin\Settings\UserController;
use App\Http\Controllers\Admin\Tournament\HallOfFameController;
use App\Http\Controllers\Admin\Tournament\MatchController;
use App\Http\Controllers\Admin\Tournament\PointRuleController;
use App\Http\Controllers\Admin\Tournament\StageController;
use App\Http\Controllers\Admin\Tournament\StandingController;
use App\Http\Controllers\Admin\Tournament\TournamentController;
use App\Http\Controllers\Admin\Tournament\TournamentSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| There is deliberately no registration route. The first super admin is
| created by AdminUserSeeder; every account after that is created from
| within User Management by someone who already has access.
|
*/

Route::prefix('admin')->name('admin.')->group(function () {

    /*
    | Sign in. Throttled at the route, per IP, as a second line of defence behind
    | the per username limiter in LoginRequest. The limit is the admin-login named
    | limiter (AppServiceProvider), read from General Config, Security on every
    | request; its default of 10 a minute is the old throttle:10,1.
    |
    | The GET is never throttled and never blocked, not even for a banned address:
    | a super admin can sign in through a ban, and this form is the way in.
    |
    | The IP ban, this limiter and the IP allowlist apply to these two routes and to
    | the authenticated admin group below, and to nothing else. Never to the public
    | website, registration, checkout, the payment return pages or the CHIP webhook:
    | on event day hundreds of participants on the stadium Wi-Fi share one public IP,
    | and the gateway calls back from a few fixed addresses, so a ban or a limit on
    | the public side would block a whole venue or stop payments being recorded.
    */
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])
            ->middleware('throttle:admin-login')
            ->name('login.attempt');
    });

    Route::post('logout', [LoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    // Everything below requires an authenticated account that still holds
    // admin access, plus the specific permission for that screen. session.timeout
    // runs after admin so the user is resolved before the inactivity check reads
    // the session. ip.allowlist signs out anyone but a super admin whose IP is not
    // on a non-empty allowlist; throttle:admin-requests is the per-user request
    // limit, off by default. Both are set on General Config, Security.
    Route::middleware(['auth', 'admin', 'session.timeout', 'ip.allowlist', 'throttle:admin-requests'])->group(function () {

        Route::get('/', DashboardController::class)
            ->middleware('permission:dashboard.view')
            ->name('dashboard');

        /*
        |----------------------------------------------------------------------
        | Event
        |----------------------------------------------------------------------
        |
        | Listing screens only for now. The create and edit forms wait on the
        | field list, and Attendance waits on its data model.
        |
        */
        Route::prefix('event')->name('event.')->group(function () {

            // Registration - tabs: Register Event, Ongoing, Completed, Cancel
            // `create` is declared before `{event}` so it is not swallowed as a
            // route parameter.
            Route::get('registration', [EventRegistrationController::class, 'index'])
                ->middleware('permission:events.view')
                ->name('registration');
            Route::get('registration/create', [EventRegistrationController::class, 'create'])
                ->middleware('permission:events.create')
                ->name('registration.create');
            Route::post('registration', [EventRegistrationController::class, 'store'])
                ->middleware('permission:events.create')
                ->name('registration.store');
            Route::get('registration/{event}', [EventRegistrationController::class, 'show'])
                ->middleware('permission:events.view')
                ->name('registration.show');
            Route::get('registration/{event}/edit', [EventRegistrationController::class, 'edit'])
                ->middleware('permission:events.update')
                ->name('registration.edit');
            Route::put('registration/{event}', [EventRegistrationController::class, 'update'])
                ->middleware('permission:events.update')
                ->name('registration.update');
            Route::delete('registration/{event}', [EventRegistrationController::class, 'destroy'])
                ->middleware('permission:events.delete')
                ->name('registration.destroy');

            /*
            | Venue Wi-Fi. Four actions on one event's logins.
            |
            | Issuing and rotating change the event, so they sit behind events.update.
            | Sending reaches competitors' inboxes, which is a different capability from
            | being allowed to edit an event, so it carries the same permission as every
            | other thing on this site that emails a participant.
            |
            | The slips are a read, but a read of every login on the event in plain text,
            | so they are kept behind the permission that already governs taking
            | participant detail out of the system rather than behind plain viewing.
            */
            Route::post('registration/{event}/wifi/issue', [EventWifiController::class, 'issue'])
                ->middleware('permission:events.update')
                ->name('registration.wifi.issue');

            Route::post('registration/{event}/wifi/rotate', [EventWifiController::class, 'rotate'])
                ->middleware('permission:events.update')
                ->name('registration.wifi.rotate');

            // Throttled: one press queues an email to every competitor on the event, and
            // a double click would be two hundred duplicates.
            Route::post('registration/{event}/wifi/send', [EventWifiController::class, 'send'])
                ->middleware(['permission:participants.notify', 'throttle:4,1'])
                ->name('registration.wifi.send');

            Route::get('registration/{event}/wifi/slips', [EventWifiController::class, 'slips'])
                ->middleware('permission:participants.export')
                ->name('registration.wifi.slips');

            // Participants - tabs: Individual, Team, Paid, Unpaid
            Route::get('participants', [ParticipantController::class, 'index'])
                ->middleware('permission:participants.view')
                ->name('participants');

            /*
             | Its own permission, and declared before the {registration} route
             | below so "export" is not mistaken for a registration id.
             |
             | Separate from viewing because a screen shows one entry to somebody
             | looking at it, while this puts every person's identity card number
             | and address into a file that leaves the building.
             */
            Route::get('participants/export', [ParticipantController::class, 'export'])
                ->middleware('permission:participants.export')
                ->name('participants.export');

            /*
             | Re-pricing one event's entries after its add-on charging rule changed.
             |
             | Two routes because it is two acts. The GET is a read-only preview that
             | writes nothing and may be opened as often as the operator likes; the POST
             | is the one that moves money, and it is refused unless the confirmation on
             | that screen was ticked.
             |
             | Behind participants.update: it corrects what an entry was charged, which
             | is the same kind of act as correcting anything else on the record, and it
             | needs no role to be re-seeded.
             |
             | Declared above the {registration} route for the same reason "export" is,
             | and scoped to one event rather than offered as a sweep: this runs against
             | a live table while people are registering.
             */
            Route::get('participants/recalculate/{event}', [ParticipantController::class, 'recalculateForm'])
                ->middleware('permission:participants.update')
                ->whereNumber('event')
                ->name('participants.recalculate');

            /*
             | Its own path rather than the same one by verb, so a GET to it is refused
             | outright with a 405 instead of quietly rendering the preview. The two are
             | different acts and a link, a scanner or a mail client following one must
             | never land on the other.
             */
            Route::post('participants/recalculate/{event}/apply', [ParticipantController::class, 'recalculate'])
                ->middleware(['permission:participants.update', 'throttle:10,1'])
                ->whereNumber('event')
                ->name('participants.recalculate.apply');

            /*
             | Receipt rows the gateway's own record contradicts, and removing them.
             |
             | Two routes because it is two acts, the same shape as the re-pricing pair
             | above. The GET is a read-only diagnostic that writes nothing; the POST
             | deletes payment rows and is refused unless the confirmation on that
             | screen was ticked.
             |
             | Behind payments.record, which already exists and is already granted to
             | the roles trusted with the registration ledger. It is the permission that
             | writes rows into event_registration_payments, and this is the only other
             | thing that touches them, so no role needs re-seeding. Viewer does not
             | hold it, which is the point.
             |
             | Declared above the {registration} route for the same reason "export" is,
             | and scoped to one event rather than offered as a sweep.
             */
            Route::get('participants/receipts/{event}', [ParticipantController::class, 'receiptsForm'])
                ->middleware('permission:payments.record')
                ->whereNumber('event')
                ->name('participants.receipts');

            /*
             | Its own path rather than the same one by verb, so a GET to it is refused
             | with a 405 instead of quietly deleting anything. Throttled because each
             | press removes rows from a live money table.
             */
            Route::post('participants/receipts/{event}/apply', [ParticipantController::class, 'receipts'])
                ->middleware(['permission:payments.record', 'throttle:10,1'])
                ->whereNumber('event')
                ->name('participants.receipts.apply');

            Route::get('participants/{registration}', [ParticipantController::class, 'show'])
                ->middleware('permission:participants.view')
                ->name('participants.show');

            /*
             | One side of a competitor's identity card.
             |
             | Behind participants.export rather than participants.view, and that is the
             | point of putting it here. Viewing a record is reading what somebody typed;
             | this is reading a photograph of their government identity document, which is
             | the same class of thing as taking the participant list out of the building.
             |
             | Declared after {registration} above but distinguished by its own segment, and
             | the side is matched against a whitelist in the controller rather than being
             | interpolated anywhere near a column name.
             */
            Route::get('participants/ic/{participant}/{side}', [IdentityCardController::class, 'show'])
                ->middleware(['permission:participants.export', 'throttle:60,1'])
                ->whereNumber('participant')
                ->where('side', 'front|back')
                ->name('participants.ic');

            // Its own permission: this one reaches somebody's inbox, which is a
            // different thing from being allowed to read the record.
            Route::post('participants/{registration}/resend', [ParticipantController::class, 'resend'])
                ->middleware('permission:participants.notify')
                ->name('participants.resend');

            // Chasing an unpaid entry is the same capability as resending, so it
            // sits behind the same permission.
            /*
             | Recording money that arrived outside the gateway. Its own permission,
             | because nothing here observed the payment: pressing it is a person
             | asserting they saw it, and that assertion confirms the entrant's place
             | and moves the figure into the takings.
             |
             | Throttled. It writes to a ledger, and a stuck finger on a confirm
             | dialog must not be able to record the same receipt twenty times.
             */
            Route::post('participants/{registration}/payment', [ParticipantController::class, 'recordPayment'])
                ->middleware(['permission:payments.record', 'throttle:20,1'])
                ->name('participants.payment');

            /*
             | Reconcile an entry against the gateway. Safer than recording a payment
             | by hand, because it only ever believes what the gateway reports, but it
             | still moves money into the takings so it carries its own permission.
             |
             | Throttled: each press makes one outbound call per purchase on record.
             */
            Route::post('participants/{registration}/tally', [ParticipantController::class, 'tally'])
                ->middleware(['permission:payments.tally', 'throttle:20,1'])
                ->name('participants.tally');

            /*
             | Chasing an unpaid entry: the payment link for whatever is still owed.
             | The same capability as resending, so the same permission.
             |
             | Throttled the way the shop's payment link is. The controller already
             | leaves one registrant alone for six hours after a link goes out, which
             | is the per-person guard; this is the per-operator one, so a stuck finger
             | on a confirm dialog cannot walk down the list emailing everybody twice.
             */
            Route::post('participants/{registration}/remind', [ParticipantController::class, 'remind'])
                ->middleware(['permission:participants.notify', 'throttle:20,1'])
                ->name('participants.remind');

            /*
             | Asking a registrant to confirm the shirt size of everybody on the entry,
             | for an event that began collecting sizes after those entries were made.
             |
             | Behind participants.notify, the permission that already governs reaching a
             | participant's inbox, so no role needs re-seeding: it is the same capability
             | as the reminder beside it, aimed at a question rather than a debt. Nothing
             | here reads or writes money.
             |
             | The bulk form is declared before the per-entry one so "sizes" is never read
             | as a registration id, and both carry the same permission: it is the same
             | act, and inventing a second permission for the plural would mean a role
             | that may email one person but not twenty.
             |
             | Throttled, the way the shop's pair is. The sender already leaves one
             | registrant alone for six hours after a link goes out, which is the
             | per-person guard; these are the per-operator ones, so a stuck finger on a
             | confirm dialog cannot walk the list twice. The bulk one is tighter because
             | each press can reach every entry on the filtered list.
             */
            Route::post('participants/sizes', [ParticipantController::class, 'sendAllSizeLinks'])
                ->middleware(['permission:participants.notify', 'throttle:4,1'])
                ->name('participants.sizes.all');

            Route::post('participants/{registration}/sizes', [ParticipantController::class, 'sendSizeLink'])
                ->middleware(['permission:participants.notify', 'throttle:20,1'])
                ->name('participants.sizes');

            /*
             | Its own permission: it moves an entry between two events, changing the
             | seat count on both and discarding the add-on lines and answers that
             | belonged to the old one. Throttled because each press writes to two
             | events under a lock.
             */
            Route::get('participants/{registration}/transfer', [ParticipantController::class, 'transferForm'])
                ->middleware('permission:participants.transfer')
                ->name('participants.transfer');

            Route::post('participants/{registration}/transfer', [ParticipantController::class, 'transfer'])
                ->middleware(['permission:participants.transfer', 'throttle:20,1'])
                ->name('participants.transfer.save');

            /*
             | One person on an entry, rather than the entry itself. Keyed by both so
             | the registration the screen and the permission were resolved against
             | stays the authority, and a person id belonging to another entry cannot
             | be posted against this URL.
             |
             | Two permissions, not one. Correcting a misspelt name fixes a record;
             | taking somebody off destroys one, along with their answers and
             | whatever was ordered in their size.
             */
            /*
             | The entry's own details: team name, logo and note. Behind the same
             | permission as correcting a person, because it is the same act on the
             | same record. The reference and the event are not here: the first is
             | quoted in every message already sent, and the second has its own
             | screen above because moving one moves seats and money.
             */
            Route::put('participants/{registration}/entry', [ParticipantController::class, 'updateEntry'])
                ->middleware(['permission:participants.update', 'throttle:30,1'])
                ->name('participants.entry.update');

            Route::put('participants/{registration}/people/{participant}', [ParticipantController::class, 'updateParticipant'])
                ->middleware(['permission:participants.update', 'throttle:30,1'])
                ->name('participants.person.update');

            Route::delete('participants/{registration}/people/{participant}', [ParticipantController::class, 'removeParticipant'])
                ->middleware(['permission:participants.remove', 'throttle:30,1'])
                ->name('participants.person.remove');

            // Its own permission: permanent, and it takes the personal data of
            // everyone named on the entry with it.
            Route::delete('participants/{registration}', [ParticipantController::class, 'destroy'])
                ->middleware('permission:participants.delete')
                ->name('participants.destroy');

            /*
            | The counter actions. Keyed by participant rather than registration
            | because a squad arrives one player at a time.
            */
            Route::post('attendance/{participant}/check-in', [AttendanceController::class, 'checkIn'])
                ->middleware('permission:attendance.update')
                ->name('attendance.check-in');
            Route::delete('attendance/{participant}/check-in', [AttendanceController::class, 'undoCheckIn'])
                ->middleware('permission:attendance.update')
                ->name('attendance.undo-check-in');
            Route::put('attendance/{participant}/swap', [AttendanceController::class, 'swapPlayer'])
                ->middleware('permission:attendance.update')
                ->name('attendance.swap');

            // Its own permission: a check-in can be undone, whereas this deletes
            // the participant row and cannot be.
            Route::delete('attendance/{participant}', [AttendanceController::class, 'removePlayer'])
                ->middleware('permission:attendance.remove-player')
                ->name('attendance.remove-player');

            // Event Settings - tabs: Email Template, SMS Template
            Route::get('settings', [EventSettingsController::class, 'index'])
                ->middleware('permission:event.settings.view')
                ->name('settings');
            Route::put('settings/{tab}', [EventSettingsController::class, 'update'])
                ->middleware('permission:event.settings.update')
                ->name('settings.update');
            Route::get('settings/{tab}/preview/{key}', [EventSettingsController::class, 'preview'])
                ->middleware('permission:event.settings.view')
                ->name('settings.preview');

            // Attendance - tabs: Attendance, Player Change, Present, Absent
            Route::get('attendance', [AttendanceController::class, 'index'])
                ->middleware('permission:attendance.view')
                ->name('attendance');

            /*
            | Collection. The other counter: handing the shirts out.
            |
            | A sibling of Attendance rather than a tab on it, because arriving and
            | collecting are two separate facts about the same afternoon — somebody can
            | walk in and leave without their shirt, or send a brother for it having
            | never turned up. Nothing here reads or writes event_attendances.
            |
            | Behind attendance.view and attendance.update: the same desk, the same
            | staff and the same shift, and both permissions already exist and are
            | already granted, so no role needs re-seeding.
            |
            | "export" is declared before anything taking a {registration}, the same
            | way the Participants export is, and carries participants.export because
            | it puts names and identity card numbers in a file that leaves the
            | building.
            */
            Route::get('collection', [CollectionController::class, 'index'])
                ->middleware('permission:attendance.view')
                ->name('collection');

            Route::get('collection/export', [CollectionController::class, 'export'])
                ->middleware('permission:participants.export')
                ->name('collection.export');

            /*
            | The code, and the handover it unlocks. POST only on both: a GET to either
            | is refused with a 405 rather than quietly texting somebody or recording
            | goods as gone, so a link, a scanner or a mail client following one can do
            | nothing.
            |
            | Keyed by the registration because one code covers a batch: a
            | representative taking all six of a grouping's shirts reads out one code,
            | not six. It also means a code issued for one entry can complete nothing
            | on another.
            |
            | Throttled. The code route spends money and reaches a handset; the
            | handover route is pressed hard at a live counter, so its limit is the
            | looser of the two.
            */
            Route::post('collection/{registration}/code', [CollectionController::class, 'sendCode'])
                ->middleware(['permission:attendance.update', 'throttle:20,1'])
                ->name('collection.code');

            Route::post('collection/{registration}/hand-over', [CollectionController::class, 'handOver'])
                ->middleware(['permission:attendance.update', 'throttle:60,1'])
                ->name('collection.hand-over');

            Route::get('reporting', [AnalyticReportingController::class, 'index'])
                ->middleware('permission:reports.view')
                ->name('reporting');
        });

        /*
        | Payments. The same registrations the Event module holds, read from the
        | money end instead of the people end.
        |
        | Everything is behind payments.view except the export, which carries names
        | and identity card numbers out of the system in one file, and the reminder,
        | which reuses the messaging permission rather than inventing a second one
        | for the same act.
        */
        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('/', [PaymentController::class, 'overview'])
                ->middleware('permission:payments.view')
                ->name('overview');

            Route::get('transactions', [PaymentController::class, 'transactions'])
                ->middleware('permission:payments.transactions.view')
                ->name('transactions');

            Route::get('refunds', [PaymentController::class, 'refunds'])
                ->middleware('permission:payments.refunds.view')
                ->name('refunds');

            /*
            | Sending money back carries its own permission, separate from every
            | view above. This is the one button in the module that moves real money
            | out of the account, and it cannot be undone from here: reversing a
            | refund means talking to CHIP.
            */
            Route::post('refund/{registration}', [PaymentController::class, 'refund'])
                ->middleware('permission:payments.refund')
                ->name('refund');

            Route::get('unpaid', [PaymentController::class, 'failed'])
                ->middleware('permission:payments.unpaid.view')
                ->name('unpaid');

            Route::get('settlements', [PaymentController::class, 'settlements'])
                ->middleware('permission:payments.settlements.view')
                ->name('settlements');

            Route::get('reports', [PaymentController::class, 'reports'])
                ->middleware('permission:payments.reports.view')
                ->name('reports');

            Route::get('export', [PaymentController::class, 'export'])
                ->middleware('permission:payments.export')
                ->name('export');


            Route::post('{registration}/remind', [PaymentController::class, 'remind'])
                ->middleware(['permission:participants.notify', 'throttle:20,1'])
                ->name('remind');
        });

        /*
        | Campaign. Reaches the same people the Event module registered, but about
        | something they did not ask for, so consent and suppression run through
        | every screen.
        |
        | Sending has its own permission. Creating a draft is reversible; putting
        | mail in a stranger's inbox is not, and an SMS blast spends money.
        */
        Route::prefix('campaigns')->name('campaigns.')->group(function () {
            Route::get('/', [CampaignController::class, 'index'])
                ->middleware('permission:campaigns.view')->name('index');

            Route::get('create', [CampaignController::class, 'create'])
                ->middleware('permission:campaigns.create')->name('create');
            Route::post('/', [CampaignController::class, 'store'])
                ->middleware('permission:campaigns.create')->name('store');

            /*
            | The people a segment covers, read by the picker on the form when the
            | audience changes. Above {campaign} so "recipients" is never mistaken
            | for a campaign id.
            */
            Route::get('recipients', [CampaignController::class, 'recipients'])
                ->middleware('permission:campaigns.view')->name('recipients');

            // Audiences and suppression sit above {campaign} so the words are never
            // taken for a campaign id.
            Route::get('audiences', [AudienceController::class, 'index'])
                ->middleware('permission:campaigns.audiences.view')->name('audiences');
            Route::post('audiences/rebuild', [AudienceController::class, 'rebuild'])
                ->middleware('permission:campaigns.audiences.rebuild')->name('audiences.rebuild');
            Route::get('audiences/export', [AudienceController::class, 'export'])
                ->middleware('permission:campaigns.audiences.export')->name('audiences.export');

            Route::get('suppression', [AudienceController::class, 'suppression'])
                ->middleware('permission:campaigns.suppression.view')->name('suppression');
            Route::post('suppression', [AudienceController::class, 'suppress'])
                ->middleware('permission:campaigns.suppression.add')->name('suppression.add');
            Route::post('suppression/{contact}/resubscribe', [AudienceController::class, 'resubscribe'])
                ->middleware('permission:campaigns.suppression.resubscribe')->name('suppression.resubscribe');

            Route::get('templates', [CampaignTemplateController::class, 'index'])
                ->middleware('permission:campaigns.templates.view')->name('templates');
            Route::get('templates/create', [CampaignTemplateController::class, 'create'])
                ->middleware('permission:campaigns.templates.create')->name('templates.create');
            Route::post('templates', [CampaignTemplateController::class, 'store'])
                ->middleware('permission:campaigns.templates.create')->name('templates.store');
            Route::get('templates/{template}/edit', [CampaignTemplateController::class, 'edit'])
                ->middleware('permission:campaigns.templates.update')->name('templates.edit');
            Route::put('templates/{template}', [CampaignTemplateController::class, 'update'])
                ->middleware('permission:campaigns.templates.update')->name('templates.update');
            Route::delete('templates/{template}', [CampaignTemplateController::class, 'destroy'])
                ->middleware('permission:campaigns.templates.delete')->name('templates.destroy');

            Route::get('reports', [CampaignReportController::class, 'index'])
                ->middleware('permission:campaigns.reports.view')->name('reports');
            Route::get('reports/{campaign}', [CampaignReportController::class, 'show'])
                ->middleware('permission:campaigns.reports.view')->name('reports.show');
            Route::get('reports/{campaign}/export', [CampaignReportController::class, 'export'])
                ->middleware('permission:campaigns.reports.export')->name('reports.export');

            Route::get('{campaign}', [CampaignController::class, 'show'])
                ->middleware('permission:campaigns.view')->name('show');
            Route::get('{campaign}/edit', [CampaignController::class, 'edit'])
                ->middleware('permission:campaigns.update')->name('edit');
            Route::put('{campaign}', [CampaignController::class, 'update'])
                ->middleware('permission:campaigns.update')->name('update');
            Route::delete('{campaign}', [CampaignController::class, 'destroy'])
                ->middleware('permission:campaigns.delete')->name('destroy');

            Route::post('{campaign}/test', [CampaignController::class, 'test'])
                ->middleware(['permission:campaigns.send', 'throttle:10,1'])->name('test');
            Route::post('{campaign}/send', [CampaignController::class, 'send'])
                ->middleware(['permission:campaigns.send', 'throttle:6,1'])->name('send');
        });

        /*
        | Tournament. What happens after the entries are in and the players have
        | been checked in: the draw, the fixtures, the scores, and the podium that
        | ends up on the public site.
        |
        | Read only for now. The write routes arrive with the data model, which is
        | still waiting on how the short squad penalty and disqualification should
        | behave.
        |
        | tournament.scope is declared on the whole group rather than on the routes
        | that take a {tournament}. A handler is confined to the tournaments assigned
        | to it, and the group is where that cannot be forgotten on a route added
        | later. It reads whichever tournament the request names, by path, by match
        | or by ?tournament=, and refuses an unassigned one with a 403. For anybody
        | who is not a handler it does nothing.
        */
        Route::prefix('tournaments')->name('tournaments.')->middleware('tournament.scope')->group(function () {
            Route::get('/', [TournamentController::class, 'index'])
                ->middleware('permission:tournaments.view')->name('index');

            Route::get('matches', [MatchController::class, 'index'])
                ->middleware('permission:tournaments.matches.view')->name('matches');

            /*
            | Score entry keys on the match, not the tournament, because that is what
            | the referee has in front of them. Its own permission, so a referee can be
            | given this and nothing else.
            */
            Route::get('matches/{match}/score', [MatchController::class, 'edit'])
                ->middleware('permission:tournaments.matches.score')->name('matches.score');
            Route::put('matches/{match}/score', [MatchController::class, 'update'])
                ->middleware('permission:tournaments.matches.score')->name('matches.score.save');
            Route::post('matches/{match}/resolve', [MatchController::class, 'resolve'])
                ->middleware('permission:tournaments.matches.score')->name('matches.resolve');

            /*
            | Blanking a result, for the figure that should never have been entered.
            | Behind the same permission as entering one, the way publishing a podium
            | and withdrawing it share theirs: whoever decides a result is the person
            | who decides it was not one.
            */
            Route::delete('matches/{match}/score', [MatchController::class, 'clear'])
                ->middleware(['permission:tournaments.matches.score', 'throttle:20,1'])
                ->name('matches.score.clear');

            /*
            | The map a fixture is on and when it starts. Behind the draw permission
            | rather than the scoring one: this decides what the fixture is, not what
            | its result was, which is the same question generating a draw answers.
            */
            Route::put('matches/{match}/fixture', [MatchController::class, 'updateFixture'])
                ->middleware(['permission:tournaments.matches.generate', 'throttle:60,1'])
                ->name('matches.fixture.update');

            Route::get('standings', [StandingController::class, 'index'])
                ->middleware('permission:tournaments.standings.view')->name('standings');

            // Its own permission: an export carries every competitor's name out of the
            // system in one file.
            Route::get('standings/{tournament}/export', [StandingController::class, 'export'])
                ->middleware('permission:tournaments.standings.export')->name('standings.export');

            /*
            | Point Rules. Declared above {tournament} would matter if that route
            | existed here yet; kept grouped so the whole scoring library reads
            | together.
            */
            Route::get('rules', [PointRuleController::class, 'index'])
                ->middleware('permission:tournaments.rules.view')->name('rules');
            Route::get('rules/create', [PointRuleController::class, 'create'])
                ->middleware('permission:tournaments.rules.create')->name('rules.create');
            Route::post('rules', [PointRuleController::class, 'store'])
                ->middleware('permission:tournaments.rules.create')->name('rules.store');
            Route::get('rules/{rule}/edit', [PointRuleController::class, 'edit'])
                ->middleware('permission:tournaments.rules.update')->name('rules.edit');
            Route::put('rules/{rule}', [PointRuleController::class, 'update'])
                ->middleware('permission:tournaments.rules.update')->name('rules.update');
            Route::delete('rules/{rule}', [PointRuleController::class, 'destroy'])
                ->middleware('permission:tournaments.rules.delete')->name('rules.destroy');

            Route::get('hall-of-fame', [HallOfFameController::class, 'index'])
                ->middleware('permission:tournaments.halloffame.view')->name('hall-of-fame');

            // The only two actions in this module that change the public website, so
            // they carry the only permission that does.
            Route::post('hall-of-fame/{tournament}/publish', [HallOfFameController::class, 'publish'])
                ->middleware('permission:tournaments.halloffame.publish')->name('hall-of-fame.publish');
            Route::post('hall-of-fame/{tournament}/withdraw', [HallOfFameController::class, 'withdraw'])
                ->middleware('permission:tournaments.halloffame.publish')->name('hall-of-fame.withdraw');

            // The player ledger publishes on its own, so it gets its own two actions
            // behind the same permission rather than riding along with the podium.
            Route::post('hall-of-fame/{tournament}/awards/publish', [HallOfFameController::class, 'publishAwards'])
                ->middleware('permission:tournaments.halloffame.publish')->name('hall-of-fame.awards.publish');
            Route::post('hall-of-fame/{tournament}/awards/withdraw', [HallOfFameController::class, 'withdrawAwards'])
                ->middleware('permission:tournaments.halloffame.publish')->name('hall-of-fame.awards.withdraw');

            Route::get('settings', [TournamentSettingsController::class, 'index'])
                ->middleware('permission:tournaments.settings.view')->name('settings');
            Route::put('settings/{tab}', [TournamentSettingsController::class, 'update'])
                ->middleware('permission:tournaments.settings.update')->name('settings.update');

            /*
            | The tournament itself. Declared last so "matches", "standings",
            | "rules", "hall-of-fame" and "settings" are never taken for an id.
            */
            Route::get('create', [TournamentController::class, 'create'])
                ->middleware('permission:tournaments.create')->name('create');
            Route::post('/', [TournamentController::class, 'store'])
                ->middleware('permission:tournaments.create')->name('store');

            Route::get('{tournament}', [TournamentController::class, 'show'])
                ->middleware('permission:tournaments.view')->name('show');
            Route::get('{tournament}/edit', [TournamentController::class, 'edit'])
                ->middleware('permission:tournaments.update')->name('edit');
            Route::put('{tournament}', [TournamentController::class, 'update'])
                ->middleware('permission:tournaments.update')->name('update');
            Route::delete('{tournament}', [TournamentController::class, 'destroy'])
                ->middleware('permission:tournaments.delete')->name('destroy');

            // Entrants and seeding are edits to the tournament, so they sit behind
            // tournaments.update rather than inventing permissions of their own.
            Route::post('{tournament}/entrants/import', [TournamentController::class, 'importEntrants'])
                ->middleware('permission:tournaments.update')->name('entrants.import');
            Route::post('{tournament}/entrants', [TournamentController::class, 'addEntrant'])
                ->middleware('permission:tournaments.update')->name('entrants.add');
            Route::delete('{tournament}/entrants/{entrant}', [TournamentController::class, 'removeEntrant'])
                ->middleware('permission:tournaments.update')->name('entrants.remove');
            Route::post('{tournament}/seed', [TournamentController::class, 'seed'])
                ->middleware('permission:tournaments.update')->name('seed');

            // Stages are part of setting a tournament up, but generating a draw is
            // its own permission: one press writes every fixture in the bracket.
            Route::post('{tournament}/stages', [StageController::class, 'store'])
                ->middleware('permission:tournaments.update')->name('stages.store');
            Route::delete('{tournament}/stages/{stage}', [StageController::class, 'destroy'])
                ->middleware('permission:tournaments.update')->name('stages.destroy');
            Route::post('{tournament}/stages/{stage}/generate', [StageController::class, 'generate'])
                ->middleware('permission:tournaments.matches.generate')->name('stages.generate');
            Route::delete('{tournament}/stages/{stage}/draw', [StageController::class, 'discard'])
                ->middleware('permission:tournaments.matches.generate')->name('stages.discard');
        });

        /*
        |----------------------------------------------------------------------
        | Shop
        |----------------------------------------------------------------------
        |
        | The merchandise catalogue: medals, apparel and event goods. Sits after
        | Payments because it is operational rather than content, and before
        | Portfolio for the same reason.
        |
        | Categories have no create or edit page: they are six field records edited
        | in a dialog on the list, the way User Management does it, so there are no
        | routes for forms that do not exist.
        |
        */
        Route::prefix('shop')->name('shop.')->group(function () {

            // Products. `create` is declared before `{product}` so it is not
            // swallowed as a route parameter.
            Route::get('products', [ShopProductController::class, 'index'])
                ->middleware('permission:shop.products.view')
                ->name('products');
            Route::get('products/create', [ShopProductController::class, 'create'])
                ->middleware('permission:shop.products.create')
                ->name('products.create');
            Route::post('products', [ShopProductController::class, 'store'])
                ->middleware('permission:shop.products.create')
                ->name('products.store');
            Route::get('products/{product}/edit', [ShopProductController::class, 'edit'])
                ->middleware('permission:shop.products.update')
                ->name('products.edit');
            Route::put('products/{product}', [ShopProductController::class, 'update'])
                ->middleware('permission:shop.products.update')
                ->name('products.update');
            Route::delete('products/{product}', [ShopProductController::class, 'destroy'])
                ->middleware('permission:shop.products.delete')
                ->name('products.destroy');

            /*
            | Categories moved into Shop Settings as a tab, because they are part of
            | how the shop is arranged rather than a screen of their own.
            |
            | The old address is kept as a redirect rather than removed: it was in the
            | sidebar, so it is in bookmarks and in the browser history of everyone who
            | used it.
            */
            Route::get('categories', fn () => redirect()->route('admin.shop.settings', [
                'tab' => ShopSettingsController::TAB_CATEGORIES,
            ]))
                ->middleware('permission:shop.categories.view')
                ->name('categories');

            Route::post('categories', [ShopCategoryController::class, 'store'])
                ->middleware('permission:shop.categories.create')
                ->name('categories.store');
            Route::put('categories/{category}', [ShopCategoryController::class, 'update'])
                ->middleware('permission:shop.categories.update')
                ->name('categories.update');
            Route::delete('categories/{category}', [ShopCategoryController::class, 'destroy'])
                ->middleware('permission:shop.categories.delete')
                ->name('categories.destroy');

            /*
            | Orders. Read, then the two things staff actually do to one: move it
            | along, and say the money arrived.
            |
            | Confirming payment carries its own permission because cash on delivery
            | and a bank transfer are settled outside this system, so pressing it is
            | a statement that real money was received rather than a status change.
            */
            Route::get('orders', [ShopOrderController::class, 'index'])
                ->middleware('permission:shop.orders.view')
                ->name('orders');
            Route::get('orders/export', [ShopOrderController::class, 'exportCsv'])
                ->middleware('permission:shop.orders.view')
                ->name('orders.export');
            Route::get('orders/{order}', [ShopOrderController::class, 'show'])
                ->middleware('permission:shop.orders.view')
                ->name('orders.show');
            Route::put('orders/{order}/status', [ShopOrderController::class, 'updateStatus'])
                ->middleware('permission:shop.orders.update')
                ->name('orders.status');
            Route::put('orders/{order}/payment', [ShopOrderController::class, 'confirmPayment'])
                ->middleware('permission:shop.orders.payment')
                ->name('orders.payment');

            /*
            | Handing a counter-collected order over.
            |
            | Its own route rather than a status posted to orders.status, because the
            | request must not be able to name the destination: paying for a counter
            | order and collecting it are weeks apart, and the whole fix is that only
            | this press moves one to delivered.
            |
            | Same permission as orders.status, so nothing needs re-seeding to grant
            | it: somebody trusted to move an order along is trusted to record a
            | handover. POST, so it cannot be fired by a link, a prefetch or a crawler.
            */
            Route::post('orders/{order}/collect', [ShopOrderController::class, 'confirmCollection'])
                ->middleware('permission:shop.orders.update')
                ->name('orders.collect');

            /*
            | Texting a one-time code to somebody collecting on the buyer's behalf.
            |
            | Same permission as the handover it guards, deliberately: issuing the
            | code and recording the handover are two halves of one act, and a second
            | permission would mean re-seeding roles to grant something already
            | granted.
            |
            | POST and CSRF protected like everything else here. No route throttle:
            | the limits that matter are the per-order cooldown and the per-number
            | burst limit, and those live in CollectionVerifier where they are counted
            | in the database against the actual order and handset rather than against
            | whoever happens to share an IP with the counter.
            */
            Route::post('orders/{order}/collection-code', [ShopOrderController::class, 'sendCollectionCode'])
                ->middleware('permission:shop.orders.update')
                ->name('orders.collection-code');

            /*
            | Email the buyer a payment link.
            |
            | Separate from orders.payment: that one asserts money arrived, this one
            | asks for it.
            |
            | The bulk form is declared before the per-order one so "payment-links" is
            | never read as an order id, and both carry the same permission: it is the
            | same act, and inventing a second permission for the plural would mean
            | re-seeding roles to grant something already granted.
            |
            | POST on both, so neither can be fired by a link, a prefetch or a crawler.
            | The bulk one is throttled far harder because one press is already
            | twenty-odd emails, and the per-order cooldown on the order row is what
            | stops a second press reaching the same buyer.
            */
            Route::post('orders/payment-links', [ShopOrderController::class, 'sendAllPaymentLinks'])
                ->middleware(['permission:shop.orders.notify', 'throttle:4,1'])
                ->name('orders.payment-links');

            Route::post('orders/{order}/payment-link', [ShopOrderController::class, 'sendPaymentLink'])
                ->middleware(['permission:shop.orders.notify', 'throttle:20,1'])
                ->name('orders.payment-link');

            /*
            | Sending money back. Its own permission again, and separate from the event
            | refund: somebody trusted with the shop is not automatically trusted with
            | registration fees.
            */
            Route::post('orders/{order}/refund', [ShopOrderController::class, 'refund'])
                ->middleware('permission:shop.orders.refund')
                ->name('orders.refund');

            /*
            | Tracking. The same orders read from the delivery end rather than the money
            | end, which is why it shares the orders permissions rather than inventing
            | its own: there is nothing here somebody with the order list cannot see.
            */
            Route::get('tracking', [ShopTrackingController::class, 'index'])
                ->middleware('permission:shop.orders.view')
                ->name('tracking');
            Route::put('tracking/{order}', [ShopTrackingController::class, 'update'])
                ->middleware('permission:shop.orders.update')
                ->name('tracking.update');

            // Shop Settings - tabs: Storefront, Inventory, Categories
            Route::get('settings', [ShopSettingsController::class, 'index'])
                ->middleware('permission:shop.settings.view')
                ->name('settings');
            Route::put('settings/{tab}', [ShopSettingsController::class, 'update'])
                ->middleware('permission:shop.settings.update')
                ->name('settings.update');
        });

        /*
        |----------------------------------------------------------------------
        | Portfolio
        |----------------------------------------------------------------------
        |
        | Work delivered, shown on the public Portfolio page. Website content
        | rather than operations, which is why it sits after the modules that run
        | an event and before Settings.
        |
        | No show route: the public page is the detail view, so a second read only
        | screen in the admin would be two places to keep in step for no gain.
        |
        */
        Route::prefix('portfolio')->name('portfolio.')->group(function () {

            // `create` is declared before `{project}` so it is not swallowed as a
            // route parameter.
            Route::get('/', [PortfolioProjectController::class, 'index'])
                ->middleware('permission:portfolio.view')
                ->name('index');
            Route::get('create', [PortfolioProjectController::class, 'create'])
                ->middleware('permission:portfolio.create')
                ->name('create');
            Route::post('/', [PortfolioProjectController::class, 'store'])
                ->middleware('permission:portfolio.create')
                ->name('store');
            /*
            | Gallery. Declared before {project} so "gallery" is never read as a
            | project id.
            |
            | An image is always tagged to a project on upload, so there is no route
            | for a loose image library: one could not be reached from the site.
            */
            Route::get('gallery', [PortfolioGalleryController::class, 'index'])
                ->middleware('permission:portfolio.gallery.view')
                ->name('gallery');
            Route::post('gallery', [PortfolioGalleryController::class, 'store'])
                ->middleware('permission:portfolio.gallery.create')
                ->name('gallery.store');
            Route::put('gallery/{image}', [PortfolioGalleryController::class, 'update'])
                ->middleware('permission:portfolio.gallery.update')
                ->name('gallery.update');
            Route::delete('gallery/{image}', [PortfolioGalleryController::class, 'destroy'])
                ->middleware('permission:portfolio.gallery.delete')
                ->name('gallery.destroy');

            Route::get('{project}/edit', [PortfolioProjectController::class, 'edit'])
                ->middleware('permission:portfolio.update')
                ->name('edit');
            Route::put('{project}', [PortfolioProjectController::class, 'update'])
                ->middleware('permission:portfolio.update')
                ->name('update');
            Route::delete('{project}', [PortfolioProjectController::class, 'destroy'])
                ->middleware('permission:portfolio.delete')
                ->name('destroy');
        });

        Route::prefix('settings')->name('settings.')->group(function () {

            // General Config - tabs: General Config, Backup & Restore, Maintenance
            Route::get('general', [GeneralConfigController::class, 'index'])
                ->middleware('permission:settings.general.view')
                ->name('general');
            Route::put('general', [GeneralConfigController::class, 'updateGeneral'])
                ->middleware('permission:settings.general.update')
                ->name('general.update');
            Route::put('maintenance', [GeneralConfigController::class, 'updateMaintenance'])
                ->middleware('permission:settings.maintenance.update')
                ->name('maintenance.update');
            Route::put('security', [GeneralConfigController::class, 'updateSecurity'])
                ->middleware('permission:settings.security.update')
                ->name('security.update');

            // Banned IPs on the Security tab. DELETE only, so a GET (a prefetch,
            // a pasted link) can never lift a ban.
            Route::delete('security/banned-ips', [GeneralConfigController::class, 'clearBans'])
                ->middleware('permission:settings.security.update')
                ->name('security.bans.clear');
            Route::delete('security/banned-ips/{bannedIp}', [GeneralConfigController::class, 'destroyBan'])
                ->whereNumber('bannedIp')
                ->middleware('permission:settings.security.update')
                ->name('security.bans.destroy');

            /*
            | Backups on the Backup & Restore tab. Three permissions rather than one,
            | because they are three different risks.
            |
            | Taking one writes a file. Deleting one throws away a safety net. But
            | downloading one hands over every participant's identity card number and
            | every password hash in the system in a single file, which is the most
            | sensitive act available anywhere in this admin, so it has a permission of
            | its own and in practice only a super admin holds it.
            |
            | Running is a POST and throttled: each press queues a job that reads the
            | whole database and zips the uploads folder, and a stuck finger on the
            | button must not queue twenty of them. Deleting is a DELETE, so a prefetch
            | or a pasted link can never remove an archive.
            |
            | The file name is a route parameter, so it is constrained here as well as
            | resolved against the backups folder in BackupStore: the pattern refuses a
            | slash outright, which means "../../.env" never reaches the controller.
            | Nothing is restored by any of these.
            */
            Route::post('backup', [GeneralConfigController::class, 'runBackup'])
                ->middleware(['permission:settings.backup.create', 'throttle:6,1'])
                ->name('backup.run');

            Route::get('backup/{file}', [GeneralConfigController::class, 'downloadBackup'])
                ->middleware(['permission:settings.backup.download', 'throttle:20,1'])
                ->where('file', '[A-Za-z0-9._-]+')
                ->name('backup.download');

            Route::delete('backup/{file}', [GeneralConfigController::class, 'destroyBackup'])
                ->middleware('permission:settings.backup.delete')
                ->where('file', '[A-Za-z0-9._-]+')
                ->name('backup.destroy');

            // Integration - tabs: Email, API & Webhook, Payments, SMS, Telegram
            Route::get('integration', [IntegrationController::class, 'index'])
                ->middleware('permission:settings.integration.view')
                ->name('integration');
            // Throttled because it makes an outbound SMTP connection each time,
            // and a slow or unreachable server would otherwise be hammered.
            Route::post('integration/email/test', [IntegrationController::class, 'sendTestEmail'])
                ->middleware(['permission:settings.integration.update', 'throttle:6,1'])
                ->name('integration.email.test');

            // Throttled for the same reason as the email test: each press is an
            // outbound call, and the SMS one costs money every time.
            Route::post('integration/sms/test', [IntegrationController::class, 'sendTestSms'])
                ->middleware(['permission:settings.integration.update', 'throttle:6,1'])
                ->name('integration.sms.test');

            Route::post('integration/telegram/test', [IntegrationController::class, 'sendTestTelegram'])
                ->middleware(['permission:settings.integration.update', 'throttle:6,1'])
                ->name('integration.telegram.test');

            // Throttled like the rest, though this one only reads: it clears the
            // cached live status, so an unthrottled button would let somebody take the
            // cache out from under a busy ranking page as fast as they can click.
            Route::post('integration/facebook/test', [IntegrationController::class, 'checkFacebookLive'])
                ->middleware(['permission:settings.integration.update', 'throttle:6,1'])
                ->name('integration.facebook.test');

            // Writes: it replaces the stored token and can fill in the Page ID, so it is
            // a POST of its own rather than part of the check above.
            Route::post('integration/facebook/connect', [IntegrationController::class, 'connectFacebookPage'])
                ->middleware(['permission:settings.integration.update', 'throttle:6,1'])
                ->name('integration.facebook.connect');

            /*
             | EasyParcel authorisation. Three legs of one redirect, so they are
             | routes rather than part of the settings form.
             |
             | The callback path is registered in the EasyParcel developer hub as an
             | Allowed Redirect URI and is compared character for character on the
             | way out and again during the token exchange. Renaming it here breaks
             | the connection until the dashboard is edited to match.
             |
             | GET on the callback because that is how the administrator's browser
             | arrives back; the state check in the controller is what stands in for
             | CSRF protection on it.
             */
            Route::get('integration/easyparcel/connect', [EasyParcelController::class, 'connect'])
                ->middleware(['permission:settings.integration.update', 'throttle:10,1'])
                ->name('integration.easyparcel.connect');

            Route::get('integration/easyparcel/callback', [EasyParcelController::class, 'callback'])
                ->middleware(['permission:settings.integration.update', 'throttle:10,1'])
                ->name('integration.easyparcel.callback');

            Route::delete('integration/easyparcel', [EasyParcelController::class, 'disconnect'])
                ->middleware('permission:settings.integration.update')
                ->name('integration.easyparcel.disconnect');

            /*
             | Last of the integration routes on purpose. {tab} would otherwise
             | swallow the easyparcel segments above.
             */
            Route::put('integration/{tab}', [IntegrationController::class, 'update'])
                ->middleware('permission:settings.integration.update')
                ->name('integration.update');

            // Roles Management - list, then a full page for the permission matrix
            Route::get('roles', [RoleController::class, 'index'])
                ->middleware('permission:roles.view')
                ->name('roles');
            Route::get('roles/create', [RoleController::class, 'create'])
                ->middleware('permission:roles.create')
                ->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])
                ->middleware('permission:roles.create')
                ->name('roles.store');
            Route::get('roles/{role}', [RoleController::class, 'show'])
                ->middleware('permission:roles.view')
                ->name('roles.show');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])
                ->middleware('permission:roles.update')
                ->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])
                ->middleware('permission:roles.update')
                ->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])
                ->middleware('permission:roles.delete')
                ->name('roles.destroy');

            // User Management
            Route::get('users', [UserController::class, 'index'])
                ->middleware('permission:users.view')
                ->name('users');
            Route::post('users', [UserController::class, 'store'])
                ->middleware('permission:users.create')
                ->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])
                ->middleware('permission:users.update')
                ->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])
                ->middleware('permission:users.delete')
                ->name('users.destroy');

            // Logging - tabs: Activity Log, Audit Log
            Route::get('logging', [LoggingController::class, 'index'])
                ->middleware('permission:logs.activity.view')
                ->name('logging');
        });
    });
});
