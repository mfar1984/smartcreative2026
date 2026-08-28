<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permissions declared as section => module => action => [slug, label].
     *
     * This mirrors how the Roles Management matrix is drawn: sections become
     * banded header rows, modules become the rows, and actions become the
     * columns. Slugs are kept stable so existing grants survive a re-seed.
     *
     * @var array<string, array<string, array<string, array{0: string, 1: string}>>>
     */
    private const PERMISSIONS = [
        'Dashboard' => [
            'Admin Area' => [
                'access' => ['admin.access', 'Access the admin area'],
            ],
            'Dashboard' => [
                'view' => ['dashboard.view', 'View dashboard'],
            ],
        ],

        'Event' => [
            'Registration' => [
                'view' => ['events.view', 'View registration events'],
                'create' => ['events.create', 'Create registration events'],
                'update' => ['events.update', 'Update registration events'],
                'delete' => ['events.delete', 'Delete registration events'],
            ],
            'Participants' => [
                'view' => ['participants.view', 'View participants and their payments'],
                // Separate from viewing, because this one puts mail in the
                // inbox of somebody outside the organisation.
                'notify' => ['participants.notify', 'Resend registration and payment messages'],
                // Separate again: there is no undo, and it destroys the record
                // of who entered and what they were charged.
                'delete' => ['participants.delete', 'Delete an unpaid registration'],
            ],
            'Attendance' => [
                'view' => ['attendance.view', 'View attendance'],
                'update' => ['attendance.update', 'Record attendance and swap a player'],
                // Filed under delete rather than as its own action, because that is
                // what it does: checking somebody in can be undone, whereas taking a
                // person off an entry deletes their row and cannot be.
                'delete' => ['attendance.remove-player', 'Remove a player from an entry at the counter'],
            ],
            'Event Settings' => [
                'view' => ['event.settings.view', 'View event message templates'],
                'update' => ['event.settings.update', 'Edit event message templates'],
            ],
            'Analytic Reporting' => [
                'view' => ['reports.view', 'View analytic reporting'],
            ],
        ],

        /*
        | One module per screen in the sidebar, so a role can be given one
        | Campaign screen without the other four. A single campaigns.view covering
        | all five made the menu all or nothing: unticking anything changed
        | nothing on screen, which is the same as the permission not existing.
        */
        'Campaign' => [
            'Campaigns' => [
                'view' => ['campaigns.view', 'View campaigns'],
                'create' => ['campaigns.create', 'Create a campaign'],
                'update' => ['campaigns.update', 'Edit a draft campaign'],
                'delete' => ['campaigns.delete', 'Delete a draft campaign'],
                // Separate from creating one. This is the button that puts mail in
                // strangers' inboxes and spends money on SMS, and it cannot be
                // taken back once pressed.
                'send' => ['campaigns.send', 'Send a campaign'],
            ],
            'Audiences' => [
                'view' => ['campaigns.audiences.view', 'View audiences'],
                'update' => ['campaigns.audiences.rebuild', 'Rebuild the contact list'],
                // Separate because an export leaves the system carrying names,
                // addresses and telephone numbers in one file.
                'export' => ['campaigns.audiences.export', 'Export the audience list'],
            ],
            'Campaign Templates' => [
                'view' => ['campaigns.templates.view', 'View campaign templates'],
                'create' => ['campaigns.templates.create', 'Create a campaign template'],
                'update' => ['campaigns.templates.update', 'Edit a campaign template'],
                'delete' => ['campaigns.templates.delete', 'Delete a campaign template'],
            ],
            'Suppression' => [
                'view' => ['campaigns.suppression.view', 'View the suppression list'],
                'create' => ['campaigns.suppression.add', 'Add somebody to the suppression list'],
                // Filed as a restore because that is what it is: putting somebody
                // back on a list they were taken off.
                'restore' => ['campaigns.suppression.resubscribe', 'Resubscribe somebody'],
            ],
            'Campaign Reports' => [
                'view' => ['campaigns.reports.view', 'View campaign reports'],
                'export' => ['campaigns.reports.export', 'Export a campaign report'],
            ],
        ],

        /*
        | Running the competition itself, once the entries are in and the players
        | have been checked in at the door.
        |
        | generate is separate from create because one press writes dozens of
        | fixtures. score is separate from everything because it is the only one a
        | referee at the desk needs, and publish is separate because it is the only
        | one that changes what the public website shows.
        */
        'Tournament' => [
            'Tournaments' => [
                'view' => ['tournaments.view', 'View tournaments'],
                'create' => ['tournaments.create', 'Create a tournament'],
                'update' => ['tournaments.update', 'Edit a tournament, its entrants and stages'],
                'delete' => ['tournaments.delete', 'Delete a tournament'],
            ],
            'Matches' => [
                'view' => ['tournaments.matches.view', 'View matches'],
                // Its own permission: one press writes every fixture in a bracket,
                // and doing it twice on a live tournament would be a mess.
                'create' => ['tournaments.matches.generate', 'Generate brackets, groups and lobbies'],
                'update' => ['tournaments.matches.score', 'Enter match results'],
            ],
            'Standings' => [
                'view' => ['tournaments.standings.view', 'View standings'],
                // Separate because an export carries team and player details out of
                // the system in one file.
                'export' => ['tournaments.standings.export', 'Export standings'],
            ],
            'Point Rules' => [
                'view' => ['tournaments.rules.view', 'View point rules'],
                'create' => ['tournaments.rules.create', 'Create a point rule'],
                'update' => ['tournaments.rules.update', 'Edit a point rule'],
                'delete' => ['tournaments.rules.delete', 'Delete a point rule'],
            ],
            'Hall of Fame' => [
                'view' => ['tournaments.halloffame.view', 'View the hall of fame'],
                // The only permission in this section that changes the public site.
                'publish' => ['tournaments.halloffame.publish', 'Publish or withdraw a podium'],
            ],
            /*
            | Settings shared by every tournament, as distinct from Point Rules.
            |
            | Point Rules hold what a result is worth. These hold how the day is run:
            | the gap between matches, how long a late team is given before it
            | forfeits, whether a screenshot is required, and what the public site
            | shows. One set, used as the default by every new tournament.
            */
            'Tournament Settings' => [
                'view' => ['tournaments.settings.view', 'View tournament settings'],
                'update' => ['tournaments.settings.update', 'Update tournament settings'],
            ],
        ],

        /*
        | Split the same way and for the same reason. Somebody reconciling a bank
        | statement needs Settlements; they have no business reading every
        | participant's telephone number on the Unpaid list.
        */
        'Payments' => [
            'Payments Overview' => [
                'view' => ['payments.view', 'View the payments overview'],
            ],
            'All Transactions' => [
                'view' => ['payments.transactions.view', 'View every transaction'],
            ],
            'Unpaid & Failed' => [
                'view' => ['payments.unpaid.view', 'View unpaid and failed entries'],
            ],
            'Refunds' => [
                'view' => ['payments.refunds.view', 'View refunded entries'],
                // Separate from viewing, and deliberately not granted to Viewer.
                // This one sends money out of the account through CHIP, and undoing
                // it means talking to the gateway rather than clicking anything here.
                'update' => ['payments.refund', 'Send a refund through the gateway'],
            ],
            'Settlements' => [
                'view' => ['payments.settlements.view', 'View settlements'],
            ],
            'Payment Reports' => [
                'view' => ['payments.reports.view', 'View payment reports'],
                // Separate because an export leaves the system carrying names,
                // identity card numbers and amounts in one file.
                'export' => ['payments.export', 'Export payment reports'],
            ],
        ],

        /*
        | Shop. Three screens, each with its own actions.
        |
        | Settings is separated from Products because the switch that opens the shop
        | to the public is a different responsibility from writing a product
        | description, and the person who does one is often not the person who
        | decides the other.
        */
        'Shop' => [
            'Products' => [
                'view' => ['shop.products.view', 'View products'],
                'create' => ['shop.products.create', 'Add products'],
                'update' => ['shop.products.update', 'Edit products'],
                'delete' => ['shop.products.delete', 'Delete products'],
            ],
            /*
            | Orders. Confirming payment is separate from moving an order along,
            | because cash on delivery and bank transfers settle outside this system:
            | pressing it asserts that real money was received, which is a different
            | trust level from ticking an order as packed.
            */
            'Orders' => [
                'view' => ['shop.orders.view', 'View orders'],
                'update' => ['shop.orders.update', 'Move orders along'],
                'send' => ['shop.orders.payment', 'Confirm order payment received'],
            ],
            'Categories' => [
                'view' => ['shop.categories.view', 'View shop categories'],
                'create' => ['shop.categories.create', 'Add shop categories'],
                'update' => ['shop.categories.update', 'Edit shop categories'],
                'delete' => ['shop.categories.delete', 'Delete shop categories'],
            ],
            'Shop Settings' => [
                'view' => ['shop.settings.view', 'View shop settings'],
                'update' => ['shop.settings.update', 'Change shop settings'],
            ],
        ],

        /*
        | Portfolio. Website content rather than operations: nothing here touches a
        | participant, a payment or a result, so the four actions are the plain set
        | with no separate publish permission. Publishing is a status on the form,
        | and anyone trusted to write the entry is trusted to say it is finished.
        */
        'Portfolio' => [
            'Projects' => [
                'view' => ['portfolio.view', 'View portfolio projects'],
                'create' => ['portfolio.create', 'Add portfolio projects'],
                'update' => ['portfolio.update', 'Edit portfolio projects'],
                'delete' => ['portfolio.delete', 'Delete portfolio projects'],
            ],
            /*
            | Its own set rather than folded into Projects. Writing up a project and
            | publishing photographs of it are different jobs, and a photographer
            | handed the gallery has no business editing the copy or the client name.
            */
            'Gallery' => [
                'view' => ['portfolio.gallery.view', 'View portfolio gallery'],
                'create' => ['portfolio.gallery.create', 'Upload gallery photographs'],
                'update' => ['portfolio.gallery.update', 'Edit gallery photographs'],
                'delete' => ['portfolio.gallery.delete', 'Delete gallery photographs'],
            ],
        ],

        'General Config' => [
            'General Settings' => [
                'view' => ['settings.general.view', 'View general config'],
                'update' => ['settings.general.update', 'Update general config'],
            ],
            /*
            | View only. Taking a backup and restoring from one are not built: the
            | screen says so itself. Permissions for buttons that do not exist are
            | worse than no permissions, because a role appears to grant something
            | it cannot.
            */
            'Backup & Restore' => [
                'view' => ['settings.backup.view', 'View backup and restore'],
            ],
            'Maintenance' => [
                'view' => ['settings.maintenance.view', 'View maintenance mode'],
                'update' => ['settings.maintenance.update', 'Change maintenance mode'],
            ],
        ],

        'Integration' => [
            'Integration' => [
                'view' => ['settings.integration.view', 'View integrations'],
                'update' => ['settings.integration.update', 'Update integrations'],
            ],
        ],

        'Roles Management' => [
            'Roles' => [
                'view' => ['roles.view', 'View roles'],
                'create' => ['roles.create', 'Create roles'],
                'update' => ['roles.update', 'Update roles and permissions'],
                'delete' => ['roles.delete', 'Delete roles'],
            ],
        ],

        'User Management' => [
            'Users' => [
                'view' => ['users.view', 'View users'],
                'create' => ['users.create', 'Create users'],
                'update' => ['users.update', 'Update users'],
                'delete' => ['users.delete', 'Delete users'],
            ],
        ],

        'Logging' => [
            'Activity Log' => [
                'view' => ['logs.activity.view', 'View activity log'],
            ],
            'Audit Log' => [
                'view' => ['logs.audit.view', 'View audit log'],
            ],
        ],
    ];

    /**
     * Roles created on install. Super Admin holds every permission implicitly,
     * so it is not listed against individual slugs.
     *
     * @var array<string, array<string, mixed>>
     */
    private const ROLES = [
        Role::SUPER_ADMIN => [
            'name' => 'Super Admin',
            'description' => 'Full system access with all permissions.',
            'is_protected' => true,
            'permissions' => '*',
        ],
        'administrator' => [
            'name' => 'Administrator',
            'description' => 'Manages settings, users and content, but cannot restore backups.',
            'is_protected' => false,
            'permissions' => [
                'admin.access',
                'dashboard.view',
                'events.view',
                'events.create',
                'events.update',
                'events.delete',
                'participants.view',
                'participants.notify',
                'participants.delete',
                'attendance.view',
                'attendance.update',
                'attendance.remove-player',
                'event.settings.view',
                'event.settings.update',
                'reports.view',

                'payments.view',
                'payments.transactions.view',
                'payments.unpaid.view',
                'payments.refunds.view',
                'payments.settlements.view',
                'payments.reports.view',
                'payments.export',
                'payments.refund',

                'campaigns.view',
                'campaigns.create',
                'campaigns.update',
                'campaigns.delete',
                'campaigns.send',
                'campaigns.audiences.view',
                'campaigns.audiences.rebuild',
                'campaigns.audiences.export',
                'campaigns.templates.view',
                'campaigns.templates.create',
                'campaigns.templates.update',
                'campaigns.templates.delete',
                'campaigns.suppression.view',
                'campaigns.suppression.add',
                'campaigns.suppression.resubscribe',
                'campaigns.reports.view',
                'campaigns.reports.export',

                'tournaments.view',
                'tournaments.create',
                'tournaments.update',
                'tournaments.delete',
                'tournaments.matches.view',
                'tournaments.matches.generate',
                'tournaments.matches.score',
                'tournaments.standings.view',
                'tournaments.standings.export',
                'tournaments.rules.view',
                'tournaments.rules.create',
                'tournaments.rules.update',
                'tournaments.rules.delete',
                'tournaments.halloffame.view',
                'tournaments.halloffame.publish',
                'tournaments.settings.view',
                'tournaments.settings.update',

                'shop.products.view',
                'shop.products.create',
                'shop.products.update',
                'shop.products.delete',
                'shop.orders.view',
                'shop.orders.update',
                'shop.orders.payment',
                'shop.categories.view',
                'shop.categories.create',
                'shop.categories.update',
                'shop.categories.delete',
                'shop.settings.view',
                'shop.settings.update',

                'portfolio.view',
                'portfolio.create',
                'portfolio.update',
                'portfolio.delete',
                'portfolio.gallery.view',
                'portfolio.gallery.create',
                'portfolio.gallery.update',
                'portfolio.gallery.delete',

                'settings.general.view',
                'settings.general.update',
                'settings.backup.view',
                'settings.maintenance.view',
                'settings.maintenance.update',
                'settings.integration.view',
                'settings.integration.update',
                'roles.view',
                'users.view',
                'users.create',
                'users.update',
                'logs.activity.view',
                'logs.audit.view',
            ],
        ],
        /*
        | A referee at the scoring desk. Can read the fixtures and enter results, and
        | nothing else: cannot create a tournament, cannot generate a draw, cannot
        | change a point rule, cannot publish a podium.
        |
        | Their sidebar shows three Tournament items rather than six, because the
        | navigation hides exactly what they cannot reach.
        */
        'referee' => [
            'name' => 'Referee',
            'description' => 'Enters match results at the scoring desk, and nothing else.',
            'is_protected' => false,
            'permissions' => [
                'admin.access',
                'tournaments.view',
                'tournaments.matches.view',
                'tournaments.matches.score',
                'tournaments.standings.view',
            ],
        ],

        'viewer' => [
            'name' => 'Viewer',
            'description' => 'Read only access to the admin area.',
            'is_protected' => false,
            'permissions' => [
                'admin.access',
                'dashboard.view',
                'events.view',
                'participants.view',
                'attendance.view',
                'event.settings.view',
                'reports.view',

                // Read only: the money is visible, exporting it is not.
                'payments.view',
                'payments.transactions.view',
                'payments.unpaid.view',
                'payments.refunds.view',
                'payments.settlements.view',
                'payments.reports.view',

                // Can read what was sent, cannot write or send anything.
                'campaigns.view',
                'campaigns.audiences.view',
                'campaigns.templates.view',
                'campaigns.suppression.view',
                'campaigns.reports.view',

                // Can read the results, cannot enter or publish any.
                'tournaments.view',
                'tournaments.matches.view',
                'tournaments.standings.view',
                'tournaments.rules.view',
                'tournaments.halloffame.view',
                'tournaments.settings.view',

                /*
                | Can read the catalogue, cannot price anything, publish anything or
                | open the shop.
                */
                'shop.products.view',
                'shop.orders.view',
                'shop.categories.view',
                'shop.settings.view',

                // Can read the portfolio, cannot publish anything to the website.
                'portfolio.view',
                'portfolio.gallery.view',

                'settings.general.view',
                'settings.integration.view',
                'roles.view',
                'users.view',
                'logs.activity.view',
            ],
        ],
    ];

    public function run(): void
    {
        $permissionIds = $this->seedPermissions();

        foreach (self::ROLES as $slug => $definition) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_protected' => $definition['is_protected'],
                    'is_active' => true,
                ],
            );

            $granted = $definition['permissions'] === '*'
                ? array_values($permissionIds)
                : array_values(array_intersect_key($permissionIds, array_flip($definition['permissions'])));

            $role->permissions()->sync($granted);
        }
    }

    /**
     * @return array<string, int> permission slug => id
     */
    private function seedPermissions(): array
    {
        $ids = [];
        $sortOrder = 0;

        foreach (self::PERMISSIONS as $section => $modules) {
            foreach ($modules as $module => $actions) {
                foreach ($actions as $action => [$slug, $name]) {
                    $permission = Permission::updateOrCreate(
                        ['slug' => $slug],
                        [
                            'name' => $name,
                            'group' => $section,
                            'module' => $module,
                            'action' => $action,
                            'sort_order' => $sortOrder++,
                        ],
                    );

                    $ids[$slug] = $permission->id;
                }
            }
        }

        /*
        | Drop anything no longer declared above.
        |
        | updateOrCreate never deletes, so a permission that has been split apart or
        | withdrawn would sit in the table for ever and keep appearing on the matrix
        | as a checkbox that grants nothing. The pivot rows go with it through the
        | cascade on permission_role.
        */
        $removed = Permission::whereNotIn('slug', array_keys($ids))->pluck('slug');

        if ($removed->isNotEmpty()) {
            Permission::whereIn('slug', $removed)->delete();

            $this->command?->warn(sprintf(
                'Removed %d permission(s) no longer defined: %s',
                $removed->count(),
                $removed->implode(', '),
            ));
        }

        return $ids;
    }
}
