<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The admin picks a design by looking at it, at six designs or at six hundred.
 *
 * WHAT THESE TESTS ARE GUARDING
 *
 * The form used to draw every design inline, all of them, full width. The owner is
 * adding designs over time, so that section grew without limit — and the fix is not a
 * scroll box: the form is used on phones, where a fixed-height scroll area steals the
 * swipe, and at two hundred designs scrolling is not finding anyway.
 *
 * So the form draws the CURRENT CHOICE and one group, and the rest arrive from
 * CouponDesignPickerController when asked for. The assertion that actually proves
 * that is the absence of a design from another group in the page, which is why
 * test_only_the_active_groups_previews_are_in_the_page exists. Hiding them with CSS
 * would pass every other test in here and still ship the heavy page.
 *
 * The posted field is unchanged — still `design`, still the same values — because the
 * stored choice is what a coupon created today will be drawn with years later.
 */
class CouponDesignPickerTest extends CouponTestCase
{
    use RefreshDatabase;

    /** Every design that is offered among the groups, for a data provider. */
    public static function groupedDesigns(): array
    {
        return array_map(
            fn (string $key) => [$key],
            array_keys(Coupon::groupedDesigns()),
        );
    }

    public static function designGroups(): array
    {
        return array_map(
            fn (string $slug) => [$slug],
            array_keys(Coupon::DESIGN_GROUPS),
        );
    }

    /* ---------------------------------------------------------------------
     | The shape of the catalogue
     * ------------------------------------------------------------------ */

    /**
     * A design without a group would be unreachable: the picker only offers what a
     * group holds, so this fails the moment somebody adds a design and forgets.
     */
    public function test_every_design_has_a_group_except_custom(): void
    {
        foreach (Coupon::DESIGNS as $key => $design) {
            $this->assertArrayHasKey('label', $design, $key.' has no label.');
            $this->assertArrayHasKey('group', $design, $key.' has no group key.');
            $this->assertNotSame('', trim((string) $design['label']), $key.' has an empty label.');

            if ($key === Coupon::DESIGN_CUSTOM) {
                // Not a design. Bring your own artwork, so there is no shape to group
                // it under and it is offered on its own.
                $this->assertNull($design['group'], 'Custom must not sit in a group.');

                continue;
            }

            $this->assertNotNull($design['group'], $key.' has no group, so the picker cannot offer it.');
            $this->assertArrayHasKey(
                $design['group'],
                Coupon::DESIGN_GROUPS,
                $key.' names a group that does not exist: '.$design['group'],
            );
        }
    }

    /** An empty tab is a dead end for whoever clicks it. */
    public function test_every_group_holds_at_least_one_design(): void
    {
        foreach (array_keys(Coupon::DESIGN_GROUPS) as $slug) {
            $this->assertNotEmpty(
                Coupon::designsInGroup($slug),
                'Group '.$slug.' has no designs in it.',
            );
        }
    }

    public function test_custom_is_not_among_the_grouped_designs(): void
    {
        $this->assertArrayNotHasKey(Coupon::DESIGN_CUSTOM, Coupon::groupedDesigns());
        $this->assertSame('Custom — upload your own artwork', Coupon::designLabelFor(Coupon::DESIGN_CUSTOM));
    }

    /* ---------------------------------------------------------------------
     | What the form itself renders
     * ------------------------------------------------------------------ */

    public function test_the_form_draws_the_current_choice_inline(): void
    {
        $coupon = $this->fixedCoupon(45, ['quantity' => 3, 'design' => 'stamp']);

        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.edit', $coupon));

        $response->assertOk();

        // Named in words, not left to a border colour.
        $response->assertSee('data-design-current-label', false);
        $response->assertSee('Stamp — rubber stamp on paper', false);

        // And drawn, with the coupon's own figures rather than a made-up batch.
        $response->assertSee('data-design-current', false);
        $response->assertSee('RM 45.00');
        $response->assertSee($coupon->name);

        // The way to the rest of them.
        $response->assertSee('data-design-open', false);
        $response->assertSee('Change design', false);
    }

    /**
     * THE ASSERTION THAT PROVES THE PAGE IS NOT DRAWING EVERYTHING.
     *
     * The picker opens on the group the current choice belongs to. A design from any
     * other group must be absent from the HTML — not present and hidden, absent — or
     * the form pays for previews nobody asked to see.
     */
    public function test_only_the_active_groups_previews_are_in_the_page(): void
    {
        // classic is the only design in the Ticket group, so the page should carry
        // that one card and no other.
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();
        $response->assertSee('data-design-card="classic"', false);

        foreach (array_keys(Coupon::groupedDesigns()) as $design) {
            if (Coupon::designGroup($design) === Coupon::designGroupFor('classic')) {
                continue;
            }

            $response->assertDontSee('data-design-card="'.$design.'"', false);
            $response->assertDontSee('name="design" value="'.$design.'"', false);
        }
    }

