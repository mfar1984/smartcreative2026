<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\ContactEnquiryReceived;
use App\Services\Messaging\StaffAlerts;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
    /**
     * Where website enquiries are sent.
     */
    private const ENQUIRY_RECIPIENT = 'event@smartcreative.my';

    public function index()
    {
        return view('pages.contact', [
            'pageTitle' => 'Contact',
            'pageSubtitle' => 'Talk to us about your next event or digital creative project',
            'contactMethods' => $this->getContactMethods(),
            'office' => $this->getOffice(),
            'businessHours' => $this->getBusinessHours(),
            'services' => ContactMessage::SERVICES,
            'faqs' => $this->getFaqs(),
        ]);
    }

    /**
     * Store an enquiry submitted from the contact form.
     *
     * The record is persisted first so nothing is lost, then a notification
     * email is attempted. A mail failure is logged but never surfaced as an
     * error, because the enquiry itself was saved successfully.
     */
    public function store(StoreContactMessageRequest $request, StaffAlerts $alerts)
    {
        $contactMessage = ContactMessage::create([
            ...$request->validated(),
            'ip_address' => $request->ip(),
        ]);

        try {
            Mail::to(self::ENQUIRY_RECIPIENT)->send(new ContactEnquiryReceived($contactMessage));
        } catch (Throwable $exception) {
            Log::error('Contact enquiry notification could not be sent.', [
                'contact_message_id' => $contactMessage->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        // Swallows its own failures, for the same reason the email above does:
        // the enquiry is saved, and losing it over an alert would be worse.
        $alerts->enquiryReceived($contactMessage);

        return redirect()
            ->to(route('contact') . '#send-message')
            ->with('contact_status', 'Thank you for your message. We will get back to you within one business day.');
    }

    /**
     * Phone number in two forms: the display value and the E.164 value used
     * for tel: and wa.me links (Malaysia country code 60, leading 0 dropped).
     */
    private function getContactMethods(): array
    {
        return [
            [
                'label' => 'Call Us',
                'value' => '019-866 6898',
                'url' => 'tel:+60198666898',
                'external' => false,
                'note' => 'Available during business hours',
                'accent' => 'blue',
                'icon' => 'phone',
            ],
            [
                'label' => 'Email Us',
                'value' => 'event@smartcreative.my',
                'url' => 'mailto:event@smartcreative.my',
                'external' => false,
                'note' => 'We reply within one business day',
                'accent' => 'purple',
                'icon' => 'mail',
            ],
            [
                'label' => 'WhatsApp',
                'value' => '019-866 6898',
                'url' => 'https://wa.me/60198666898',
                'external' => true,
                'note' => 'Quickest way to reach us',
                'accent' => 'green',
                'icon' => 'whatsapp',
            ],
        ];
    }

    private function getOffice(): array
    {
        return [
            'heading' => 'Main Office',
            'name' => 'Smart Digital Creative Management & Resources',
            'registration' => '202303326459 / 003562257-U',
            'address' => [
                'Suite: 33-01, 33rd Floor',
                'Menara Keck Seng',
                '203 Jalan Bukit Bintang',
                '55100 Kuala Lumpur, Malaysia',
            ],
            'directions_url' => 'https://www.google.com/maps?q=Menara+Keck+Seng+Kuala+Lumpur',
        ];
    }

    private function getBusinessHours(): array
    {
        return [
            ['days' => 'Monday - Friday', 'hours' => '9:00 AM - 6:00 PM', 'closed' => false],
            ['days' => 'Saturday', 'hours' => '10:00 AM - 2:00 PM', 'closed' => false],
            ['days' => 'Sunday', 'hours' => 'Closed', 'closed' => true],
            ['days' => 'Public Holidays', 'hours' => 'Closed', 'closed' => true],
        ];
    }

    private function getFaqs(): array
    {
        return [
            [
                'question' => 'What services do you provide?',
                'answer' => 'We specialize in event management, online registration platforms, digital creative solutions, and promotional merchandise. Each service can be customized to meet your specific needs.',
            ],
            [
                'question' => 'How far in advance should I book your services?',
                'answer' => 'For events, we recommend booking at least 3-6 months in advance for large-scale events, and 1-2 months for smaller events. Digital creative and registration services can typically be started within 1-2 weeks.',
            ],
            [
                'question' => 'Do you offer packages or custom pricing?',
                'answer' => 'Yes, we offer both pre-designed packages and fully customized solutions. Contact us for a detailed quote based on your specific requirements and budget.',
            ],
            [
                'question' => 'What is your coverage area?',
                'answer' => 'We primarily serve clients across Malaysia, with our main office in Kuala Lumpur. For large-scale events, we can coordinate services nationwide.',
            ],
            [
                'question' => 'Can you handle last-minute requests?',
                'answer' => 'While we prefer advance bookings for optimal planning, we do accommodate urgent requests when possible. Contact us immediately and we will do our best to assist you.',
            ],
        ];
    }
}
