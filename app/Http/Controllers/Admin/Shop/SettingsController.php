<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ShopCategoryRequest;
use App\Models\Setting;
use App\Models\ShopCategory;
use App\Models\ShopProduct;
use App\Services\AdminLogger;
use App\Support\ShopSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shop settings, in two tabs.
 *
 * Same shape as the Integration screen: a schema keyed by tab drives the fields,
 * the validation and the save, so adding a setting means one entry rather than
 * edits in three places.
 *
 * There is nothing here about shipping rates, tax or checkout. Those belong with
 * the checkout, and settings for a feature nobody can reach would suggest the
 * feature exists.
 */
class SettingsController extends Controller
{
    /**
     * The category tab, kept out of SCHEMA because it holds records rather than
     * settings and is saved through its own routes.
     */
    public const TAB_CATEGORIES = 'categories';

    /**
     * Tab slug => label, icon, intro and its fields.
     *
     * Field types map onto the form: toggle, text, textarea, number.
     */
    public const SCHEMA = [
        'storefront' => [
            'label' => 'Storefront',
            'icon' => 'globe',
            'intro' => [
                'title' => 'What visitors see',
                'description' => 'Whether the shop is open at all, and the wording at the top of it.',
            ],
            'fields' => [
                'enabled' => [
                    'label' => 'Shop is open',
                    'type' => 'toggle',
                    'help' => 'Off means the Shop page says the shop is not open yet instead of listing products. Turn it on once you have products worth showing.',
                ],
                'heading' => [
                    'label' => 'Page Heading',
                    'type' => 'text',
                    'rules' => ['required', 'string', 'max:120'],
                    'help' => 'The large heading on the Shop page.',
                ],
                'intro' => [
                    'label' => 'Introduction',
                    'type' => 'textarea',
                    'rules' => ['nullable', 'string', 'max:400'],
                    'help' => 'One or two lines under the heading. Leave blank for none.',
                ],
                'per_page' => [
                    'label' => 'Products Per Page',
                    'type' => 'number',
                    'rules' => ['required', 'integer', 'min:4', 'max:48'],
                    'help' => 'Between 4 and 48.',
                ],
                'enquiry_note' => [
                    'label' => 'How To Order Note',
                    'type' => 'textarea',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'help' => 'Shown on every product page. Online checkout is not built yet, so this is what tells a buyer how to actually order.',
                ],
            ],
        ],

        'inventory' => [
            'label' => 'Inventory',
            'icon' => 'archive',
            'intro' => [
                'title' => 'Stock behaviour',
                'description' => 'What happens when something runs low, and how much of that a visitor is told.',
            ],
            'fields' => [
                'hide_sold_out' => [
                    'label' => 'Hide sold out products',
                    'type' => 'toggle',
                    'help' => 'On removes them from the shop entirely. Off leaves them listed with a Sold Out badge, which keeps the page populated and tells returning buyers the item is real.',
                ],
                'show_stock_count' => [
                    'label' => 'Show how many are left',
                    'type' => 'toggle',
                    'help' => 'On shows "4 left" when stock is low. Off shows nothing, which is worth choosing if you would rather not publish stock levels.',
                ],
                'low_stock_threshold' => [
                    'label' => 'Default Low Stock Warning',
                    'type' => 'number',
                    'rules' => ['required', 'integer', 'min:0', 'max:9999'],
                    'help' => 'Filled into a new product\'s low stock field. Changing it does not alter products that already exist.',
                ],
            ],
        ],
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $canSeeCategories = $user->hasPermission('shop.categories.view');

        $tab = $this->resolveTab($request->query('tab'), $canSeeCategories);

        /*
         | Categories is a tab on this screen but not an entry in SCHEMA, because
         | SCHEMA drives validation and saving for settings that are single values.
         | Categories are records with their own create, edit and delete routes, so
         | the tab only borrows the frame.
         */
        $tabs = collect(self::SCHEMA)
            ->map(fn (array $definition) => [
                'label' => $definition['label'],
                'icon' => $definition['icon'],
            ])
            ->all();

        if ($canSeeCategories) {
            $tabs[self::TAB_CATEGORIES] = ['label' => 'Categories', 'icon' => 'tag'];
        }

        $data = [
            'tabs' => $tabs,
            'activeTab' => $tab,
            'isCategoriesTab' => $tab === self::TAB_CATEGORIES,
            'values' => ShopSettings::all(),

            // Shown as context on the Storefront tab: opening a shop with nothing
            // active in it is worth a warning before it happens, not after.
            'activeProducts' => ShopProduct::query()->active()->count(),

            'canUpdate' => $user->hasPermission('shop.settings.update'),
        ];

        if ($tab === self::TAB_CATEGORIES) {
            return view('admin.shop.settings', $data + [
                // No SCHEMA entry, so the view is given an equivalent intro block
                // rather than being made to special case its own heading.
                'definition' => [
                    'label' => 'Categories',
                    'icon' => 'tag',
                    'intro' => [
                        'title' => 'How the shop is grouped',
                        'description' => 'Visitors use these as filters, so keep them few and obvious. A shop with no categories lists everything on one page, which is fine until there is a lot to look through.',
                    ],
                    'fields' => [],
                ],
                'categories' => ShopCategory::query()
                    ->withCount('products')
                    ->inDisplayOrder()
                    ->get(),
                'icons' => ShopCategoryRequest::ICONS,
                'canCreateCategory' => $user->hasPermission('shop.categories.create'),
                'canUpdateCategory' => $user->hasPermission('shop.categories.update'),
                'canDeleteCategory' => $user->hasPermission('shop.categories.delete'),
            ]);
        }

        return view('admin.shop.settings', $data + [
            'definition' => self::SCHEMA[$tab],
        ]);
    }

