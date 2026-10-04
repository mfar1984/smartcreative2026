<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Mail\ContactEnquiryReceived;
use App\Services\Messaging\StaffAlerts;
use App\Models\ContactMessage;
use App\Support\GeneralSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ContactController extends Controller
{
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
            // Where enquiries go is the Contact Email on the General Config screen,
            // which is what that field's own help text promises.
            Mail::to(GeneralSettings::contactEmail())->send(new ContactEnquiryReceived($contactMessage));
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
     *
     * Both forms come from the General Config screen now. The conversion between
     * them lives in GeneralSettings, so editing the number on screen updates the
     * link as well as the text rather than leaving the two disagreeing.
     */
    private function getContactMethods(): array
    {
        return [
            [
                'label' => 'Call Us',
                'value' => GeneralSettings::contactPhone(),
                'url' => GeneralSettings::contactPhoneLink(),
                'external' => false,
                'note' => 'Available during business hours',
                'accent' => 'blue',
                'icon' => 'phone',
            ],
            [
                'label' => 'Email Us',
                'value' => GeneralSettings::contactEmail(),
                'url' => 'mailto:' . GeneralSettings::contactEmail(),
                'external' => false,
                'note' => 'We reply within one business day',
                'accent' => 'purple',
                'icon' => 'mail',
            ],
            [
                'label' => 'WhatsApp',
                'value' => GeneralSettings::whatsapp(),
                'url' => GeneralSettings::whatsappLink(),
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
            'name' => GeneralSettings::siteName(),
            'registration' => GeneralSettings::registrationNo(),
            'address' => GeneralSettings::addressLines(),

            /*
             | Left as a literal on purpose. There is no setting behind it, and
             | deriving a maps query from the saved address would move where the
             | button points for the address already saved.
             */
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
