<?php

namespace App\Http\Controllers;

use App\Support\GeneralSettings;

class HomeController extends Controller
{
    public function index()
    {
        $data = [
            'heroTitle' => GeneralSettings::siteName(),
            'heroSubtitle' => 'Your Partner in Digital Excellence',
            'companyInfo' => $this->getCompanyInfo(),
            'contactInfo' => $this->getContactInfo(),
        ];
        
        return view('pages.home', $data);
    }
    
    private function getCompanyInfo(): array
    {
        return [
            'name' => GeneralSettings::siteName(),
            'registration' => GeneralSettings::registrationNo(),
            'address' => implode(', ', GeneralSettings::addressLines()),

            // Not a General Config setting, so it stays a literal here.
            'domain' => 'https://smartcreative.my/',
        ];
    }
    
    private function getContactInfo(): array
    {
        return [
            'email' => GeneralSettings::contactEmail(),
            'phone' => GeneralSettings::contactPhone(),
        ];
    }
}
