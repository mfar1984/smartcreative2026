<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $data = [
            'heroTitle' => 'Smart Digital Creative Management & Resources',
            'heroSubtitle' => 'Your Partner in Digital Excellence',
            'companyInfo' => $this->getCompanyInfo(),
            'contactInfo' => $this->getContactInfo(),
        ];
        
        return view('pages.home', $data);
    }
    
    private function getCompanyInfo(): array
    {
        return [
            'name' => 'Smart Digital Creative Management & Resources',
            'registration' => '202303326459 / 003562257-U',
            'address' => 'Suite: 33-01, 33rd Floor, Menara Keck Seng, 203 Jalan Bukit Bintang, 55100 Kuala Lumpur, Malaysia',
            'domain' => 'https://smartcreative.my/',
        ];
    }
    
    private function getContactInfo(): array
    {
        return [
            'email' => 'event@smartcreative.my',
            'phone' => '019-866 6898',
        ];
    }
}
