<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    /**
     * Services a visitor can enquire about.
     *
     * Single source of truth: the form builds its options from this list and
     * the form request validates the submitted value against its keys.
     */
    public const SERVICES = [
        'event-management' => 'Event Management',
        'online-registration' => 'Online Registration Solutions',
        'digital-creative' => 'Digital Creative Solutions',
        'merchandise' => 'Promotional Merchandise',
        'other' => 'Other',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'service',
        'message',
        'ip_address',
    ];

    /**
     * Human readable label for the selected service.
     */
    public function serviceLabel(): string
    {
        return self::SERVICES[$this->service] ?? $this->service;
    }
}
