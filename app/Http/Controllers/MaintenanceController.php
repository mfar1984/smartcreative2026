<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function services()
    {
        return $this->renderMaintenancePage('Services');
    }
    
    public function eventManagement()
    {
        return $this->renderMaintenancePage('Event Management');
    }
    
    public function onlineRegistration()
    {
        return $this->renderMaintenancePage('Online Registration Solutions');
    }
    
    public function digitalCreative()
    {
        return $this->renderMaintenancePage('Digital Creative Solutions');
    }
    
    public function portfolio()
    {
        return $this->renderMaintenancePage('Portfolio');
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
