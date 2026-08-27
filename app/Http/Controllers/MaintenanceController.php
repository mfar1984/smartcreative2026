<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Placeholder pages for sections that are not built yet.
 *
 * The three service pages moved to ServiceController and Portfolio moved to
 * PortfolioController, so their placeholder methods have gone with them. Services
 * and Shop are the only two left unbuilt.
 */
class MaintenanceController extends Controller
{
    public function services()
    {
        return $this->renderMaintenancePage('Services');
    }

    public function shop()
    {
        return $this->renderMaintenancePage('Shop');
    }

    private function renderMaintenancePage(string $pageName)
    {
        return view('pages.maintenance', [
            'pageName' => $pageName,
            'message' => 'This section is currently under development. Please check back soon.',
        ]);
    }
}
