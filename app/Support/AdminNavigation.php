<?php

namespace App\Support;

use App\Models\User;

class AdminNavigation
{
    /**
     * The admin menu, already filtered down to what the given user may see.
     *
     * The tree supports three node kinds:
     *   item    a single link
     *   group   a collapsible parent with child links
     *   section a labelled band holding items and groups
     *
     * Adding a new screen means adding one entry here; the sidebar view walks
     * whatever this returns.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function for(User $user): array
    {
        return self::filter(self::definition(), $user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function definition(): array
    {
        return [
            [
                'kind' => 'item',
                'label' => 'Dashboard',
                'icon' => 'grid',
                'route' => 'admin.dashboard',
                'active' => 'admin.dashboard',
                'permission' => 'dashboard.view',
            ],

            [
                'kind' => 'section',
                // Deliberately not "Event": the group below already carries
                // that name, and repeating it reads badly in the sidebar.
                'label' => 'Modules',
                'items' => [
                    [
                        'kind' => 'group',
                        'key' => 'event',
                        'label' => 'Event',
                        'icon' => 'clipboard',
                        'children' => [
                            [
                                'label' => 'Registration',
                                'route' => 'admin.event.registration',
                                'active' => 'admin.event.registration',
                                'permission' => 'events.view',
                            ],
                            [
                                'label' => 'Participants',
                                'route' => 'admin.event.participants',
                                // Wildcard so the detail page keeps the item lit.
                                'active' => 'admin.event.participants*',
                                'permission' => 'participants.view',
                            ],
                            [
                                'label' => 'Attendance',
                                'route' => 'admin.event.attendance',
                                'active' => 'admin.event.attendance',
                                'permission' => 'attendance.view',
                            ],
                            [
                                'label' => 'Analytic Reporting',
                                'route' => 'admin.event.reporting',
                                'active' => 'admin.event.reporting',
                                'permission' => 'reports.view',
                            ],
                            // Configuration sits below the screens that use it.
                            [
                                'label' => 'Settings',
                                'route' => 'admin.event.settings',
                                'active' => 'admin.event.settings*',
                                'permission' => 'event.settings.view',
                            ],
                        ],
                    ],

                    /*
                    | Tournament sits directly after Event because it reads the same
                    | registrations and continues the same afternoon: take entries,
                    | check people in at the door, then run the competition.
                    |
                    | Its own group rather than another Event child, for the reason
                    | Payments has one: the referee entering scores at the desk is not
                    | the person managing the registration list.
                    */
                    [
                        'kind' => 'group',
                        'key' => 'tournament',
                        'label' => 'Tournament',
                        'icon' => 'trophy',
                        'children' => [
                            [
                                'label' => 'Tournaments',
                                'route' => 'admin.tournaments.index',
                                'active' => 'admin.tournaments.index',
                                'permission' => 'tournaments.view',
                            ],
                            [
                                'label' => 'Matches',
                                'route' => 'admin.tournaments.matches',
                                // Wildcard so the score entry screen keeps this lit.
                                'active' => 'admin.tournaments.matches*',
                                'permission' => 'tournaments.matches.view',
                            ],
                            [
                                'label' => 'Standings',
                                'route' => 'admin.tournaments.standings',
                                'active' => 'admin.tournaments.standings',
                                'permission' => 'tournaments.standings.view',
                            ],
                            [
                                'label' => 'Point Rules',
                                'route' => 'admin.tournaments.rules',
                                'active' => 'admin.tournaments.rules*',
                                'permission' => 'tournaments.rules.view',
                            ],
                            [
                                'label' => 'Hall of Fame',
                                'route' => 'admin.tournaments.hall-of-fame',
                                'active' => 'admin.tournaments.hall-of-fame',
                                'permission' => 'tournaments.halloffame.view',
                            ],
                            // Configuration sits below the screens that use it, the
                            // same way Event does it.
                            [
                                'label' => 'Settings',
                                'route' => 'admin.tournaments.settings',
                                'active' => 'admin.tournaments.settings*',
                                'permission' => 'tournaments.settings.view',
                            ],
                        ],
                    ],