    public function test_the_page_opens_on_the_group_the_chosen_design_belongs_to(): void
    {
        $coupon = $this->coupon(['design' => 'gradient']);

        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.edit', $coupon));

        $response->assertOk();

        // gradient is the Modern group, so its card is in the page and classic's is not.
        $response->assertSee('data-design-card="gradient"', false);
        $response->assertDontSee('data-design-card="classic"', false);
    }

    public function test_the_panel_lists_every_group_and_a_search_box(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        foreach (Coupon::DESIGN_GROUPS as $slug => $label) {
            $response->assertSee('data-design-tab="'.$slug.'"', false);
            $response->assertSee($label, false);
        }

        $response->assertSee('data-design-search', false);
        $response->assertSee('Search designs', false);
    }

    /**
     * Search matches a label or a group name, and it has to find a design in a group
     * nobody has opened. That means the page carries the name and group of every
     * design — text only, which is cheap — so this asserts they are all there.
     */
    public function test_the_page_carries_every_design_name_and_group_for_the_search(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        foreach (Coupon::groupedDesigns() as $key => $label) {
            $response->assertSee('"key":"'.$key.'"', false);
            $response->assertSee('"group":"'.Coupon::designGroup($key).'"', false);
            $response->assertSee('"groupLabel":"'.Coupon::designGroupLabel(Coupon::designGroup($key)).'"', false);
        }
    }

    public function test_custom_is_offered_separately_with_its_upload(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        // Its own option, outside the group tabs: it carries no data-design-group,
        // because it is in no group.
        $response->assertSee('data-design-custom', false);
        $response->assertSee('name="design" value="custom"', false);
        $response->assertDontSee('data-design-card="custom"', false);

        // And the upload field it exists for, on the form as it always was.
        $response->assertSee('name="design_image"', false);
        $response->assertSee('data-custom-row', false);
    }

    /**
     * The design choice stays a real control.
     *
     * Not a div with a click handler: a radio group inside a labelled fieldset, so the
     * keyboard arrows through it, the chosen one is announced as selected, and the
     * posted field is the one CouponRequest validates.
     */
    public function test_the_choice_is_a_real_radio_group_in_a_labelled_fieldset(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertSee('data-design-picker', false);
        $response->assertSee('Coupon design');
        $response->assertSee('type="radio" id="design-classic" name="design" value="classic"', false);

        // Said in words as well as in colour, and spoken when it changes.
        $response->assertSee('Chosen', false);
        $response->assertSee('aria-live="polite" data-design-announce', false);

        // A dialog that can be escaped and whose opener owns it.
        $response->assertSee('aria-modal="true"', false);
        $response->assertSee('aria-haspopup="dialog"', false);
        $response->assertSee('aria-controls="coupon-design-panel"', false);
    }

    /**
     * The picker's behaviour actually reaches the page.
     *
     * There is no browser in this suite, so the keyboard path and the typing in the
     * search box cannot be driven here. What can be proved is that the script that
     * carries them is pushed onto the page at all, and that it has been told where to
     * fetch the groups it does not have — without which the picker would open on one
     * group and go no further, with every other test in here still passing.
     */
    public function test_the_page_ships_the_picker_behaviour_and_the_address_it_fetches_from(): void
    {
        $response = $this->actingAs($this->couponAdmin())->get(route('admin.coupons.create'));

        $response->assertOk();
        $response->assertSee(json_encode(route('admin.coupons.designs')), false);
        $response->assertSee("event.key === 'Escape'", false);
        $response->assertSee('opener?.focus()', false);
    }

    /** The edit form fetches against its own coupon, so the figures stay its own. */
    public function test_the_edit_form_fetches_against_its_own_coupon(): void
    {
        $coupon = $this->coupon(['design' => 'minimal']);

        $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.edit', $coupon))
            ->assertOk()
            ->assertSee(json_encode(route('admin.coupons.designs', $coupon)), false);
    }

