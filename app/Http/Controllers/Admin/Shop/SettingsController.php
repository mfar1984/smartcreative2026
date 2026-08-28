<?php

namespace App\Http\Controllers\Admin\Shop;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
        $tab = $this->resolveTab($request->query('tab'));

        return view('admin.shop.settings', [
            'tabs' => collect(self::SCHEMA)
                ->map(fn (array $definition) => [
                    'label' => $definition['label'],
                    'icon' => $definition['icon'],
                ])
                ->all(),
            'activeTab' => $tab,
            'definition' => self::SCHEMA[$tab],
            'values' => ShopSettings::all(),

            // Shown as context on the Storefront tab: opening a shop with nothing
            // active in it is worth a warning before it happens, not after.
            'activeProducts' => ShopProduct::query()->active()->count(),

            'canUpdate' => $request->user()->hasPermission('shop.settings.update'),
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
    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::SCHEMA) ? (string) $tab : 'storefront';
    }
}
