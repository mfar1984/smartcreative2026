<?php

namespace App\Http\Controllers;

/**
 * The three service pages.
 *
 * These replaced MaintenanceController placeholders. Each page is laid out
 * differently on purpose: the three services are bought for different reasons and
 * a visitor comparing them should be able to tell them apart at a glance rather
 * than reading three variations of the same grid.
 *
 * Copy lives in the views rather than here. It is prose, it is edited far more
 * often than it is read by code, and keeping it in Blade means an edit does not
 * mean touching a controller.
 */
class ServiceController extends Controller
{
    public function eventManagement()
    {
        return view('pages.services.event-management', [
            'pageTitle' => 'Event Management',
            'pageSubtitle' => 'We run the whole event, from the first planning meeting to the final report on your desk.',
        ]);
    }

    public function onlineRegistration()
    {
        return view('pages.services.online-registration', [
            'pageTitle' => 'Online Registration Solutions',
            'pageSubtitle' => 'One system that takes entries, collects payment, checks people in and scores the competition.',
        ]);
    }

    public function digitalCreative()
    {
        return view('pages.services.digital-creative', [
            'pageTitle' => 'Digital Creative Solutions',
            'pageSubtitle' => 'Design and content that make an event look like it was worth turning up to.',
        ]);
    }
}
