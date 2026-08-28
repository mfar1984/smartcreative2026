<?php

namespace App\Http\Requests\Admin;

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
            'images.max' => 'A product can carry at most ' . self::MAX_IMAGES . ' images.',
            'variants.max' => 'A product can carry at most ' . self::MAX_VARIANTS . ' options.',
            'variants.*.label.required' => 'Every option needs a label, for example "Size M".',
            'variants.*.price.numeric' => 'An option price must be a number, or blank to use the product price.',
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
        ]);
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
        ];
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
        ]);

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
     * @return array<int, array<string, mixed>>
     */
    public function variantRows(): array
    {
        return $this->validated()['variants'] ?? [];
    }

    /**
     * @return array<int, int>
     */
    public function categoryIds(): array
    {
        return array_map('intval', $this->validated()['categories'] ?? []);
    }
}