    /* ---------------------------------------------------------------------
     | The rest of the catalogue, fetched a group at a time
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('designGroups')]
    public function test_a_group_hands_back_a_card_for_every_design_in_it(string $slug): void
    {
        $response = $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.designs', ['group' => $slug]));

        $response->assertOk();

        foreach (Coupon::designsInGroup($slug) as $key => $label) {
            $response->assertSee('data-design-card="'.$key.'"', false);
            $response->assertSee('data-design-group="'.$slug.'"', false);
            $response->assertSee('name="design" value="'.$key.'"', false);
            $response->assertSee($label, false);
        }

        // Nothing from another group comes with it: one request, one group.
        foreach (array_keys(Coupon::groupedDesigns()) as $key) {
            if (Coupon::designGroup($key) === $slug) {
                continue;
            }

            $response->assertDontSee('data-design-card="'.$key.'"', false);
        }
    }

    /** Between the four of them, the groups offer every non-custom design and no other. */
    public function test_the_groups_together_offer_every_design_but_custom(): void
    {
        $offered = [];

        foreach (array_keys(Coupon::DESIGN_GROUPS) as $slug) {
            $offered = [...$offered, ...array_keys(Coupon::designsInGroup($slug))];
        }

        sort($offered);
        $expected = array_keys(Coupon::groupedDesigns());
        sort($expected);

        $this->assertSame($expected, $offered);
        $this->assertNotContains(Coupon::DESIGN_CUSTOM, $offered);
    }

    public function test_a_group_that_does_not_exist_is_a_404(): void
    {
        $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.designs', ['group' => 'nope']))
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('groupedDesigns')]
    public function test_one_design_comes_back_drawn_full_size_for_the_inline_block(string $design): void
    {
        $response = $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.designs', ['design' => $design]));

        $response->assertOk();

        // The full-size rendering, so no compact card markup and a real figure.
        $response->assertSee('<figure', false);
        $response->assertDontSee('data-design-card', false);
    }

    public function test_the_inline_block_of_an_existing_coupon_uses_its_own_figures(): void
    {
        $coupon = $this->fixedCoupon(45, ['design' => 'bold']);

        $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.designs', ['coupon' => $coupon->id, 'design' => 'minimal']))
            ->assertOk()
            ->assertSee('RM 45.00')
            ->assertSee($coupon->name);
    }

    public function test_a_design_that_does_not_exist_is_a_404_on_the_endpoint(): void
    {
        $this->actingAs($this->couponAdmin())
            ->get(route('admin.coupons.designs', ['design' => 'does-not-exist']))
            ->assertNotFound();
    }

    public function test_the_endpoint_is_closed_to_somebody_who_cannot_touch_coupons(): void
    {
        $this->actingAs($this->userWith(['coupons.view']))
            ->get(route('admin.coupons.designs', ['group' => 'ticket']))
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------------
     | What gets saved
     * ------------------------------------------------------------------ */

    #[\PHPUnit\Framework\Attributes\DataProvider('groupedDesigns')]
    public function test_choosing_a_design_saves_it(string $design): void
    {
        $name = 'DES'.strtoupper(substr(md5($design), 0, 3));

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => $name,
                'quantity' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 30,
                'design' => $design,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($design, Coupon::query()->where('name', $name)->sole()->design);
    }

    public function test_the_custom_upload_path_still_works(): void
    {
        Storage::fake('public');

        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => 'OWNART',
                'quantity' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 30,
                'design' => Coupon::DESIGN_CUSTOM,
                'design_image' => UploadedFile::fake()->image('ticket.png', 600, 300),
            ])
            ->assertSessionHasNoErrors();

        $coupon = Coupon::query()->where('name', 'OWNART')->sole();

        $this->assertSame(Coupon::DESIGN_CUSTOM, $coupon->design);
        $this->assertTrue($coupon->hasCustomDesign());
        $this->assertStringStartsWith(Coupon::DESIGN_DIRECTORY.'/', $coupon->design_path);
        Storage::disk('public')->assertExists($coupon->design_path);
    }

    public function test_a_design_that_does_not_exist_is_refused(): void
    {
        $this->actingAs($this->couponAdmin())
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => 'NOPE01',
                'quantity' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 30,
                'design' => 'does-not-exist',
            ])
            ->assertSessionHasErrors('design');
    }

    /**
     * A complaint about the design still lands on the design field.
     *
     * The control is behind a button now, so the message has to be beside the button
     * rather than inside the panel nobody has opened — and the panel reopens itself,
     * so the thing that can answer the complaint is in front of the operator.
     */
    public function test_a_validation_error_renders_against_the_design_field(): void
    {
        $response = $this->actingAs($this->couponAdmin())
            ->from(route('admin.coupons.create'))
            ->followingRedirects()
            ->post(route('admin.coupons.store'), [
                'kind' => Coupon::KIND_EVENT,
                'name' => 'BADDES',
                'quantity' => 5,
                'expires_at' => now()->addMonth()->toDateString(),
                'discount_type' => Coupon::DISCOUNT_PERCENTAGE,
                'discount_value' => 30,
                'design' => 'does-not-exist',
            ]);

        $response->assertOk();

        // The message is rendered by x-admin.field-row's error slot, which is beside
        // the "Change design" button rather than inside the panel.
        $response->assertSee('The selected design is invalid.', false);
        $response->assertSee('Design Coupon', false);
        $response->assertSee('data-design-open', false);
    }
}
