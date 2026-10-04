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
     * The add-on's own price folded into every unit one person takes.
     *
     * Zero in the ordinary case, where that price is charged once for the whole
     * registration by the group line below. When the event charges per participant it
     * moves onto each person's own line instead, so three people with a RM40 shirt each
     * owe RM40.
     *
     * Only with variants. Without them the quantity path already charges the add-on
     * price per unit, so there is nothing to move.
     *
     * Public, and the only definition of the rule. The admin recalculation re-prices
     * entries that were stored before the event charged per participant, and it has to
     * reach the same figure this builder would: two copies of this one line is how the
     * screen and the invoice start disagreeing about money again.
     */
    public static function perParticipantUnitBase(Event $event, EventAddon $addon): float
    {
        return $addon->hasVariants() && $event->chargesAddonsPerParticipant()
            ? $addon->unitPrice()
            : 0.0;
    }

    /**
     * What one unit of a per-person add-on costs on somebody's own line.
     *
     * The whole of the per-head rule in one expression, so there is exactly one
     * place that answers "what does this person owe for this item".
     *
     * With sizes, it is the add-on's own price folded in per head — zero unless the
     * event charges per participant, see perParticipantUnitBase() — plus whatever
     * that size adds. Without sizes there is nothing to fold: the quantity path has
     * always charged the add-on price per unit, so that is the figure.
     *
     * A null variant is a line with no size recorded against it, and it costs the
     * same as one with an ordinary size. The money is owed for the item, not for the
     * choice; a required shirt nobody picked a size for is still a shirt owed.
     *
     * Public because the admin recalculation has to reach the same figure this
     * builder would. Two copies of this rule is how the screen and the invoice start
     * disagreeing about money again.
     */
    public static function perParticipantUnitPrice(Event $event, EventAddon $addon, ?EventAddonVariant $variant = null): float
    {
        if (! $addon->hasVariants()) {
            return round($addon->unitPrice(), 2);
        }

        return round(
            self::perParticipantUnitBase($event, $addon) + ($variant?->unitPrice() ?? 0.0),
            2,
        );
    }

    /**
     * Whether a per-person add-on also carries one charge for the registration.
     *
     * With variants, the add-on's own price remains one charge for the entry and the
     * sizes only record choices. Unless the event charges per participant, in which
     * case that price is already on each person's own line and adding it here as well
     * would charge for one shirt nobody takes delivery of.
     */
    public static function chargesGroupLine(Event $event, EventAddon $addon): bool
    {
        return $addon->hasVariants()
            && $addon->unitPrice() > 0
            && ! $event->chargesAddonsPerParticipant();
    }

    /**
     * @param  mixed  $input  the raw addons input: [addonId => [variantId|'base' => qty]]
     * @param  array<int, array<string, mixed>>  $participants  the submitted people,
     *         whose own [addons][addonId] => variantId choices feed the per person
     *         add-ons. Passed separately because those live inside each person's
     *         block on the form, not in the shared addons map.
     */
    public static function build(Event $event, mixed $input, array $participants = []): self
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

            /*
             | A per person add-on is not ordered here. Its choices arrive inside
             | each person's block and are read below, so a quantity posted against
             | it is either a stale form or a tampered one; either way ignoring it
             | is right, because honouring it would double the order.
             */
            if ($addon->isAssignedPerParticipant($event)) {
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

            [$addonLines, $addonErrors] = $addon->isRadioSelection()
                ? self::buildRadioAddon($addon, $quantities)
                : self::buildAddon($addon, $quantities);

            $lines = array_merge($lines, $addonLines);
            $errors = array_merge($errors, $addonErrors);
        }

        [$perPersonLines, $perPersonErrors] = self::buildPerParticipant($event, $participants);

        $lines = array_merge($lines, $perPersonLines);
        $errors = array_merge($errors, $perPersonErrors);

        $errors = array_merge($errors, self::checkRequired($event, $lines));

        return new self($lines, $errors);
    }

    /**
     * Lines for the add-ons chosen one person at a time.
     *
     * Carries participant_index rather than an id, because this runs during
     * validation before anybody has been written. The controller swaps the index
     * for the real id once the people exist, and strips the key before insert.
     *
     * @param  array<int, array<string, mixed>>  $participants
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private static function buildPerParticipant(Event $event, array $participants): array
    {
        $perPerson = $event->addons->filter(
            fn (EventAddon $addon) => $addon->isAssignedPerParticipant($event)
        );

        if ($perPerson->isEmpty()) {
            return [[], []];
        }

        $lines = [];
        $errors = [];

        foreach ($perPerson as $addon) {
            if (! $addon->is_active) {
                continue;
            }

            $variants = $addon->variants->keyBy('id');
            $taken = 0;

            // The add-on's own price, folded into every unit somebody takes. The rule
            // itself is set out on perParticipantUnitBase().
            $perUnitBase = self::perParticipantUnitBase($event, $addon);

            foreach (array_values($participants) as $index => $person) {
                $path = "participants.{$index}.addons.{$addon->id}";
                $selection = $person['addons'][$addon->id] ?? null;
                $personTaken = 0;

                if ($addon->isRadioSelection()) {
                    // Radio sends one variant id. Accept the keyed shape too so a
                    // stale form cannot turn a valid single choice into a quantity.
                    $choice = is_array($selection) ? ($selection['choice'] ?? null) : $selection;

                    if (blank($choice)) {
                        if ($addon->is_required) {
                            $errors[$path] = sprintf('Choose an option for "%s".', $addon->name);
                        }

                        continue;
                    }

                    $variant = $variants->get((int) $choice);

                    if ($variant === null) {
                        $errors[$path] = sprintf('That option for "%s" is no longer available.', $addon->name);

                        continue;
                    }

                    $available = $variant->stockLeft();
                    $wantedSoFar = 1 + self::countVariant($lines, $variant->id);

                    if ($available !== null && $wantedSoFar > $available) {
                        $errors[$path] = $available === 0
                            ? sprintf('%s is sold out.', $variant->label)
                            : sprintf('Only %d of %s left, and more than that were chosen.', $available, $variant->label);

                        continue;
                    }

                    $line = self::line($addon, $variant, $perUnitBase + $variant->unitPrice(), 1);
                    $line['participant_index'] = $index;
                    $lines[] = $line;
                    $taken++;

                    continue;
                }

                // Quantity mode keeps the existing [variantId => quantity] shape,
                // but it lives inside this person's block for grouping entries.
                if (! is_array($selection)) {
                    if ($addon->is_required) {
                        $errors[$path] = sprintf('Enter a quantity for "%s".', $addon->name);
                    }

                    continue;
                }

                foreach ($selection as $key => $rawQuantity) {
                    $quantityPath = "{$path}.{$key}";
                    $quantity = self::quantity($rawQuantity);

                    if ($quantity === null) {
                        $errors[$quantityPath] = 'Enter a whole number of units.';

                        continue;
                    }

                    if ($quantity === 0) {
                        continue;
                    }

                    if ($quantity > self::MAX_LINE_QUANTITY) {
                        $errors[$quantityPath] = sprintf('At most %d units per line.', self::MAX_LINE_QUANTITY);

                        continue;
                    }

                    if ($key === 'base') {
                        if ($addon->hasVariants()) {
                            $errors[$quantityPath] = sprintf('Choose an option for "%s".', $addon->name);

                            continue;
                        }

                        $line = self::line($addon, null, $addon->unitPrice(), $quantity);
                        $line['participant_index'] = $index;
                        $lines[] = $line;
                        $personTaken += $quantity;
                        $taken += $quantity;

                        continue;
                    }

                    $variant = $variants->get((int) $key);

                    if ($variant === null) {
                        $errors[$quantityPath] = sprintf('That option for "%s" is no longer available.', $addon->name);

                        continue;
                    }

                    $available = $variant->stockLeft();
                    $wantedSoFar = $quantity + self::countVariant($lines, $variant->id);

                    if ($available !== null && $wantedSoFar > $available) {
                        $errors[$quantityPath] = $available === 0
                            ? sprintf('%s is sold out.', $variant->label)
                            : sprintf('Only %d of %s left, and more than that were chosen.', $available, $variant->label);

                        continue;
                    }

                    $line = self::line($addon, $variant, $perUnitBase + $variant->unitPrice(), $quantity);
                    $line['participant_index'] = $index;
                    $lines[] = $line;
                    $personTaken += $quantity;
                    $taken += $quantity;
                }

                if ($addon->is_required && $personTaken === 0) {
                    $errors[$path] = sprintf('Enter a quantity for "%s".', $addon->name);
                }
            }

            // One charge for the registration alongside the per-person lines, when the
            // event still prices the add-on that way. See chargesGroupLine().
            if ($taken > 0 && self::chargesGroupLine($event, $addon)) {
                $lines[] = self::line($addon, null, $addon->unitPrice(), 1);
            }

            $cap = $addon->perOrderCap();

            if ($cap !== null && $taken > $cap) {
                $errors["addons.{$addon->id}"] = sprintf(
                    'At most %d of "%s" per registration. %d were chosen.',
                    $cap,
                    $addon->name,
                    $taken,
                );
            }
        }

        return [$lines, $errors];
    }

    /**
     * How many of one variant the lines already hold.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private static function countVariant(array $lines, int $variantId): int
    {
        $total = 0;

        foreach ($lines as $line) {
            if (($line['event_addon_variant_id'] ?? null) === $variantId) {
                $total += (int) $line['quantity'];
            }
        }

        return $total;
    }

    /**
     * One registration-level radio choice. Quantity is always one.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, string>}
     */
    private static function buildRadioAddon(EventAddon $addon, mixed $selection): array
    {
        if (! is_array($selection)) {
            return [[], []];
        }

        $rawChoice = $selection['choice'] ?? null;

        if (blank($rawChoice)) {
            return [[], []];
        }

        $path = "addons.{$addon->id}.choice";
        $choice = self::quantity($rawChoice);

        if ($choice === null || $choice <= 0) {
            return [[], [$path => sprintf('Choose an option for "%s".', $addon->name)]];
        }

        $variant = $addon->variants->firstWhere('id', $choice);

        if ($variant === null) {
            return [[], [$path => sprintf('That option for "%s" is no longer available.', $addon->name)]];
        }

        $available = $variant->stockLeft();

        if ($available !== null && $available < 1) {
            return [[], [$path => sprintf('%s is sold out.', $variant->label)]];
        }

        $lines = [self::line($addon, $variant, $variant->unitPrice(), 1)];

        if ($addon->unitPrice() > 0) {
            array_unshift($lines, self::line($addon, null, $addon->unitPrice(), 1));
        }

        return [$lines, []];
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

            /*
             | A line is written for every size chosen even when it adds nothing,
             | because the shirts still have to be printed and somebody has to know
             | which sizes were asked for. The figure on it is the surcharge for that
             | size, so an ordinary size is a RM0.00 line that records a choice.
             */
            $lines[] = self::line($addon, $variant, $variant->unitPrice(), $quantity);
            $ordered += $quantity;
        }

        /*
         | The add-on's own price is charged once, not per unit.
         |
         | "Event Tee RM50" is the price of being given shirts at all; the sizes are
         | how many and which, and they only carry money when a size costs more. So
         | five shirts on a RM50 add-on is RM50, not RM250, and that is what makes
         | the figure on the form match what the organiser meant by it.
         |
         | Added after the loop so it only appears when something was actually
         | ordered, and placed at the front so the invoice reads as the thing first
         | and its sizes underneath.
         */
        if ($lines !== [] && $addon->unitPrice() > 0) {
            array_unshift($lines, self::line($addon, null, $addon->unitPrice(), 1));
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

            /*
             | Per person add-ons are already checked one person at a time in
             | buildPerParticipant(), which can say who has not chosen. Repeating it
             | here would add a second message about the order as a whole that the
             | visitor cannot act on.
             */
            if ($addon->isAssignedPerParticipant($event)) {
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
