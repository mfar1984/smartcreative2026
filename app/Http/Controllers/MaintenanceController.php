<?php

namespace App\Http\Controllers;

/**
 * Placeholder page for the one section that is not built yet.
 *
 * The three service pages moved to ServiceController, Portfolio to
 * PortfolioController and Shop to ShopController, so their placeholder methods went
 * with them. Only the Services landing page is left.
 */
class MaintenanceController extends Controller
{
    public function services()
    {
        return $this->renderMaintenancePage('Services');
    }

    private function renderMaintenancePage(string $pageName)
    {
        return view('pages.maintenance', [
            'pageName' => $pageName,
            'message' => 'This section is currently under development. Please check back soon.',
        ]);
    }
}
