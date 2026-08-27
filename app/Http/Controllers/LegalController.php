<?php

namespace App\Http\Controllers;

/**
 * The five policy pages.
 *
 * CHIP asks a merchant for a refund policy, a privacy policy and a shipping
 * policy before it will approve a live account, and each has to be a real page on
 * our own domain. The footer already advertised three of these as links, but they
 * pointed at "#", so anybody who pressed one stayed where they were.
 *
 * These are published together, so one effective date covers all five. Revise a
 * page and you must move the date with it, otherwise the page claims to be current
 * when it is not.
 */
class LegalController extends Controller
{
    /**
     * Shown on every policy page. Kept as a constant rather than today's date so
     * the pages do not silently claim to have been reviewed this morning.
     */
    private const EFFECTIVE_FROM = '26 August 2026';

    public function privacy()
    {
        return $this->render(
            'privacy-policy',
            'Privacy Policy',
            'What we collect when you register, why we need it, and what you can ask us to do with it.',
        );
    }

    public function terms()
    {
        return $this->render(
            'terms-of-service',
            'Terms of Service',
            'The terms you agree to when you register for an event or use this site.',
        );
    }

    public function cookies()
    {
        return $this->render(
            'cookie-policy',
            'Cookie Policy',
            'The few cookies this site sets, and why none of them are for advertising.',
        );
    }

    public function refund()
    {
        return $this->render(
            'refund-policy',
            'Refund Policy',
            'When a registration fee can be returned, how to ask, and how long it takes.',
        );
    }

    public function shipping()
    {
        return $this->render(
            'shipping-policy',
            'Shipping Policy',
            'How your registration reaches you, and what happens with any physical item.',
        );
    }

    private function render(string $view, string $pageTitle, string $pageSubtitle)
    {
        return view("pages.legal.{$view}", [
            'pageTitle' => $pageTitle,
            'pageSubtitle' => $pageSubtitle,
            'effectiveFrom' => self::EFFECTIVE_FROM,
        ]);
    }
}
