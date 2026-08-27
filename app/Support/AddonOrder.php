<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventAddon;
use App\Models\EventAddonVariant;

/**
 * The add-on part of a registration, worked out from submitted quantities.
 *
 * Every price comes from the database. The form only ever says how many of
 * something is wanted, so a tampered payload cannot set its own price.
 *
 * The same builder runs twice: once during validation to report problems, and
 * again inside the storing transaction against a locked event, so stock that
 * ran out in between is caught rather than oversold.
 */
class AddonOrder
{
    /**
     * Quantity a single line may carry. Generous for a team kit order, low
     * enough that a scripted post cannot ask for a million shirts.
     */
    private const MAX_LINE_QUANTITY = 200;

    /**
     * @param  array<int, array<string, mixed>>  $lines   ready for EventRegistrationAddon
     * @param  array<string, string>             $errors  input path => message
     */
    private function __construct(
        public readonly array $lines,
        public readonly array $errors,
    ) {
    }

    /**
     * @param  mixed  $input  the raw addons input: [addonId => [variantId|'base' => qty]]
     */
    public static function build(Event $event, mixed $input): self
    {
        $lines = [];
        $errors = [];

        $submitted = is_array($input) ? $input : [];

        // Keyed by id so an unknown key is a tampered payload, not a lookup miss.
        $catalogue = $event->addons->keyBy('id');

        foreach ($submitted as $addonId => $quantities) {
            $addon = $catalogue->get((int) $addonId);

            if ($addon === null) {
                $errors["addons.{$addonId}"] = 'One of the extras is no longer available. Please reload the page.';

                continue;
            }

            if (! $addon->is_active) {
                // Silently ignored rather than reported: an add-on withdrawn
                // while the form was open is not the visitor's mistake, and a
                // zero quantity for it is the common case.
                if (self::wants($quantities)) {
                    $errors["addons.{$addonId}"] = sprintf('"%s" is no longer on sale.', $addon->name);
                }

                continue;
            }

            [$addonLines, $addonErrors] = self::buildAddon($addon, $quantities);

            $lines = array_merge($lines, $addonLines);
            $errors = array_merge($errors, $addonErrors);
        }

        $errors = array_merge($errors, self::checkRequired($event, $lines));

        return new self($lines, $errors);
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private static function buildAddon(EventAddon $addon, mixed $quantities): array
    {
        $lines = [];
        $errors = [];

        if (! is_array($quantities)) {
            return [[], []];
        }

        $variants = $addon->variants->keyBy('id');
        $ordered = 0;

        foreach ($quantities as $key => $rawQuantity) {
            $path = "addons.{$addon->id}.{$key}";
            $quantity = self::quantity($rawQuantity);

            if ($quantity === null) {
                $errors[$path] = 'Enter a whole number of units.';

                continue;
            }

            if ($quantity === 0) {
                continue;
            }

            if ($quantity > self::MAX_LINE_QUANTITY) {
                $errors[$path] = sprintf('At most %d units per line.', self::MAX_LINE_QUANTITY);

                continue;
            }

            // Buying "the add-on itself" only makes sense when it has no options
            // to choose between.
            if ($key === 'base') {
                if ($addon->hasVariants()) {
                    $errors[$path] = sprintf('Choose an option for "%s".', $addon->name);

                    continue;
                }

                $lines[] = self::line($addon, null, $addon->unitPrice(), $quantity);
                $ordered += $quantity;

                continue;
            }

            $variant = $variants->get((int) $key);

            if ($variant === null) {
                $errors[$path] = sprintf('That option for "%s" is no longer available.', $addon->name);

                continue;
            }

            $available = $variant->stockLeft();

            if ($available !== null && $quantity > $available) {
                $errors[$path] = $available === 0
                    ? sprintf('%s is sold out.', $variant->label)
                    : sprintf('Only %d of %s left.', $available, $variant->label);

                continue;
            }

            $lines[] = self::line($addon, $variant, $variant->unitPrice(), $quantity);
            $ordered += $quantity;
        }

        // The cap counts every option together, so a limit of 3 shirts cannot be
        // dodged by taking one of each size.
        $cap = $addon->perOrderCap();

        if ($cap !== null && $ordered > $cap) {
            $errors["addons.{$addon->id}"] = sprintf(
                'At most %d of "%s" per registration. You have chosen %d.',
                $cap,
                $addon->name,
                $ordered,
            );
        }

        return [$lines, $errors];
    }

    /**
     * Compulsory add-ons have to appear on the order.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, string>
     */
    private static function checkRequired(Event $event, array $lines): array
    {
        $errors = [];
        $orderedIds = array_column($lines, 'event_addon_id');

        foreach ($event->addons as $addon) {
            if (! $addon->is_required || ! $addon->isPurchasable()) {
                continue;
            }

            if (! in_array($addon->id, $orderedIds, true)) {
                $errors["addons.{$addon->id}"] = sprintf('"%s" is required for this event.', $addon->name);
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(EventAddon $addon, ?EventAddonVariant $variant, float $unitPrice, int $quantity): array
    {
        $unitPrice = round($unitPrice, 2);

        return [
            'event_addon_id' => $addon->id,
            'event_addon_variant_id' => $variant?->id,

            // Copied in, not looked up later: an invoice must keep saying what
            // was bought at the price charged even if the catalogue changes.
            'name' => $addon->name,
            'variant_label' => $variant?->label,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => round($unitPrice * $quantity, 2),
        ];
    }

    /**
     * Strictly a non negative whole number, or null when it is not one.
     */
    private static function quantity(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }

        if (is_int($raw)) {
            return $raw >= 0 ? $raw : null;
        }

        if (! is_string($raw) || ! preg_match('/^\d+$/', trim($raw))) {
            return null;
        }

        return (int) trim($raw);
    }

    /**
     * Whether any quantity in the group was above zero.
     */
    private static function wants(mixed $quantities): bool
    {
        if (! is_array($quantities)) {
            return false;
        }

        foreach ($quantities as $quantity) {
            if ((self::quantity($quantity) ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     | Reading the result
     * ------------------------------------------------------------------ */

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function hasLines(): bool
    {
        return $this->lines !== [];
    }

    public function total(): float
    {
        return round(array_sum(array_column($this->lines, 'line_total')), 2);
    }

    /**
     * Units taken per variant, used to move stock_taken along.
     *
     * @return array<int, int>
     */
    public function variantQuantities(): array
    {
        $taken = [];

        foreach ($this->lines as $line) {
            if ($line['event_addon_variant_id'] === null) {
                continue;
            }

            $id = $line['event_addon_variant_id'];
            $taken[$id] = ($taken[$id] ?? 0) + $line['quantity'];
        }

        return $taken;
    }
}