    public function update(Request $request, string $tab)
    {
        if (! array_key_exists($tab, self::SCHEMA)) {
            throw new NotFoundHttpException();
        }

        $fields = self::SCHEMA[$tab]['fields'];

        $validated = $request->validate(
            collect($fields)
                ->filter(fn (array $field) => isset($field['rules']))
                ->mapWithKeys(fn (array $field, string $key) => [$key => $field['rules']])
                ->all(),
        );

        foreach ($fields as $key => $field) {
            if ($field['type'] === 'toggle') {
                // Absent checkboxes mean off, so the value is read from the request
                // rather than from the validated set, which would not contain it.
                ShopSettings::put($key, $request->boolean($key) ? '1' : '0');

                continue;
            }

            ShopSettings::put($key, $validated[$key] ?? null);
        }

        ShopSettings::flush();

        AdminLogger::activity(
            'shop.settings.update',
            sprintf('Updated shop %s settings.', self::SCHEMA[$tab]['label']),
        );

        // Matches how the Integration and General Config screens audit a settings
        // save: an unsaved Setting carrying the wildcard key, because the change is
        // to a group of keys rather than to one record.
        AdminLogger::audit(
            new Setting(['key' => 'shop.*', 'group' => 'shop']),
            'settings.updated',
            null,
            ['tab' => $tab, 'keys' => array_keys($fields)],
        );

        return redirect()
            ->route('admin.shop.settings', ['tab' => $tab])
            ->with('status', sprintf('%s settings saved.', self::SCHEMA[$tab]['label']));
    }

    /**
     * An unknown tab falls back rather than 404ing, so a stale bookmark still
     * opens the screen.
     */
    private function resolveTab(?string $tab, bool $canSeeCategories): string
    {
        $tab = (string) $tab;

        /*
         | Categories is accepted only when the account may see it. Without this an
         | account holding shop.settings.view but not shop.categories.view could read
         | the category list by editing the query string.
         */
        if ($tab === self::TAB_CATEGORIES) {
            return $canSeeCategories ? self::TAB_CATEGORIES : 'storefront';
        }

        return array_key_exists($tab, self::SCHEMA) ? $tab : 'storefront';
    }
}