                    /*
                    | Payments reads the same registrations the Event group does,
                    | from the money end. Its own group rather than another Event
                    | child, because the person reconciling a bank statement is not
                    | the person running a registration desk.
                    */
                    /*
                    | Campaign reaches the same people the Event group registered,
                    | but for something they did not ask for. That difference is why
                    | it has its own group, its own consent rules and its own
                    | suppression list rather than living inside Event.
                    */
                    [
                        'kind' => 'group',
                        'key' => 'campaign',
                        'label' => 'Campaign',
                        'icon' => 'send',
                        'children' => [
                            [
                                'label' => 'Campaigns',
                                'route' => 'admin.campaigns.index',
                                'active' => 'admin.campaigns.index',
                                'permission' => 'campaigns.view',
                            ],
                            [
                                'label' => 'Audiences',
                                'route' => 'admin.campaigns.audiences',
                                'active' => 'admin.campaigns.audiences',
                                'permission' => 'campaigns.audiences.view',
                            ],
                            [
                                'label' => 'Templates',
                                'route' => 'admin.campaigns.templates',
                                'active' => 'admin.campaigns.templates*',
                                'permission' => 'campaigns.templates.view',
                            ],
                            [
                                'label' => 'Suppression',
                                'route' => 'admin.campaigns.suppression',
                                'active' => 'admin.campaigns.suppression',
                                'permission' => 'campaigns.suppression.view',
                            ],
                            [
                                'label' => 'Reports',
                                'route' => 'admin.campaigns.reports',
                                'active' => 'admin.campaigns.reports*',
                                'permission' => 'campaigns.reports.view',
                            ],
                        ],
                    ],

                    [
                        'kind' => 'group',
                        'key' => 'payments',
                        'label' => 'Payments',
                        'icon' => 'credit-card',
                        'children' => [
                            [
                                'label' => 'Overview',
                                'route' => 'admin.payments.overview',
                                'active' => 'admin.payments.overview',
                                'permission' => 'payments.view',
                            ],
                            [
                                'label' => 'All Transactions',
                                'route' => 'admin.payments.transactions',
                                'active' => 'admin.payments.transactions',
                                'permission' => 'payments.transactions.view',
                            ],
                            [
                                'label' => 'Unpaid & Failed',
                                'route' => 'admin.payments.unpaid',
                                'active' => 'admin.payments.unpaid',
                                'permission' => 'payments.unpaid.view',
                            ],
                            [
                                'label' => 'Refunds',
                                'route' => 'admin.payments.refunds',
                                'active' => 'admin.payments.refunds',
                                'permission' => 'payments.refunds.view',
                            ],
                            [
                                'label' => 'Settlements',
                                'route' => 'admin.payments.settlements',
                                'active' => 'admin.payments.settlements',
                                'permission' => 'payments.settlements.view',
                            ],
                            [
                                'label' => 'Reports',
                                'route' => 'admin.payments.reports',
                                'active' => 'admin.payments.reports',
                                'permission' => 'payments.reports.view',
                            ],
                        ],
                    ],

                    /*
                    | Shop. Sits after Payments because it is operational, and its
                    | three children are the three things a shop is made of: what is
                    | for sale, how it is grouped, and whether the doors are open.
                    */
                    [
                        'kind' => 'group',
                        'key' => 'shop',
                        'label' => 'Shop',
                        'icon' => 'bag',
                        'children' => [
                            [
                                'label' => 'Products',
                                'route' => 'admin.shop.products',
                                // Wildcard so create and edit keep this item lit.
                                'active' => 'admin.shop.products*',
                                'permission' => 'shop.products.view',
                            ],
                            /*
                            | Categories is not listed here. It lives as a tab inside
                            | Settings, because it describes how the shop is arranged
                            | rather than being a screen anybody visits on its own.
                            |
                            | The category routes are still matched below so that
                            | arriving at one keeps Settings lit.
                            */
                            [
                                'label' => 'Settings',
                                'route' => 'admin.shop.settings',
                                'active' => ['admin.shop.settings*', 'admin.shop.categories*'],
                                'permission' => 'shop.settings.view',
                            ],
                        ],
                    ],

