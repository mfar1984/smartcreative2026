<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Mail\TestEmail;
use App\Models\Setting;
use App\Services\AdminLogger;
use App\Services\Messaging\InfobipGateway;
use App\Services\Messaging\MessagingException;
use App\Services\Messaging\TelegramNotifier;
use App\Support\ChipBalance;
use App\Support\MailSettings;
use App\Support\PhoneNumber;
use App\Support\PaymentSettings;
use App\Support\ShippingSettings;
use App\Support\SmsSettings;
use App\Support\TelegramSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class IntegrationController extends Controller
{
    /**
     * CHIP webhook events this site acts on.
     *
     * Listed on the settings screen so the operator knows which boxes to tick
     * in the CHIP portal. Subscribing to more is harmless; they are
     * acknowledged and ignored.
     *
     * @var array<int, string>
     */
    private const CHIP_HANDLED_EVENTS = [
        'purchase.created',
        'purchase.paid',
        'purchase.payment_failure',
        'purchase.cancelled',
        'purchase.captured',
        'purchase.settled',
        'purchase.refunded',
        'payment.refunded',
    ];

    /**
     * Definition for every integration tab.
     *
     * `fields` stays flat because validation and saving walk it directly.
     * `panels` only describes how those fields are grouped on screen, so the
     * layout can change without touching the save logic.
     *
     * Fields flagged `secret` are stored encrypted and never sent back to the
     * browser.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SCHEMA = [
        'email' => [
            'label' => 'Email',
            'icon' => 'mail',
            'intro' => [
                'title' => 'Email Delivery',
                'description' => 'SMTP profile used for system email — contact enquiries and admin notifications.',
                'icon' => 'mail',
                'accent' => 'blue',
            ],
            'panels' => [
                'Profile & Sender' => ['icon' => 'identification', 'fields' => ['from_address', 'from_name']],
                'SMTP Server' => ['icon' => 'globe', 'fields' => ['mailer', 'host', 'port', 'encryption']],
                'Authentication' => ['icon' => 'lock', 'fields' => ['username', 'password']],
            ],
            'fields' => [
                'from_address' => ['label' => 'From Address', 'type' => 'email', 'rules' => ['nullable', 'email:rfc', 'max:190'], 'help' => 'Address system email is sent from.'],
                'from_name' => ['label' => 'From Name', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:150'], 'help' => 'Display name recipients see.'],
                'mailer' => ['label' => 'Mailer', 'type' => 'select', 'options' => ['smtp' => 'SMTP', 'log' => 'Log file (testing)', 'sendmail' => 'Sendmail'], 'rules' => ['required', 'in:smtp,log,sendmail'], 'help' => 'Transport used to deliver mail.'],
                'host' => ['label' => 'Host', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190'], 'placeholder' => 'mail.smartcreative.my', 'help' => 'SMTP server hostname.'],
                'port' => ['label' => 'Port', 'type' => 'number', 'rules' => ['nullable', 'integer', 'min:1', 'max:65535'], 'placeholder' => '465', 'help' => '465 for SSL, 587 for TLS.'],
                'encryption' => ['label' => 'Encryption', 'type' => 'select', 'options' => ['smtps' => 'SSL (implicit, port 465)', 'tls' => 'TLS (STARTTLS, port 587)', 'none' => 'None'], 'rules' => ['required', 'in:smtps,tls,none']],
                'username' => ['label' => 'Username', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190'], 'help' => 'Account used to log in to the SMTP server.'],
                'password' => ['label' => 'Password', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190']],
            ],
        ],

        'api' => [
            'label' => 'API & Webhook',
            'icon' => 'plug',
            'intro' => [
                'title' => 'API & Webhook',
                'description' => 'Access rules for the public API, and the outbound webhook this site posts events to.',
                'icon' => 'plug',
                'accent' => 'purple',
            ],
            'panels' => [
                'Access' => ['icon' => 'shield', 'fields' => ['api_enabled', 'api_key']],
                'Webhook' => ['icon' => 'globe', 'fields' => ['webhook_url', 'webhook_secret', 'webhook_events']],
            ],
            'fields' => [
                'api_enabled' => ['label' => 'Enable public API', 'type' => 'toggle', 'rules' => ['nullable', 'boolean'], 'help' => 'Serve the public API endpoints.'],
                'api_key' => ['label' => 'API Key', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190'], 'help' => 'Shared secret required in the X-API-Key header.'],
                'webhook_url' => ['label' => 'Webhook URL', 'type' => 'url', 'rules' => ['nullable', 'url', 'max:500'], 'placeholder' => 'https://example.com/hooks/smartcreative', 'help' => 'Where events are posted.'],
                'webhook_secret' => ['label' => 'Signing Secret', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190'], 'help' => 'Signs outgoing payloads so the receiver can verify them.'],
                'webhook_events' => ['label' => 'Events to Send', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000'], 'help' => 'One event name per line, for example enquiry.created.'],
            ],
        ],

        'payments' => [
            'label' => 'Payments',
            'icon' => 'credit-card',
            'intro' => [
                'title' => 'Payments',
                'description' => 'How money is collected: the online gateway, and the offline methods that sit beside it.',
                'icon' => 'credit-card',
                'accent' => 'green',
            ],
            // A panel or field carrying a `provider` key only appears, and is
            // only validated, when that gateway is the one selected. Every
            // gateway asks for different things, so a shared credential form
            // would always be showing boxes that do not apply.
            'panels' => [
                'Gateway' => ['icon' => 'credit-card', 'fields' => ['provider', 'mode', 'currency']],

                'CHIP Credentials' => [
                    'icon' => 'lock',
                    'provider' => 'chip',
                    'fields' => ['chip_brand_id', 'chip_api_key', 'chip_webhook_public_key'],
                ],

                'Billplz Credentials' => [
                    'icon' => 'lock',
                    'provider' => 'billplz',
                    'fields' => ['billplz_secret_key', 'billplz_collection_id', 'billplz_xsignature_key'],
                ],

                'toyyibPay Credentials' => [
                    'icon' => 'lock',
                    'provider' => 'toyyibpay',
                    'fields' => ['toyyibpay_secret_key', 'toyyibpay_category_code'],
                ],

                'Stripe Credentials' => [
                    'icon' => 'lock',
                    'provider' => 'stripe',
                    'fields' => ['stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret'],
                ],

                /*
                | Offline methods. No `provider` key, so they stay on screen whichever
                | gateway is chosen: they sit alongside it rather than replacing it,
                | and a shop order might use either.
                |
                | Neither collects money by itself. Cash on delivery is settled by
                | whoever hands the parcel over, and a transfer has to be checked
                | against the bank before an order is treated as paid.
                */
                'Cash on Delivery' => [
                    'icon' => 'cash',
                    'fields' => ['cod_enabled', 'cod_note'],
                ],

                'Manual Bank Transfer' => [
                    'icon' => 'building',
                    'fields' => [
                        'bank_transfer_enabled',
                        'bank_account_name',
                        'bank_name',
                        'bank_account_number',
                        'bank_transfer_note',
                    ],
                ],
            ],

            'fields' => [
                'provider' => ['label' => 'Provider', 'type' => 'select', 'options' => ['none' => 'Not configured', 'chip' => 'CHIP (chip-in.asia)', 'billplz' => 'Billplz', 'toyyibpay' => 'toyyibPay', 'stripe' => 'Stripe'], 'rules' => ['required', 'in:none,chip,billplz,toyyibpay,stripe'], 'help' => 'The credential fields below change to match this.'],
                'mode' => ['label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'Sandbox / Test', 'live' => 'Live'], 'rules' => ['required', 'in:sandbox,live'], 'help' => 'CHIP issues separate keys for test and live, so switching mode means switching keys too.'],
                'currency' => ['label' => 'Currency', 'type' => 'select', 'options' => ['MYR' => 'MYR', 'SGD' => 'SGD', 'USD' => 'USD'], 'rules' => ['required', 'in:MYR,SGD,USD'], 'help' => 'Event fees are entered in this currency.'],

                /* ---- Cash on delivery ---- */

                'cod_enabled' => [
                    'label' => 'Accept cash on delivery',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'Offers cash on delivery at checkout. The money is collected by whoever hands the parcel over, so an order stays unpaid here until somebody marks it settled.',
                ],
                'cod_note' => [
                    'label' => 'What The Buyer Is Told',
                    'type' => 'textarea',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'placeholder' => 'Have the exact amount ready. Our courier cannot give change.',
                    'help' => 'Shown at checkout when cash on delivery is chosen. Say anything they need to have ready.',
                ],

                /* ---- Manual bank transfer ---- */

                'bank_transfer_enabled' => [
                    'label' => 'Accept manual bank transfer',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'Shows your account details at checkout. Nothing arrives automatically, so a transfer has to be checked against the bank before the order is treated as paid.',
                ],
                'bank_account_name' => [
                    'label' => 'Account Name',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => 'Smart Digital Creative Management & Resources',
                    'help' => 'Exactly as the bank holds it. A name that does not match is the usual reason a transfer is rejected.',
                ],
                'bank_name' => [
                    'label' => 'Bank Name',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => 'Maybank',
                    'help' => 'The bank the account is held with.',
                ],
                'bank_account_number' => [
                    'label' => 'Account Number',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:60'],
                    'placeholder' => '512345678901',
                    /*
                     | Deliberately not marked secret. An account number has to be
                     | shown to a buyer to receive money, and storing it encrypted
                     | would only mean it could not be displayed back.
                     */
                    'help' => 'Digits only, no spaces or dashes. This is published to buyers, so it is stored in the clear rather than encrypted.',
                ],
                'bank_transfer_note' => [
                    'label' => 'What The Buyer Is Told',
                    'type' => 'textarea',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'placeholder' => 'Send the transfer receipt to event@smartcreative.my with your order reference. Orders are released once the payment shows in our account.',
                    'help' => 'Shown at checkout beside the account details. Tell them how to send proof and how long confirmation takes.',
                ],

                // ---- CHIP ----
                'chip_brand_id' => [
                    'provider' => 'chip',
                    'label' => 'Brand ID',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => '00000000-0000-0000-0000-000000000000',
                    'help' => 'From the Brands tab in the CHIP portal.',
                ],
                'chip_api_key' => [
                    'provider' => 'chip',
                    'label' => 'API Key',
                    'type' => 'password',
                    'secret' => true,
                    'rules' => ['nullable', 'string', 'max:500'],
                    'help' => 'From the API Keys tab. Sent as a bearer token, so it must match the mode selected above.',
                ],
                'chip_webhook_public_key' => [
                    'provider' => 'chip',
                    'label' => 'Webhook Public Key',
                    'type' => 'textarea',
                    'rows' => 8,
                    'rules' => ['nullable', 'string', 'max:4000'],
                    'placeholder' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
                    'help' => 'Shown when you create the webhook in CHIP. Paste the whole block including the BEGIN and END lines. Without it, callbacks cannot be verified and are refused.',
                ],

                // ---- Billplz ----
                'billplz_secret_key' => ['provider' => 'billplz', 'label' => 'Secret Key', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190']],
                'billplz_collection_id' => ['provider' => 'billplz', 'label' => 'Collection ID', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190']],
                'billplz_xsignature_key' => ['provider' => 'billplz', 'label' => 'X-Signature Key', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190'], 'help' => 'Used to verify Billplz callbacks.'],

                // ---- toyyibPay ----
                'toyyibpay_secret_key' => ['provider' => 'toyyibpay', 'label' => 'Secret Key', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190']],
                'toyyibpay_category_code' => ['provider' => 'toyyibpay', 'label' => 'Category Code', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190']],

                // ---- Stripe ----
                'stripe_publishable_key' => ['provider' => 'stripe', 'label' => 'Publishable Key', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190']],
                'stripe_secret_key' => ['provider' => 'stripe', 'label' => 'Secret Key', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190']],
                'stripe_webhook_secret' => ['provider' => 'stripe', 'label' => 'Webhook Signing Secret', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190']],
            ],
        ],

        'sms' => [
            'label' => 'SMS',
            'icon' => 'mobile',
            'intro' => [
                'title' => 'SMS',
                'description' => 'Texts participants directly. The wording lives in Event > Settings > SMS Template; the switches here decide which of it is sent.',
                'icon' => 'mobile',
                'accent' => 'amber',
            ],
            'panels' => [
                'Gateway' => ['icon' => 'mobile', 'fields' => ['enabled', 'provider', 'sender_id']],
                'Infobip Credentials' => ['icon' => 'lock', 'fields' => ['base_url', 'api_key']],
                'Legacy Credentials' => ['icon' => 'lock', 'fields' => ['endpoint', 'username', 'api_secret']],
                'Alerts' => ['icon' => 'clipboard', 'fields' => ['notify_registration', 'notify_payment', 'notify_payment_reminder']],
            ],
            'fields' => [
                'enabled' => [
                    'label' => 'Send SMS notifications',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'The master switch. With this off nothing is texted, whatever the alerts below say.',
                ],
                'provider' => [
                    'label' => 'Provider',
                    'type' => 'select',
                    'options' => SmsSettings::PROVIDERS,
                    'rules' => ['required'],
                    'help' => 'Only Infobip has a driver. Choosing another records the choice but cannot send.',
                ],
                'sender_id' => [
                    'label' => 'Sender ID',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:20'],
                    'placeholder' => '62033',
                    'help' => 'The short code or name recipients see. Issued by the gateway, not chosen here.',
                ],

                // ---- Infobip ----
                'base_url' => [
                    'label' => 'API Base URL',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => 'xxxxx.api.infobip.com',
                    'help' => 'Infobip gives each account its own host. The https:// is added for you if you leave it off.',
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'type' => 'password',
                    'secret' => true,
                    'rules' => ['nullable', 'string', 'max:255'],
                    'help' => 'Needs the sms:message:send scope. Sent as an App token, which is Infobip\'s own scheme rather than Bearer.',
                ],

                // ---- The gateways with no driver ----
                'endpoint' => [
                    'label' => 'Endpoint URL',
                    'type' => 'url',
                    'rules' => ['nullable', 'url', 'max:500'],
                    'help' => 'Only used by the gateways that have no driver yet.',
                ],
                'username' => ['label' => 'Username / Account SID', 'type' => 'text', 'rules' => ['nullable', 'string', 'max:190']],
                'api_secret' => ['label' => 'API Secret / Auth Token', 'type' => 'password', 'secret' => true, 'rules' => ['nullable', 'string', 'max:190']],

                // ---- Alerts ----
                'notify_registration' => [
                    'label' => 'Text people when they are registered',
                    'row_label' => 'Registration',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'Uses the Registration wording from Event > Settings > SMS Template.',
                ],
                'notify_payment' => [
                    'label' => 'Text people when payment is received',
                    'row_label' => 'Payment received',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                ],
                'notify_payment_reminder' => [
                    'label' => 'Text the registrant when chasing payment',
                    'row_label' => 'Payment reminder',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'Sent from the Participants screen, alongside the email reminder.',
                ],

            ],
        ],

        /*
        | Shipping. Postage for shop orders.
        |
        | EasyParcel quotes live rates, but a checkout cannot depend on somebody
        | else's API answering: if the call fails or the key is not set up, the flat
        | rates below are used instead. That is why they are not optional extras but
        | part of the configuration.
        |
        | The sender address is here rather than on each order because rate checking
        | needs a origin, and it is the same warehouse every time.
        */
        'shipping' => [
            'label' => 'Shipping',
            'icon' => 'archive',
            'intro' => [
                'title' => 'Shipping',
                'description' => 'What postage costs a shop buyer. EasyParcel quotes it live where it can, and the flat rates are what is charged when it cannot.',
                'icon' => 'archive',
                'accent' => 'blue',
            ],
            'panels' => [
                'EasyParcel' => [
                    'icon' => 'plug',
                    'fields' => [
                        'easyparcel_enabled',
                        'easyparcel_client_id',
                        'easyparcel_client_secret',
                        'easyparcel_api_key',
                    ],
                ],
                'Collection Address' => [
                    'icon' => 'building',
                    'fields' => [
                        'sender_name',
                        'sender_phone',
                        'sender_address_1',
                        'sender_address_2',
                        'sender_postcode',
                        'sender_city',
                        'sender_state',
                    ],
                ],
                'Flat Rates' => [
                    'icon' => 'cash',
                    'fields' => [
                        'flat_rate_west',
                        'flat_rate_east',
                        'free_shipping_threshold',
                        'shipping_note',
                    ],
                ],
            ],

            'fields' => [
                'easyparcel_enabled' => [
                    'label' => 'Quote rates through EasyParcel',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'Off means every order is charged the flat rate below. On means EasyParcel is asked first, and the flat rate is still used if it cannot answer.',
                ],
                /*
                 | There is deliberately no demo/live selector.
                 |
                 | Next Gen decides the environment from the EasyParcel account that
                 | authorises the connection, not from the endpoint and not from the
                 | application: one Client ID serves both, and an access token issued
                 | by a sandbox account can only ever reach sandbox. A selector here
                 | would be a control that changes nothing, which is worse than no
                 | control at all. Sign in with the sandbox account to test and the
                 | live account to go live.
                 */
                /*
                 | EasyParcel has two generations, and an account is on one or the
                 | other. Next Gen issues a Client ID and Client Secret and expects
                 | OAuth; Classic issues a single API key. Both are offered because
                 | which one applies is decided by the account, not by us, and an
                 | account that has not migrated cannot produce a Client ID at all.
                 */
                'easyparcel_client_id' => [
                    'label' => 'Client ID',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:255'],
                    'placeholder' => '00000000-0000-0000-0000-000000000000',
                    'help' => 'Next Gen accounts only. Found on the app you created under Integrations, on the same screen as the Client Secret.',
                ],
                'easyparcel_client_secret' => [
                    'label' => 'Client Secret',
                    'type' => 'password',
                    'secret' => true,
                    'rules' => ['nullable', 'string', 'max:255'],
                    'help' => 'Next Gen accounts only. Shown beside the Client ID on the app screen. Rotate it there if it has ever been pasted anywhere it should not have been.',
                ],
                'easyparcel_api_key' => [
                    'label' => 'API Key (Classic)',
                    'type' => 'password',
                    'secret' => true,
                    'rules' => ['nullable', 'string', 'max:255'],
                    'help' => 'Classic accounts only. Leave this blank if you have a Client ID above: a Next Gen account does not issue an API key, and pasting the Client ID here will not work.',
                ],

                /* ---- Where parcels are collected from ---- */

                'sender_name' => [
                    'label' => 'Contact Name',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => 'Smart Digital Creative',
                    'help' => 'Who the courier asks for at pickup.',
                ],
                'sender_phone' => [
                    'label' => 'Contact Phone',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:40'],
                    'placeholder' => '0198666898',
                    'help' => 'Digits only. The courier calls this if they cannot find the address.',
                ],
                'sender_address_1' => [
                    'label' => 'Address Line 1',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => 'Suite 33-01, 33rd Floor, Menara Keck Seng',
                ],
                'sender_address_2' => [
                    'label' => 'Address Line 2',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:190'],
                    'placeholder' => '203 Jalan Bukit Bintang',
                ],
                'sender_postcode' => [
                    'label' => 'Postcode',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:10'],
                    'placeholder' => '55100',
                    'help' => 'Rate checking works off the postcode, so this one matters more than the street.',
                ],
                'sender_city' => [
                    'label' => 'City',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:120'],
                    'placeholder' => 'Kuala Lumpur',
                ],
                'sender_state' => [
                    'label' => 'State',
                    'type' => 'select',
                    'options' => ShippingSettings::STATES,
                    /*
                     | A plain string rule rather than in:..., because SCHEMA is a
                     | class constant and PHP will not evaluate implode() in one. The
                     | dropdown is built from ShippingSettings::STATES, which stays
                     | the single source of truth, and this field is only ever set by
                     | an admin editing their own collection address. A value outside
                     | the list would simply not match East Malaysia when banding the
                     | flat rate, which is visible on this form.
                     */
                    'rules' => ['nullable', 'string', 'max:120'],
                    'help' => 'Stored as the full name. The courier code it maps to is worked out when a rate is requested, so the stored value does not depend on which EasyParcel API generation is in use.',
                ],

                /* ---- What is charged when EasyParcel cannot be asked ---- */

                'flat_rate_west' => [
                    'label' => 'Peninsular Malaysia (RM)',
                    'type' => 'text',
                    'rules' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
                    'placeholder' => '8.00',
                    'help' => 'Charged per order, not per item.',
                ],
                'flat_rate_east' => [
                    'label' => 'Sabah & Sarawak (RM)',
                    'type' => 'text',
                    'rules' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
                    'placeholder' => '15.00',
                    'help' => 'East Malaysia costs more, so it is asked for separately rather than averaged into one rate.',
                ],
                'free_shipping_threshold' => [
                    'label' => 'Free Shipping Over (RM)',
                    'type' => 'text',
                    'rules' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
                    'placeholder' => '150.00',
                    'help' => 'Leave blank for no free shipping. Compared against the goods total, before postage.',
                ],
                'shipping_note' => [
                    'label' => 'What The Buyer Is Told',
                    'type' => 'textarea',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'placeholder' => 'Orders are dispatched within 3 working days. Allow 3 to 5 working days for Peninsular Malaysia and up to 10 for Sabah and Sarawak.',
                    'help' => 'Shown at checkout beside the postage line.',
                ],
            ],
        ],

        'telegram' => [
            'label' => 'Telegram',
            'icon' => 'send',
            'intro' => [
                'title' => 'Telegram',
                'description' => 'Posts into one staff group so the office sees activity as it happens. This is not a channel to participants: they never see it.',
                'icon' => 'send',
                'accent' => 'blue',
            ],
            'panels' => [
                'Bot' => ['icon' => 'chat', 'fields' => ['enabled', 'bot_token', 'bot_username', 'chat_id']],
                'Alerts' => ['icon' => 'clipboard', 'fields' => ['notify_enquiry', 'notify_registration', 'notify_payment', 'notify_attendance']],
            ],
            'fields' => [
                'enabled' => [
                    'label' => 'Post alerts to Telegram',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'The master switch. With this off nothing is posted, whatever the alerts below say.',
                ],
                'bot_token' => [
                    'label' => 'Bot API Key',
                    'type' => 'password',
                    'secret' => true,
                    'rules' => ['nullable', 'string', 'max:255'],
                    'help' => 'Issued by @BotFather when you create the bot.',
                ],
                'bot_username' => [
                    'label' => 'Bot Username',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:100'],
                    'placeholder' => '@yourbot',
                    'help' => 'Recorded for reference only. Delivery is decided by the token and the chat id.',
                ],
                'chat_id' => [
                    'label' => 'Group ID',
                    'type' => 'text',
                    'rules' => ['nullable', 'string', 'max:100'],
                    'placeholder' => '-1001234567890',
                    'help' => 'The group or channel to post into. Supergroup ids are negative. The bot has to be a member of it first.',
                ],

                'notify_enquiry' => [
                    'label' => 'Post when a contact enquiry arrives',
                    'row_label' => 'Contact enquiry',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                ],
                'notify_registration' => [
                    'label' => 'Post when someone registers for an event',
                    'row_label' => 'Registration',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                ],
                'notify_payment' => [
                    'label' => 'Post when a payment is received',
                    'row_label' => 'Payment received',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                ],
                'notify_attendance' => [
                    'label' => 'Post when a player is removed or transferred at the counter',
                    'row_label' => 'Counter changes',
                    'type' => 'toggle',
                    'rules' => ['nullable', 'boolean'],
                    'help' => 'Substitutions are routine and are not posted. Removals and transfers between teams are.',
                ],
            ],
        ],
    ];

    public function index(Request $request)
    {
        $tab = $this->resolveTab($request->query('tab'));
        $provider = $this->selectedProvider($tab);

        return view('admin.settings.integration', [
            'tabs' => array_map(
                fn (array $definition) => ['label' => $definition['label'], 'icon' => $definition['icon']],
                self::SCHEMA
            ),
            'activeTab' => $tab,
            'definition' => self::SCHEMA[$tab],

            // The view renders every provider's panels but hides all except the
            // selected one, so switching gateway needs no page reload.
            'panels' => self::SCHEMA[$tab]['panels'],
            'selectedProvider' => $provider,

            'values' => $this->valuesFor($tab),
            'secretsPresent' => $this->secretsPresent($tab),
            'effectiveMail' => $tab === 'email' ? $this->effectiveMailConfig() : null,

            // Handed to the gateway, so they are shown read only for copying
            // into the CHIP portal rather than typed in here.
            'callbackUrls' => $tab === 'payments' ? $this->callbackUrls() : null,
            'chipEvents' => $tab === 'payments' ? self::CHIP_HANDLED_EVENTS : null,
            'webhooksVerifiable' => $tab === 'payments' ? PaymentSettings::canVerifyWebhooks() : null,

            // One honest sentence about whether the channel would actually do
            // anything right now, shown above its test button.
            'smsSummary' => $tab === 'sms' ? SmsSettings::summary() : null,
            'telegramSummary' => $tab === 'telegram' ? TelegramSettings::summary() : null,

            // Read only and generated, so it is shown rather than asked for. Only
            // resolved on the SMS tab, because reading it creates the secret on
            // first use and an installation that never sends SMS should not carry
            // one.
            'smsDeliveryUrl' => $tab === 'sms' && SmsSettings::isInfobip()
                ? SmsSettings::deliveryReportUrl()
                : null,

            'canUpdate' => $request->user()->hasPermission('settings.integration.update'),
        ]);
    }

    public function update(Request $request, string $tab)
    {
        if (! array_key_exists($tab, self::SCHEMA)) {
            throw new NotFoundHttpException('Unknown integration tab.');
        }

        // Only the selected gateway's fields are on the submitted form. Writing
        // every field would blank out the credentials of the others.
        $provider = $tab === 'payments'
            ? (string) $request->input('provider', PaymentSettings::PROVIDER_NONE)
            : null;

        $fields = $this->fieldsFor($tab, $provider);
        $validated = $request->validate($this->rulesFor($tab, $provider));
        $changed = [];

        foreach ($fields as $key => $field) {
            $isSecret = $field['secret'] ?? false;
            $isToggle = ($field['type'] ?? 'text') === 'toggle';

            if ($isToggle) {
                Setting::write("integration.{$tab}.{$key}", $request->boolean($key) ? '1' : '0', "integration.{$tab}");
                $changed[$key] = $request->boolean($key) ? '1' : '0';

                continue;
            }

            $value = $validated[$key] ?? null;

            // A blank secret field means "leave the stored value alone", so an
            // administrator can save the form without retyping credentials.
            if ($isSecret && ($value === null || $value === '')) {
                continue;
            }

            Setting::write("integration.{$tab}.{$key}", $value, "integration.{$tab}", $isSecret);
            $changed[$key] = $isSecret ? '[redacted]' : $value;
        }

        // The mail profile is cached per request, so the copy read earlier in
        // this one is now stale and would be reported back on the next page.
        if ($tab === 'email') {
            MailSettings::flush();
        }

        /*
         | Swapping the payment credentials has to drop the cached account balance.
         |
         | Without this the sidebar keeps showing the figure fetched under the old
         | key: for up to five minutes if the new key works, and indefinitely if it
         | does not, because the fallback figure is stored without an expiry. A test
         | account's balance sitting under a live key is the worst version of that,
         | since it looks like a working integration reporting the wrong money.
         */
        if ($tab === 'payments') {
            ChipBalance::forget();
        }

        AdminLogger::activity(
            'settings.integration.update',
            sprintf('Updated %s integration settings.', self::SCHEMA[$tab]['label']),
        );
        AdminLogger::audit(
            new Setting(['key' => "integration.{$tab}.*", 'group' => "integration.{$tab}"]),
            'settings.updated',
            null,
            $changed,
        );

        return redirect()
            ->route('admin.settings.integration', ['tab' => $tab])
            ->with('status', sprintf('%s settings saved.', self::SCHEMA[$tab]['label']));
    }

    private function resolveTab(?string $tab): string
    {
        return array_key_exists((string) $tab, self::SCHEMA) ? (string) $tab : 'email';
    }

    /**
     * Gateway currently stored, used to decide which panels start visible.
     */
    private function selectedProvider(string $tab): ?string
    {
        if ($tab !== 'payments') {
            return null;
        }

        return old('provider', PaymentSettings::provider());
    }

    /**
     * Fields that belong to the given provider: the shared ones plus that
     * gateway's own. Passing null returns every field, which is what the
     * non-payment tabs want.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldsFor(string $tab, ?string $provider): array
    {
        return array_filter(
            self::SCHEMA[$tab]['fields'],
            fn (array $field) => ! isset($field['provider']) || $field['provider'] === $provider,
        );
    }

    /**
     * URLs the gateway needs to be told about.
     *
     * Generated from the routes rather than typed in, because they are decided
     * by this application and a typo would silently break callbacks.
     *
     * @return array<string, array<string, string>>
     */
    private function callbackUrls(): array
    {
        return [
            'webhook' => [
                'label' => 'Webhook / Callback URL',
                'url' => route('payments.chip.webhook'),
                'help' => 'Register this in the Webhooks tab in CHIP. It must be reachable from the internet, so a local address will not receive anything.',
            ],
        ];
    }

    /**
     * Stored values for a tab. Secrets are omitted so they never reach the
     * browser; the view shows a "saved" marker instead.
     *
     * @return array<string, string|null>
     */
    private function valuesFor(string $tab): array
    {
        $stored = Setting::readGroup("integration.{$tab}");
        $values = [];

        foreach (self::SCHEMA[$tab]['fields'] as $key => $field) {
            if ($field['secret'] ?? false) {
                $values[$key] = null;

                continue;
            }

            $values[$key] = $stored["integration.{$tab}.{$key}"] ?? null;
        }

        return $values;
    }

    /**
     * Which secret fields already have a value stored.
     *
     * @return array<string, bool>
     */
    private function secretsPresent(string $tab): array
    {
        $stored = Setting::readGroup("integration.{$tab}");
        $present = [];

        foreach (self::SCHEMA[$tab]['fields'] as $key => $field) {
            if ($field['secret'] ?? false) {
                $present[$key] = filled($stored["integration.{$tab}.{$key}"] ?? null);
            }
        }

        return $present;
    }

    /**
     * Send one email to an address the operator names, and report exactly what
     * happened.
     *
     * The whole point is answering "does mail actually arrive", so a failure
     * shows the transport's own message rather than a tidy summary. That text
     * comes from the SMTP server, not from user input, and it is the only thing
     * that makes a misconfiguration diagnosable.
     */
    public function sendTestEmail(Request $request)
    {
        $data = $request->validate([
            'test_recipient' => ['required', 'string', 'email:rfc', 'max:190'],
        ], [
            'test_recipient.required' => 'Enter the address the test should go to.',
            'test_recipient.email' => 'That does not look like an email address.',
        ]);

        $recipient = $data['test_recipient'];

        // Saved settings are re-read here: the operator may have pressed Save
        // moments ago, and testing the previous profile would be misleading.
        MailSettings::flush();
        MailSettings::apply();

        $profile = MailSettings::effective();

        try {
            Mail::to($recipient)->send(new TestEmail(
                profile: $profile,
                sentBy: $request->user()->name,
                siteName: config('app.name'),
            ));
        } catch (Throwable $exception) {
            Log::error('Test email could not be sent.', [
                'recipient' => $recipient,
                'profile' => Arr::except($profile, ['Username']),
                'error' => $exception->getMessage(),
            ]);

            AdminLogger::activity(
                'settings.email.test-failed',
                sprintf('Test email to %s failed.', $recipient),
            );

            return back()
                ->with('test_email_error', $exception->getMessage())
                ->withInput();
        }

        AdminLogger::activity(
            'settings.email.test',
            sprintf('Sent a test email to %s.', $recipient),
        );

        // 'log' writes to the log file instead of delivering, so saying "check
        // your inbox" would be wrong.
        $message = $profile['Mailer'] === 'log'
            ? sprintf('Handed to the log mailer, so nothing was delivered to %s. The message body is in storage/logs.', $recipient)
            : sprintf('Test email accepted by %s for delivery to %s.', $profile['Host'] ?: 'the mail server', $recipient);

        return back()->with('test_email_success', $message);
    }

    /**
     * Text one number the operator names, and report exactly what Infobip said.
     *
     * Sends for real, because there is no dry run that proves anything: a wrong
     * sender id or an unregistered number only fails on a genuine send. The
     * confirmation on screen says so before it is pressed.
     */
    public function sendTestSms(Request $request, InfobipGateway $gateway)
    {
        $data = $request->validate([
            'test_number' => ['required', 'string', 'max:30'],
        ], [
            'test_number.required' => 'Enter the number the test should go to.',
        ]);

        $raw = $data['test_number'];
        $normalised = PhoneNumber::toInternational($raw);

        if ($normalised === null) {
            return back()
                ->with('test_sms_error', sprintf('"%s" could not be read as a telephone number.', $raw))
                ->withInput();
        }

        if (! SmsSettings::hasDriver()) {
            return back()
                ->with('test_sms_error', sprintf(
                    '%s is selected, and this application has no driver for it. Choose Infobip to send.',
                    SmsSettings::providerLabel(),
                ))
                ->withInput();
        }

        try {
            $result = $gateway->send($normalised, sprintf(
                '%s: test message sent by %s. If you can read this, SMS is working.',
                config('app.name'),
                $request->user()->name,
            ));
        } catch (MessagingException $exception) {
            // The gateway's own words, not the tidied public version: the person
            // reading this is the one diagnosing it.
            Log::error('Test SMS could not be sent.', [
                'destination' => $normalised,
                'error' => $exception->getMessage(),
            ]);

            AdminLogger::activity(
                'settings.sms.test-failed',
                sprintf('Test SMS to %s failed.', $normalised),
            );

            return back()->with('test_sms_error', $exception->getMessage())->withInput();
        }

        AdminLogger::activity(
            'settings.sms.test',
            sprintf('Sent a test SMS to %s.', $normalised),
        );

        return back()->with('test_sms_success', sprintf(
            'Accepted by Infobip for %s (typed as %s). Status: %s. Accepted is not the same as delivered; the handset is the proof.',
            $normalised,
            $raw,
            $result->description,
        ));
    }

    /**
     * Post one message into the configured Telegram group.
     *
     * getMe runs first so the two things that actually go wrong are told apart:
     * a bad token fails there, while a bot that is not a member of the group
     * fails only on the post.
     */
    public function sendTestTelegram(Request $request, TelegramNotifier $telegram)
    {
        if (! TelegramSettings::isReady()) {
            return back()->with('test_telegram_error', 'Enter the bot token and the group id first.');
        }

        try {
            $bot = $telegram->checkBot();
        } catch (MessagingException $exception) {
            return back()->with('test_telegram_error', $exception->getMessage());
        }

        try {
            $telegram->send(sprintf(
                "<b>Test message</b>\nSent by %s from %s. If you can read this, alerts will arrive here.",
                e($request->user()->name),
                e(config('app.name')),
            ));
        } catch (MessagingException $exception) {
            Log::error('Test Telegram message could not be posted.', [
                'chat_id' => TelegramSettings::chatId(),
                'error' => $exception->getMessage(),
            ]);

            AdminLogger::activity('settings.telegram.test-failed', 'Test Telegram message failed.');

            return back()->with('test_telegram_error', sprintf(
                'The token works (@%s), but posting to %s failed: %s',
                $bot['username'] ?? '?',
                TelegramSettings::chatId(),
                $exception->getMessage(),
            ));
        }

        AdminLogger::activity('settings.telegram.test', 'Sent a test Telegram message.');

        return back()->with('test_telegram_success', sprintf(
            'Posted to %s as @%s. Check the group.',
            TelegramSettings::chatId(),
            $bot['username'] ?? '?',
        ));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(string $tab, ?string $provider = null): array
    {
        $rules = [];

        foreach ($this->fieldsFor($tab, $provider) as $key => $field) {
            $fieldRules = $field['rules'] ?? ['nullable', 'string', 'max:190'];

            // Keep select values inside their declared option list.
            if (($field['type'] ?? null) === 'select' && isset($field['options'])) {
                $fieldRules[] = Rule::in(array_keys($field['options']));
            }

            $rules[$key] = $fieldRules;
        }

        return $rules;
    }

    /**
     * What mail will actually go out as.
     *
     * The saved profile is applied first, because otherwise this would report
     * the .env values while the settings on screen are the ones that win.
     *
     * @return array<string, string|null>
     */
    private function effectiveMailConfig(): array
    {
        MailSettings::apply();

        return MailSettings::effective();
    }
}
