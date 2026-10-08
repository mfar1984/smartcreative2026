<?php

namespace App\Services\Collection;

use App\Models\CollectionVerification;
use App\Services\Messaging\SmsResult;
use App\Support\LocalTime;

/**
 * What came back from issuing a code.
 *
 * Deliberately does not carry the code. The digits exist in one local variable
 * inside CollectionVerifier::issue(), go to the gateway, and are gone; there is no
 * object anywhere that can hand them back to a controller, a view or a test.
 */
class IssuedCode
{
    public function __construct(
        public readonly CollectionVerification $verification,
        public readonly SmsResult $sms,
    ) {
    }

    /**
     * One line for the operator at the counter: where it went, and until when.
     *
     * Names the gateway's own answer, because "accepted" and "arrived" are not the
     * same thing and the person holding the queue should know which one they have.
     */
    public function summary(): string
    {
        return sprintf(
            'Code texted to %s. It can be used until %s. The gateway reports: %s.',
            $this->sms->destination,
            LocalTime::time($this->verification->expires_at),
            $this->sms->description,
        );
    }
}