                    /*
                    | Portfolio. Website content rather than operations, so it sits
                    | last in Modules: nothing here touches a participant, a payment
                    | or a result.
                    |
                    | A group with one child rather than a plain item, so the second
                    | screen this will grow does not force the sidebar to be
                    | restructured later.
                    */
                    [
                        'kind' => 'group',
                        'key' => 'portfolio',
                        'label' => 'Portfolio',
                        'icon' => 'photo',
                        'children' => [
                            [
                                'label' => 'Projects',
                                'route' => 'admin.portfolio.index',
                                /*
                                | Listed rather than wildcarded. `admin.portfolio.*`
                                | would also match the gallery routes and light both
                                | children at once.
                                */
                                'active' => [
                                    'admin.portfolio.index',
                                    'admin.portfolio.create',
                                    'admin.portfolio.edit',
                                ],
                                'permission' => 'portfolio.view',
                            ],
                            [
                                'label' => 'Gallery',
                                'route' => 'admin.portfolio.gallery',
                                'active' => 'admin.portfolio.gallery*',
                                'permission' => 'portfolio.gallery.view',
                            ],
                        ],
                    ],
                ],
            ],

            [
                'kind' => 'section',
                'label' => 'System',
                'items' => [
                    [
                        'kind' => 'group',
                        'key' => 'settings',
                        'label' => 'Settings',
                        'icon' => 'cog',
                        'children' => [
                            [
                                'label' => 'General Config',
                                'route' => 'admin.settings.general',
                                'active' => 'admin.settings.general',
                                'permission' => 'settings.general.view',
                            ],
                            [
                                'label' => 'Integration',
                                'route' => 'admin.settings.integration',
                                'active' => 'admin.settings.integration',
                                'permission' => 'settings.integration.view',
                            ],
                            [
                                'label' => 'Roles Management',
                                'route' => 'admin.settings.roles',
                                // Wildcard so create, show and edit keep the item lit.
                                'active' => 'admin.settings.roles*',
                                'permission' => 'roles.view',
                            ],
                            [
                                'label' => 'User Management',
                                'route' => 'admin.settings.users',
                                'active' => 'admin.settings.users',
                                'permission' => 'users.view',
                            ],
                            [
                                'label' => 'Logging',
                                'route' => 'admin.settings.logging',
                                'active' => 'admin.settings.logging',
                                'permission' => 'logs.activity.view',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Drop anything the user has no permission for, then drop groups and
     * sections that ended up empty so no bare heading is left behind.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private static function filter(array $nodes, User $user): array
    {
        $visible = [];

        foreach ($nodes as $node) {
            switch ($node['kind']) {
                case 'item':
                    if (self::allowed($node, $user)) {
                        $visible[] = $node;
                    }
                    break;

                case 'group':
                    $children = array_values(array_filter(
                        $node['children'],
                        fn (array $child) => self::allowed($child, $user)
                    ));

                    if ($children !== []) {
                        $node['children'] = $children;
                        $visible[] = $node;
                    }
                    break;

                case 'section':
                    $items = self::filter($node['items'], $user);

                    if ($items !== []) {
                        $node['items'] = $items;
                        $visible[] = $node;
                    }
                    break;
            }
        }

        return $visible;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function allowed(array $node, User $user): bool
    {
        return ! isset($node['permission']) || $user->hasPermission($node['permission']);
    }
}
