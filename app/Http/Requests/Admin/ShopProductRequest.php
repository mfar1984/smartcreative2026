<?php

namespace App\Http\Requests\Admin;

use App\Models\ShopOrder;
use App\Models\ShopProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShopProductRequest extends FormRequest
{
    /**
     * Caps on the variant builder. High enough for a shirt in every size and
     * colour, low enough that a scripted post cannot bury the form.
     */
    private const MAX_VARIANTS = 60;

    /** How many pictures one product may carry. */
    private const MAX_IMAGES = 8;

    /** @var Collection<int, \App\Models\ShopProductVariant>|null */
    private ?Collection $storedVariants = null;

    public function authorize(): bool
    {
        // Routes already carry permission:shop.products.create / shop.products.update
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            /* ---------------- Basic ---------------- */

            'name' => ['required', 'string', 'max:180'],
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('shop_products', 'slug')->ignore($product?->id),
            ],

            /*
             | Unique so two products cannot share a code. Nullable because not
             | every item has one, and MySQL permits many nulls in a unique index.
             */
            'sku' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('shop_products', 'sku')->ignore($product?->id),
            ],
            'barcode' => ['nullable', 'string', 'max:80'],

            'short_description' => ['nullable', 'string', 'max:400'],
            'description' => ['nullable', 'string', 'max:20000'],

            /* ---------------- Pricing ---------------- */

            // Required rather than defaulted: a forgotten price would give stock away.
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            /* ---------------- Inventory ---------------- */

            'track_inventory' => ['boolean'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:9999'],

            /* ---------------- Shipping, entered in kg and cm ---------------- */

            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'length_cm' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'width_cm' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:500'],

            /* ---------------- Copy ---------------- */

            'highlights' => ['nullable', 'string', 'max:2000'],
            'included_items' => ['nullable', 'string', 'max:2000'],
            'specifications' => ['nullable', 'string', 'max:4000'],

            /* ---------------- Organisation ---------------- */

            'vendor' => ['nullable', 'string', 'max:180'],
            'brand' => ['nullable', 'string', 'max:180'],

            'status' => ['required', Rule::in(array_keys(ShopProduct::STATUSES))],

            /*
             | How this product may be paid for. At least one, always: a product
             | that accepts no method reaches the storefront looking for sale and
             | then cannot be bought, which is worse than not listing it.
             |
             | Validated against the whole set of methods rather than against the
             | ones currently switched on. The shop's switches are the ceiling at
             | checkout, not at save time, so an administrator can set a product up
             | before wiring the gateway, and turning a method off in settings does
             | not silently rewrite every product that accepted it.
             */
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['string', Rule::in(array_keys(ShopOrder::METHODS))],

            /* ---------------- Posted out, or collected in person ---------------- */

            /*
             | Which way this product reaches the buyer. Not nullable and not
             | defaulted here: postage applies to one and not the other, so leaving it
             | unanswered would decide it by accident.
             */
            'fulfilment' => ['required', Rule::in(array_keys(ShopProduct::FULFILMENTS))],

            // Only meaningful for an offline product. Cross checked in after().
            'collection_source' => ['nullable', Rule::in(['event', 'manual'])],
            'collection_event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'collection_location' => ['nullable', 'string', 'max:190'],
            'collection_at' => ['nullable', 'date'],

            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],

            'categories' => ['array'],
            'categories.*' => ['integer', Rule::exists('shop_categories', 'id')],

            /* ---------------- Search engines ---------------- */

            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:180'],
            'seo_keywords' => ['nullable', 'string', 'max:255'],

            /* ---------------- Images ---------------- */

            'images' => ['array', 'max:' . self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_images' => ['array'],
            'remove_images.*' => ['integer'],
            'featured_image' => ['nullable', 'integer'],

            /* ---------------- Variants ---------------- */

            'option_name' => ['nullable', 'string', 'max:60'],

            'variants' => ['array', 'max:' . self::MAX_VARIANTS],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.label' => ['required', 'string', 'max:60'],
            'variants.*.sku' => ['nullable', 'string', 'max:80'],

            // Nullable is meaningful: blank means charge the product price.
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            /*
             | Blank means use the product's weight, which is what most options
             | want. Accepted in kilograms because that is what a scale and a
             | courier both speak, and converted to whole grams once on the way in,
             | the same as the product field above.
             |
             | Three decimals is one gram. Anything finer is beyond what any
             | courier prices on.
             */
            'variants.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999.999'],

            // Nullable means unlimited.
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The URL slug may only contain lowercase letters, numbers and single hyphens.',
            'sku.unique' => 'Another product already uses that SKU.',
            'price.required' => 'Every product needs a price. Enter 0 if it is given away.',

            'payment_methods.required' => 'Choose at least one way this product can be paid for. With none ticked nobody could buy it.',
            'payment_methods.min' => 'Choose at least one way this product can be paid for. With none ticked nobody could buy it.',
            'payment_methods.*.in' => 'That is not a payment method this shop knows about.',

            'images.max' => 'A product can carry at most ' . self::MAX_IMAGES . ' images.',
            'variants.max' => 'A product can carry at most ' . self::MAX_VARIANTS . ' options.',
            'variants.*.label.required' => 'Every option needs a label, for example "Size M".',
            'variants.*.price.numeric' => 'An option price must be a number, or blank to use the product price.',
            'variants.*.weight_kg.numeric' => 'An option weight must be a number in kilograms, or blank to use the product weight.',
            'variants.*.stock.integer' => 'Option stock must be a whole number, or blank for unlimited.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'slug' => filled($this->input('slug')) ? str($this->input('slug'))->slug()->toString() : null,
            'sku' => filled($this->input('sku')) ? trim((string) $this->input('sku')) : null,
            'barcode' => filled($this->input('barcode')) ? trim((string) $this->input('barcode')) : null,
            'vendor' => filled($this->input('vendor')) ? trim((string) $this->input('vendor')) : null,
            'brand' => filled($this->input('brand')) ? trim((string) $this->input('brand')) : null,

            // Unchecked boxes are absent from the payload rather than false.
            'track_inventory' => $this->boolean('track_inventory'),
            'is_featured' => $this->boolean('is_featured'),

            'sort_order' => $this->filled('sort_order') ? (int) $this->input('sort_order') : 0,

            // Blank means "not on offer", which is not the same as zero.
            'compare_at_price' => $this->filled('compare_at_price') ? $this->input('compare_at_price') : null,
            'cost_price' => $this->filled('cost_price') ? $this->input('cost_price') : null,

            'variants' => $this->normalisedVariants(),
            'payment_methods' => $this->normalisedPaymentMethods(),
        ]);
    }

    /**
     * The ticked payment methods, de-duplicated and re-indexed.
     *
     * Unticked boxes send nothing, so an empty list here is what "none chosen"
     * looks like and the required rule reports it. Duplicates are dropped rather
     * than stored twice, which would otherwise show the same radio button twice
     * at checkout.
     *
     * @return array<int, string>
     */
    private function normalisedPaymentMethods(): array
    {
        $methods = $this->input('payment_methods');

        if (! is_array($methods)) {
            return [];
        }

        return collect($methods)
            ->filter(fn ($method) => is_string($method) && $method !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkOfferIsAnOffer($validator),
            fn (Validator $validator) => $this->checkVariantLabelsAreUnique($validator),
            fn (Validator $validator) => $this->checkOptionNameIsGiven($validator),
            fn (Validator $validator) => $this->checkNothingSoldWasRemoved($validator),
            fn (Validator $validator) => $this->checkCollectionPointIsUsable($validator),
        ];
    }

    /**
     * An offline product needs somewhere and some time to be collected.
     *
     * Either an event already in the system, or a location and a moment typed in.
     * Both halves of the manual pair are required together: a place with no time, or
     * a time with no place, is not something a buyer can turn up for.
     */
    private function checkCollectionPointIsUsable(Validator $validator): void
    {
        if ($this->input('fulfilment') !== ShopProduct::FULFILMENT_OFFLINE) {
            return;
        }

        if ($this->input('collection_source') === 'event') {
            if (blank($this->input('collection_event_id'))) {
                $validator->errors()->add(
                    'collection_event_id',
                    'Choose the event this is collected at, or switch to entering the details by hand.',
                );
            }

            return;
        }

        if (blank($this->input('collection_location'))) {
            $validator->errors()->add(
                'collection_location',
                'Say where this is collected from. A buyer needs somewhere to turn up to.',
            );
        }

        if (blank($this->input('collection_at'))) {
            $validator->errors()->add(
                'collection_at',
                'Say when it can be collected, date and time. A date on its own is not something anybody can turn up for.',
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Cross field checks
     * ------------------------------------------------------------------ */

    /**
     * A compare-at price at or below the real price advertises a saving that does
     * not exist, so it is refused rather than quietly ignored.
     */
    private function checkOfferIsAnOffer(Validator $validator): void
    {
        $compare = $this->input('compare_at_price');

        if ($compare === null || $compare === '') {
            return;
        }

        if ((float) $compare <= (float) $this->input('price')) {
            $validator->errors()->add(
                'compare_at_price',
                'The compare at price has to be higher than the price, otherwise there is no saving to show. Leave it blank if the product is not on offer.',
            );
        }
    }

    /**
     * Two options with the same label are indistinguishable to a buyer, and the
     * table has a unique index that would otherwise fail as a server error.
     */
    private function checkVariantLabelsAreUnique(Validator $validator): void
    {
        $seen = [];

        foreach ((array) $this->input('variants', []) as $index => $variant) {
            $label = mb_strtolower(trim((string) ($variant['label'] ?? '')));

            if ($label === '') {
                continue;
            }

            if (isset($seen[$label])) {
                $validator->errors()->add(
                    "variants.{$index}.label",
                    sprintf('This option repeats "%s". Every option needs its own label.', $variant['label']),
                );

                continue;
            }

            $seen[$label] = true;
        }
    }

    /**
     * With options on offer, the chooser needs a heading. "Choose an option" tells
     * a buyer nothing when the answer is a shirt size.
     */
    private function checkOptionNameIsGiven(Validator $validator): void
    {
        $variants = (array) $this->input('variants', []);

        if ($variants !== [] && blank($this->input('option_name'))) {
            $validator->errors()->add(
                'option_name',
                'Name what the options are, for example Size or Colour. It becomes the heading buyers pick from.',
            );
        }
    }

    /**
     * Removing an option somebody has already ordered would orphan their order
     * line, so it is refused. Setting its stock to what has been taken stops it
     * selling while keeping the record.
     */
    private function checkNothingSoldWasRemoved(Validator $validator): void
    {
        $stored = $this->storedVariants();

        if ($stored->isEmpty()) {
            return;
        }

        $keptIds = collect($this->input('variants', []))
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $removedWithOrders = $stored
            ->whereNotIn('id', $keptIds)
            ->filter(fn ($variant) => $variant->stock_taken > 0);

        foreach ($removedWithOrders as $variant) {
            $validator->errors()->add(
                'variants',
                sprintf(
                    'Option "%s" has %d order(s), so it cannot be removed. Set its stock to %d to stop selling it instead.',
                    $variant->label,
                    $variant->stock_taken,
                    $variant->stock_taken,
                ),
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Normalising
     * ------------------------------------------------------------------ */

    /**
     * Re-index rows from zero so error keys line up with the rows on screen, drop
     * rows that were never filled in, and keep null distinct from zero.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalisedVariants(): array
    {
        $rows = $this->input('variants');

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function ($row) {
                if (! is_array($row)) {
                    return null;
                }

                $id = ($row['id'] ?? '') === '' ? null : (int) $row['id'];
                $label = trim((string) ($row['label'] ?? ''));
                $sku = trim((string) ($row['sku'] ?? ''));
                $price = ($row['price'] ?? '') === '' ? null : $row['price'];
                $stock = ($row['stock'] ?? '') === '' ? null : $row['stock'];

                /*
                 | A stray click on "Add an option" leaves an untouched row. Dropping
                 | it means that click cannot block a save. A row that carries an id
                 | is kept even when blank, so clearing a saved option's label is
                 | reported rather than silently deleting it.
                 */
                if ($id === null && $label === '' && $sku === '' && $price === null && $stock === null) {
                    return null;
                }

                return [
                    'id' => $id,
                    'label' => $label,
                    'sku' => $sku === '' ? null : $sku,
                    'price' => $price,
                    'stock' => $stock,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The options currently stored against the product being edited.
     *
     * @return Collection<int, \App\Models\ShopProductVariant>
     */
    private function storedVariants(): Collection
    {
        if ($this->storedVariants !== null) {
            return $this->storedVariants;
        }

        $product = $this->route('product');

        return $this->storedVariants = $product instanceof ShopProduct
            ? $product->variants()->get()
            : collect();
    }

    /* ---------------------------------------------------------------------
     | Handing over to the controller
     * ------------------------------------------------------------------ */

    /**
     * Attributes ready to fill the model.
     *
     * The unit conversions happen here, once, so nothing downstream has to
     * remember whether a number is kilograms or grams.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $data = $this->safe()->except([
            'slug',
            'categories',
            'variants',
            'images',
            'remove_images',
            'featured_image',
            'weight_kg',
            'length_cm',
            'width_cm',
            'height_cm',

            // A radio that decides which of the collection fields to keep. It is a
            // question about the form, not a property of the product.
            'collection_source',
        ]);

        /*
         | Exactly one collection point survives, and switching away clears the other.
         |
         | Written here rather than left to whatever the form posted, because both
         | halves of the form exist in the markup at once: without this, unticking
         | "at an event" and saving would leave the old event id behind and the
         | product would still be collected somewhere nobody chose.
         */
        $data = array_merge($data, $this->collectionColumns());

        $data['weight_grams'] = $this->filled('weight_kg')
            ? (int) round((float) $this->input('weight_kg') * 1000)
            : null;

        foreach (['length' => 'length_mm', 'width' => 'width_mm', 'height' => 'height_mm'] as $field => $column) {
            $data[$column] = $this->filled($field . '_cm')
                ? (int) round((float) $this->input($field . '_cm') * 10)
                : null;
        }

        // Meaningless without options, and leaving it behind would put a stray
        // heading on the storefront.
        if ($this->input('variants', []) === []) {
            $data['option_name'] = null;
        }

        return $data;
    }

    /**
     * The three collection columns, resolved to one answer.
     *
     * An online product carries none of them. An offline one carries either the event
     * or the manual pair, never a mixture, so nothing downstream has to decide which
     * of two filled-in sources it meant.
     *
     * @return array{collection_event_id: int|null, collection_location: string|null, collection_at: string|null}
     */
    private function collectionColumns(): array
    {
        $blank = [
            'collection_event_id' => null,
            'collection_location' => null,
            'collection_at' => null,
        ];

        if ($this->input('fulfilment') !== ShopProduct::FULFILMENT_OFFLINE) {
            return $blank;
        }

        if ($this->input('collection_source') === 'event') {
            return array_merge($blank, [
                'collection_event_id' => (int) $this->input('collection_event_id'),
            ]);
        }

        return array_merge($blank, [
            'collection_location' => trim((string) $this->input('collection_location')),
            'collection_at' => $this->input('collection_at'),
        ]);
    }

    /**
     * The option rows, with the weight converted the way the product's is.
     *
     * The form asks for kilograms because that is what a scale reads and what a
     * courier quotes in; the column is whole grams. Converting here rather than in
     * the writer keeps the rule in one place: kilograms in, grams out, rounded
     * once. Blank stays null, which means "the product weighs this too".
     *
     * @return array<int, array<string, mixed>>
     */
    public function variantRows(): array
    {
        $rows = $this->validated()['variants'] ?? [];

        return array_map(function (array $row) {
            $kg = $row['weight_kg'] ?? null;

            $row['weight_grams'] = ($kg === null || $kg === '')
                ? null
                : (int) round((float) $kg * 1000);

            unset($row['weight_kg']);

            return $row;
        }, $rows);
    }

    /**
     * @return array<int, int>
     */
    public function categoryIds(): array
    {
        return array_map('intval', $this->validated()['categories'] ?? []);
    }
}
