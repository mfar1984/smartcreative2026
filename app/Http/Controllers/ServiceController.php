<?php

namespace App\Http\Controllers;

/**
 * The services landing page and the three service pages beneath it.
 *
 * Each of the three is laid out differently on purpose: the services are bought for
 * different reasons and a visitor comparing them should be able to tell them apart
 * at a glance rather than reading three variations of the same grid. The landing
 * page is a directory, so it is the one page here that does use a single repeated
 * shape.
 *
 * Copy lives in the views rather than here. It is prose, it is edited far more
 * often than it is read by code, and keeping it in Blade means an edit does not
 * mean touching a controller. The exception is the list below, which is in PHP
 * because two pages show the same words and holding them twice would let an edit to
 * one leave the other contradicting it.
 */
class ServiceController extends Controller
{
    /**
     * The three services, keyed by the slug that is both the route suffix and the
     * view name. Each page shows its own title and summary in the page header; the
     * landing page shows the same pair on the card that links to it.
     *
     * @var array<string, array{title: string, summary: string}>
     */
    private const SERVICES = [
        'event-management' => [
            'title' => 'Event Management',
            'summary' => 'We run the whole event, from the first planning meeting to the final report on your desk.',
        ],
        'online-registration' => [
            'title' => 'Online Registration Solutions',
            'summary' => 'One system that takes entries, collects payment, checks people in and scores the competition.',
        ],
        'digital-creative' => [
            'title' => 'Digital Creative Solutions',
            'summary' => 'Design and content that make an event look like it was worth turning up to.',
        ],
    ];

    public function index()
    {
        return view('pages.services.index', [
            'pageTitle' => 'Services',
            'pageSubtitle' => 'Three services, taken together or one at a time. Most clients start with registration and hand over more once they have seen a day run.',
            'services' => self::SERVICES,
        ]);
    }

    public function eventManagement()
    {
        return $this->servicePage('event-management');
    }

    public function onlineRegistration()
    {
        return $this->servicePage('online-registration');
    }

    public function digitalCreative()
    {
        return $this->servicePage('digital-creative');
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    private function servicePage(string $slug)
    {
        return view('pages.services.'.$slug, [
            'pageTitle' => self::SERVICES[$slug]['title'],
            'pageSubtitle' => self::SERVICES[$slug]['summary'],
        ]);
    }
}
