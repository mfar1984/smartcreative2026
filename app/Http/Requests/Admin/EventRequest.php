<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EventRequest extends FormRequest
{
    /**
     * Caps on the add-on builder. High enough for a real event, low enough that
     * a scripted post cannot bury the form in thousands of rows.
     */
    private const MAX_ADDONS = 20;
    private const MAX_VARIANTS = 40;

    /** @var Collection<int, EventAddon>|null */
    private ?Collection $existingAddons = null;

    public function authorize(): bool
    {
        // Routes already carry permission:events.create / events.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $event = $this->route('event');

        return [
            'title' => ['required', 'string', 'max:180'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('events', 'slug')->ignore($event?->id),
            ],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'rules' => ['nullable', 'string', 'max:10000'],

            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'time' => ['nullable', 'string', 'max:100'],

            'location' => ['required', 'string', 'max:180'],
            'address' => ['nullable', 'string', 'max:500'],

            'fee' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'seats_total' => ['required', 'integer', 'min:0', 'max:100000'],

            'status' => ['required', Rule::in(array_keys(Event::STATUSES))],
            'registration_mode' => ['required', Rule::in(array_keys(Event::MODES))],

            'min_players' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_players' => ['nullable', 'integer', 'min:1', 'max:1000', 'gte:min_players'],

            'requires_ign' => ['boolean'],
            'requires_logo' => ['boolean'],

            'registration_opens_at' => ['nullable', 'date'],
            'registration_closes_at' => ['nullable', 'date', 'after_or_equal:registration_opens_at'],

            // 4 MB keeps posters usable without letting a huge upload through.
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_poster' => ['nullable', 'boolean'],

            /* ---------------- Paid add-ons ---------------- */

            'addons' => ['array', 'max:' . self::MAX_ADDONS],
            'addons.*.id' => ['nullable', 'integer'],
            'addons.*.name' => ['required', 'string', 'max:180'],
            'addons.*.description' => ['nullable', 'string', 'max:255'],

            // Deliberately required rather than defaulted to zero: a forgotten
            // price would otherwise hand out merchandise for free.
            'addons.*.price' => ['required', 'numeric', 'min:0', 'max:999999.99'],

            'addons.*.max_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'addons.*.is_required' => ['boolean'],
            'addons.*.is_active' => ['boolean'],

            'addons.*.variants' => ['array', 'max:' . self::MAX_VARIANTS],
            'addons.*.variants.*.id' => ['nullable', 'integer'],
            'addons.*.variants.*.label' => ['required', 'string', 'max:60'],
            'addons.*.variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'addons.*.variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers and single hyphens.',
            'ends_at.after_or_equal' => 'The end date cannot be before the start date.',
            'max_players.gte' => 'The maximum number of players cannot be lower than the minimum.',
            'registration_closes_at.after_or_equal' => 'Registration cannot close before it opens.',

            'addons.max' => 'An event can carry at most ' . self::MAX_ADDONS . ' add-ons.',
            'addons.*.name.required' => 'Every add-on needs a name.',
            'addons.*.price.required' => 'Every add-on needs a price. Enter 0 for a free extra.',
            'addons.*.price.numeric' => 'The add-on price must be a number.',
            'addons.*.variants.max' => 'An add-on can carry at most ' . self::MAX_VARIANTS . ' options.',
            'addons.*.variants.*.label.required' => 'Every option needs a label, for example "Size M".',
            'addons.*.variants.*.price.numeric' => 'An option price must be a number, or blank to use the add-on price.',
            'addons.*.variants.*.stock.integer' => 'Stock must be a whole number, or blank for unlimited.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : $this->title,
            'slug' => filled($this->slug) ? str($this->slug)->slug()->toString() : null,
            'remove_poster' => $this->boolean('remove_poster'),
            'requires_ign' => $this->boolean('requires_ign'),
            'requires_logo' => $this->boolean('requires_logo'),
            // An empty fee box means free, not zero-that-was-typed.
            'fee' => $this->input('fee') === '' ? null : $this->input('fee'),
            'addons' => $this->normalisedAddons(),
        ]);
    }

    /**
     * Tidy the add-on rows before they are validated.
     *
     * Rows are re-indexed from zero so the error keys the view reads line up
     * with the rows it renders, and rows the operator opened but never filled
     * in are dropped rather than failing validation, so a stray click on
     * "Add an Add-on" does not block saving.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalisedAddons(): array
    {
        $rows = $this->input('addons');

        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = blank($row['id'] ?? null) ? null : (int) $row['id'];
            $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $price = $row['price'] ?? '';
            $variants = $this->normalisedVariants($row['variants'] ?? []);

            // Untouched blank row.
            if ($id === null && $name === '' && $price === '' && $variants === []) {
                continue;
            }

            $clean[] = [
                'id' => $id,
                'name' => $name,
                'description' => filled($row['description'] ?? null) ? trim((string) $row['description']) : null,
                'price' => $price === '' ? null : $price,
                'max_quantity' => blank($row['max_quantity'] ?? null) ? null : (int) $row['max_quantity'],
                'is_required' => (bool) ($row['is_required'] ?? false),
                'is_active' => (bool) ($row['is_active'] ?? false),
                'variants' => $variants,
            ];
        }

        return $clean;
    }

    /**
     * @param  mixed  $variants
     * @return array<int, array<string, mixed>>
     */
    private function normalisedVariants($variants): array
    {
        if (! is_array($variants)) {
            return [];
        }

        $clean = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $id = blank($variant['id'] ?? null) ? null : (int) $variant['id'];
            $label = is_string($variant['label'] ?? null) ? trim($variant['label']) : '';

            // A blank option row that was never typed into is dropped. One that
            // carries an id is kept, so clearing the label of a saved option is
            // reported rather than silently deleting it.
            if ($id === null && $label === '') {
                continue;
            }

            $clean[] = [
                'id' => $id,
                'label' => $label,
                'price' => ($variant['price'] ?? '') === '' ? null : $variant['price'],
                'stock' => ($variant['stock'] ?? '') === '' ? null : $variant['stock'],
            ];
        }

        return $clean;
    }

    /**
     * Cross field checks that only make sense once the basics pass.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $mode = $this->input('registration_mode');

                // Player bounds belong to squad registration only.
                if ($mode !== Event::MODE_MANAGER) {
                    return;
                }

                if (blank($this->input('min_players'))) {
                    $validator->errors()->add(
                        'min_players',
                        'Set the minimum number of players for a manager registration.'
                    );
                }
            },

            function (Validator $validator) {
                $closes = $this->input('registration_closes_at');

                if (blank($closes) || blank($this->input('ends_at'))) {
                    return;
                }

                // Taking entries after the event has finished is never intended.
                if (strtotime($closes) > strtotime($this->input('ends_at'))) {
                    $validator->errors()->add(
                        'registration_closes_at',
                        'Registration cannot close after the event has ended.'
                    );
                }
            },

            fn (Validator $validator) => $this->checkAddons($validator),
        ];
    }

    /* ---------------------------------------------------------------------
     | Add-on checks
     * ------------------------------------------------------------------ */

    /**
     * Everything about the add-on rows that a per field rule cannot express:
     * duplicate option labels, rows borrowed from another event, stock set
     * below what has already been sold, and rows removed despite having orders.
     */
    private function checkAddons(Validator $validator): void
    {
        $rows = $this->input('addons');

        if (! is_array($rows) || $rows === []) {
            $this->checkNothingSoldWasRemoved($validator, []);

            return;
        }

        $existing = $this->existingAddons();
        $seenIds = [];

        foreach ($rows as $i => $row) {
            $this->checkAddonPrice($validator, $i, $row);
            $this->checkDuplicateLabels($validator, $i, $row);

            $id = $row['id'] ?? null;

            if ($id === null) {
                $this->checkNewVariantsCarryNoId($validator, $i, $row);

                continue;
            }

            $addon = $existing->get($id);

            // An id the event does not own would let one event's form edit
            // another's catalogue.
            if ($addon === null) {
                $validator->errors()->add("addons.{$i}.name", 'That add-on does not belong to this event.');

                continue;
            }

            if (in_array($id, $seenIds, true)) {
                $validator->errors()->add("addons.{$i}.name", 'This add-on appears twice in the form.');

                continue;
            }

            $seenIds[] = $id;

            $this->checkVariantsAgainstStored($validator, $i, $row, $addon);
        }

        $this->checkNothingSoldWasRemoved($validator, $seenIds);
    }

    /**
     * A zero priced add-on is allowed, but only when it is deliberate: every
     * option must then name its own price, otherwise a blank price would give
     * the item away.
     */
    private function checkAddonPrice(Validator $validator, int|string $i, array $row): void
    {
        if ((float) ($row['price'] ?? 0) > 0) {
            return;
        }

        foreach (($row['variants'] ?? []) as $j => $variant) {
            if ($variant['price'] === null) {
                $validator->errors()->add(
                    "addons.{$i}.variants.{$j}.price",
                    sprintf(
                        'Set a price for "%s", or give the add-on itself a price for its options to inherit.',
                        $variant['label'] !== '' ? $variant['label'] : 'this option'
                    )
                );
            }
        }
    }

    private function checkDuplicateLabels(Validator $validator, int|string $i, array $row): void
    {
        $seen = [];

        foreach (($row['variants'] ?? []) as $j => $variant) {
            $key = mb_strtolower($variant['label']);

            if ($key === '') {
                continue;
            }

            // The table has a unique index on (add-on, label), so a duplicate
            // has to be caught here or the save would fail with a driver error.
            if (isset($seen[$key])) {
                $validator->errors()->add(
                    "addons.{$i}.variants.{$j}.label",
                    sprintf('"%s" is listed more than once in this add-on.', $variant['label'])
                );

                continue;
            }

            $seen[$key] = true;
        }
    }

    /**
     * A brand new add-on cannot own saved options.
     */
    private function checkNewVariantsCarryNoId(Validator $validator, int|string $i, array $row): void
    {
        foreach (($row['variants'] ?? []) as $j => $variant) {
            if ($variant['id'] !== null) {
                $validator->errors()->add("addons.{$i}.variants.{$j}.label", 'This option could not be matched to a saved add-on.');
            }
        }
    }

    private function checkVariantsAgainstStored(Validator $validator, int|string $i, array $row, EventAddon $addon): void
    {
        $stored = $addon->variants->keyBy('id');
        $kept = [];

        foreach (($row['variants'] ?? []) as $j => $variant) {
            $id = $variant['id'];

            if ($id === null) {
                continue;
            }

            $variantModel = $stored->get($id);

            if ($variantModel === null) {
                $validator->errors()->add("addons.{$i}.variants.{$j}.label", 'This option does not belong to this add-on.');

                continue;
            }

            $kept[] = $id;

            if ($variant['label'] === '') {
                $validator->errors()->add("addons.{$i}.variants.{$j}.label", 'An option needs a label.');
            }

            // Stock is a total, not a remainder, so it can never be set below
            // what has already gone out.
            if ($variant['stock'] !== null && (int) $variant['stock'] < $variantModel->stock_taken) {
                $validator->errors()->add(
                    "addons.{$i}.variants.{$j}.stock",
                    sprintf('%d already ordered, so stock cannot go below %d.', $variantModel->stock_taken, $variantModel->stock_taken)
                );
            }
        }

        // Options dropped from the form that people have already bought.
        $removedWithOrders = $addon->variants
            ->reject(fn (EventAddonVariant $variant) => in_array($variant->id, $kept, true))
            ->filter(fn (EventAddonVariant $variant) => $variant->stock_taken > 0);

        if ($removedWithOrders->isNotEmpty()) {
            $validator->errors()->add(
                "addons.{$i}.name",
                sprintf(
                    'Cannot remove %s because %s already been ordered. Set the stock to the number ordered to stop selling it.',
                    $removedWithOrders->map(fn ($variant) => '"' . $variant->label . '"')->join(', ', ' and '),
                    $removedWithOrders->count() === 1 ? 'it has' : 'they have',
                )
            );
        }
    }

    /**
     * Refuse to drop an add-on that appears on an invoice.
     *
     * The order lines would survive, because they keep their own copy of the
     * name and price, but the link back to the catalogue and its stock counts
     * would be gone for good. Deactivating is the intended path.
     *
     * @param  array<int, int>  $keptIds
     */
    private function checkNothingSoldWasRemoved(Validator $validator, array $keptIds): void
    {
        $sold = $this->existingAddons()
            ->reject(fn (EventAddon $addon) => in_array($addon->id, $keptIds, true))
            ->filter(fn (EventAddon $addon) => $addon->orderLines()->exists());

        if ($sold->isEmpty()) {
            return;
        }

        $validator->errors()->add(
            'addons',
            sprintf(
                'Cannot remove %s: %s already been bought. Untick "Offer this add-on" instead so the records stay intact.',
                $sold->map(fn (EventAddon $addon) => '"' . $addon->name . '"')->join(', ', ' and '),
                $sold->count() === 1 ? 'it has' : 'they have',
            )
        );
    }

    /**
     * Add-ons already saved against the event, keyed by id.
     *
     * Memoised because several checks walk the same list.
     *
     * @return Collection<int, EventAddon>
     */
    private function existingAddons(): Collection
    {
        if ($this->existingAddons !== null) {
            return $this->existingAddons;
        }

        $event = $this->route('event');

        return $this->existingAddons = $event instanceof Event
            ? $event->addons()->with('variants')->get()->keyBy('id')
            : collect();
    }

    /**
     * Attributes to write onto the event, poster handled separately.
     *
     * @return array<string, mixed>
     */
    public function eventAttributes(): array
    {
        $data = $this->safe()->except(['poster', 'remove_poster', 'slug', 'addons']);

        // Player bounds are meaningless outside manager mode, so they are
        // cleared rather than left behind as stale numbers.
        if ($data['registration_mode'] !== Event::MODE_MANAGER) {
            $data['min_players'] = null;
            $data['max_players'] = null;
        }

        return $data;
    }

    /**
     * Validated add-on rows, in the order they were submitted.
     *
     * @return array<int, array<string, mixed>>
     */
    public function addonRows(): array
    {
        return $this->validated()['addons'] ?? [];
    }
}
