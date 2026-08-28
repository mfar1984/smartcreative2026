<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Event\AnalyticReportingController;
use App\Http\Controllers\Admin\Event\AttendanceController;
use App\Http\Controllers\Admin\Campaign\AudienceController;
use App\Http\Controllers\Admin\Campaign\CampaignController;
use App\Http\Controllers\Admin\Campaign\CampaignReportController;
use App\Http\Controllers\Admin\Campaign\CampaignTemplateController;
use App\Http\Controllers\Admin\Event\ParticipantController;
use App\Http\Controllers\Admin\Payment\PaymentController;
use App\Http\Controllers\Admin\Portfolio\GalleryController as PortfolioGalleryController;
use App\Http\Controllers\Admin\Portfolio\ProjectController as PortfolioProjectController;
use App\Http\Controllers\Admin\Shop\CategoryController as ShopCategoryController;
use App\Http\Controllers\Admin\Shop\ProductController as ShopProductController;
use App\Http\Controllers\Admin\Shop\SettingsController as ShopSettingsController;
use App\Http\Controllers\Admin\Event\RegistrationController as EventRegistrationController;
use App\Http\Controllers\Admin\Event\SettingsController as EventSettingsController;
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

    // Sign in. Throttled at the route as a second line of defence behind the
    // per username limiter in LoginRequest.
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('login.attempt');
    });

    Route::post('logout', [LoginController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    // Everything below requires an authenticated account that still holds
    // admin access, plus the specific permission for that screen.
    Route::middleware(['auth', 'admin'])->group(function () {

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

            // Participants - tabs: Individual, Team, Paid, Unpaid
            Route::get('participants', [ParticipantController::class, 'index'])
                ->middleware('permission:participants.view')
                ->name('participants');
            Route::get('participants/{registration}', [ParticipantController::class, 'show'])
                ->middleware('permission:participants.view')
                ->name('participants.show');

            // Its own permission: this one reaches somebody's inbox, which is a
            // different thing from being allowed to read the record.
            Route::post('participants/{registration}/resend', [ParticipantController::class, 'resend'])
                ->middleware('permission:participants.notify')
                ->name('participants.resend');

            // Chasing an unpaid entry is the same capability as resending, so it
            // sits behind the same permission.
            Route::post('participants/{registration}/remind', [ParticipantController::class, 'remind'])
                ->middleware('permission:participants.notify')
                ->name('participants.remind');

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
        */
        Route::prefix('tournaments')->name('tournaments.')->group(function () {
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

            // Categories
            Route::get('categories', [ShopCategoryController::class, 'index'])
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

            // Shop Settings - tabs: Storefront, Inventory
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
