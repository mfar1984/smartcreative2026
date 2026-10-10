<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /services landing page.
 *
 * It replaced a placeholder that said "under development", so the assertion worth
 * having is that the wording is absent: a test that only checks for a 200 would
 * have passed against the placeholder too.
 *
 * The rest guard the things that would quietly break if this page were edited in
 * isolation. The route name and the URL are relied on by the header, the footer and
 * the portfolio page, and the three links out are the page's only reason to exist,
 * so each is followed to make sure it actually answers.
 */
class ServicesLandingPageTest extends TestCase
{
    // Every page here reads settings, so the tables have to be there.
    use RefreshDatabase;

    /** The three service pages, by route name. */
    private const SERVICE_ROUTES = [
        'services.event-management',
        'services.online-registration',
        'services.digital-creative',
    ];

    /* ---------------------------------------------------------------------
     | The page itself
     * ------------------------------------------------------------------ */

    public function test_the_landing_page_answers_and_is_not_the_placeholder(): void
    {
        $response = $this->get(route('services'));

        $response->assertOk();

        // The placeholder's exact copy. Absence is what proves it is gone.
        $response->assertDontSee('Under Development');
        $response->assertDontSee('This section is currently under development');
        $response->assertDontSee('We\'re working hard to bring you this section', false);
    }

    public function test_the_route_name_and_the_url_have_not_changed(): void
    {
        // The header, the footer and the portfolio page all link by name, and the
        // address is the one that has been published.
        $this->assertSame(url('/services'), route('services'));

        $this->get('/services')->assertOk();
    }

    public function test_it_is_served_by_the_service_controller(): void
    {
        $route = app('router')->getRoutes()->getByName('services');

        $this->assertNotNull($route);
        $this->assertSame(
            \App\Http\Controllers\ServiceController::class.'@index',
            $route->getActionName(),
        );
    }

    /* ---------------------------------------------------------------------
     | Where it sends people
     * ------------------------------------------------------------------ */

    public function test_it_links_to_all_three_service_pages(): void
    {
        $response = $this->get(route('services'));

        foreach (self::SERVICE_ROUTES as $name) {
            $response->assertSee('href="'.route($name).'"', false);
        }
    }

    public function test_every_service_page_it_links_to_answers(): void
    {
        foreach (self::SERVICE_ROUTES as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_each_service_is_named_in_the_words_its_own_page_uses(): void
    {
        /*
         | The titles come from ServiceController, which is also where each service
         | page reads its own header from. Asserting the landing page shows the same
         | strings is what stops the two drifting apart.
         */
        $response = $this->get(route('services'));

        $response->assertSee('Event Management');
        $response->assertSee('Online Registration Solutions');
        $response->assertSee('Digital Creative Solutions');
    }

    public function test_the_three_service_pages_still_carry_their_own_heading_and_summary(): void
    {
        /*
         | The titles and summaries moved into a constant on the controller so this
         | page and the service pages read one copy of them. These are the exact
         | strings the three pages carried before that move, so a change to the
         | constant that altered a page's own header would fail here.
         */
        $expected = [
            'services.event-management' => [
                'Event Management',
                'We run the whole event, from the first planning meeting to the final report on your desk.',
            ],
            'services.online-registration' => [
                'Online Registration Solutions',
                'One system that takes entries, collects payment, checks people in and scores the competition.',
            ],
            'services.digital-creative' => [
                'Digital Creative Solutions',
                'Design and content that make an event look like it was worth turning up to.',
            ],
        ];

        foreach ($expected as $name => [$title, $summary]) {
            $this->get(route($name))
                ->assertOk()
                ->assertSee($title)
                ->assertSee($summary);
        }
    }

    public function test_promotional_merchandise_points_at_the_shop(): void
    {
        /*
         | Promotional Merchandise is in the contact form's subject list and in the
         | Contact FAQ, so leaving it off this page would contradict both. It has no
         | service page because it is a catalogue, so it points at the Shop and at an
         | enquiry instead.
         */
        $response = $this->get(route('services'));

        $response->assertSee('Promotional Merchandise');
        $response->assertSee('href="'.route('shop').'"', false);
        $response->assertSee('href="'.route('contact').'"', false);

        // And the Shop answers, switched on or not: closed, it explains itself.
        $this->get(route('shop'))->assertOk();
    }

    /* ---------------------------------------------------------------------
     | The navigation that depends on it
     * ------------------------------------------------------------------ */

    public function test_the_header_navigation_still_points_at_the_landing_page(): void
    {
        // Rendered on another page, so this is the real header markup rather than
        // something this test built.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('services').'"', false);
    }

    /* ---------------------------------------------------------------------
     | The placeholder machinery is gone
     * ------------------------------------------------------------------ */

    public function test_the_maintenance_controller_no_longer_exists(): void
    {
        $this->assertFalse(
            class_exists(\App\Http\Controllers\MaintenanceController::class),
            'MaintenanceController is back. It existed only for the /services placeholder.',
        );
    }

    public function test_the_placeholder_view_is_gone_and_the_holding_page_is_not(): void
    {
        // One word apart, and only one of them should have been deleted: the second
        // is the live maintenance holding page.
        $this->assertFalse(view()->exists('pages.maintenance'));
        $this->assertTrue(view()->exists('pages.site-maintenance'));
    }
}
