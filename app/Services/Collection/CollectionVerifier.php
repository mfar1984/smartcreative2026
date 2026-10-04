<?php

namespace App\Services\Collection;

use App\Models\CollectionVerification;
use App\Models\CollectionVerificationAttempt;
use App\Services\Messaging\InfobipGateway;
use App\Services\Messaging\MessagingException;
use App\Support\PhoneNumber;
use App\Support\SmsSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Proving that the person at the counter is who they say, by texting them a code.
 *
 * Generic over what is being collected. Anything with a primary key can be the
 * target: issue() and verify() take a Model and bind the code to its morph class and
 * key, so the shop passes a ShopOrder and the event Collection screen will pass one
 * participant without a line of this file changing. There is nothing shop-shaped in
 * here, and nothing event-shaped either — what the code is FOR arrives as a short
 * subject string from the caller, which is the only thing that differs between them.
 *
 * Three rules this file exists to hold in one place:
 *
 *   1. The code is hashed before it touches the database, so a live code cannot be
 *      read out of the table by anybody who can read the table.
 *   2. The code is bound to one target, so a code texted for order A is not a key
 *      to order B.
 *   3. The code is spent on success, and spent on failure once the tries run out.
 *
 * Sent synchronously, straight through InfobipGateway. Not queued, and that is not
 * an oversight: the queue runs from cron once a minute, so a queued code arrives up
 * to a minute after the person asked for it, with a queue of people waiting. The
 * gateway's own answer is handed back to the caller so a failure is visible at the
 * counter the moment it happens rather than discovered later.
 */
class CollectionVerifier
{
    /**
     * How many codes one telephone number may receive inside the cooldown window.
     *
     * The per-target cooldown already stops the same collection being texted twice,
     * so this is the other axis: one handset, many targets. It is a burst limit
     * rather than a flat cooldown because one person legitimately collects for a
     * group — six people in one registration, six shirts, one representative — and a
     * flat cooldown would refuse the fifth of them.
     *
     * A fixed figure rather than a fourth setting: nobody has asked to tune it, and
     * a setting nobody changes is one more thing that can be set wrong.
     */
    public const NUMBER_BURST_LIMIT = 6;

    /** How much of a caller's subject line is used in the message. */
    private const SUBJECT_LIMIT = 60;

    public function __construct(private InfobipGateway $gateway)
    {
    }

    /* ---------------------------------------------------------------------
     | Issuing
     * ------------------------------------------------------------------ */

    /**
     * Text a fresh six-digit code to a number, bound to one thing being collected.
     *
     * @param  Model  $target  what is being collected
     * @param  string  $phone  as typed by the operator; normalised here
     * @param  string  $subject  short description for the message, e.g. "order SO-2026-0023"
     *
     * @throws CollectionCodeException  refused by our own rules: cooldown, or too many to one number
     * @throws MessagingException  refused by the gateway, or the gateway is not configured
     */
    public function issue(Model $target, string $phone, string $subject): IssuedCode
    {
        $destination = PhoneNumber::toInternational($phone);

        if ($destination === null) {
            throw MessagingException::unusableNumber($phone);
        }

        $cooldown = SmsSettings::collectionCodeCooldownMinutes();

        if ($retryAt = $this->cooldownEndsAt($target)) {
            throw CollectionCodeException::cooldown($retryAt, $cooldown);
        }

        if ($this->sentToNumberSince($destination, now()->subMinutes($cooldown)) >= self::NUMBER_BURST_LIMIT) {
            throw CollectionCodeException::numberFlooded(self::NUMBER_BURST_LIMIT, $cooldown);
        }

        /*
         | The only place the digits exist. They are hashed on the next line, handed
         | to the gateway below, and never assigned anywhere that outlives this call:
         | not onto the model, not into the return value, not into a log line.
         */
        $code = $this->freshCode();

        $verification = DB::transaction(function () use ($target, $destination, $code) {
            /*
             | Any code already in somebody's hand for this target dies here. One live
             | code per thing being collected, so "the code" is never ambiguous and an
             | older message cannot be read out instead of the new one.
             */
            $this->live($target)->update(['burned_at' => now()]);

            return CollectionVerification::create([
                'verifiable_type' => $target->getMorphClass(),
                'verifiable_id' => $target->getKey(),
                'phone' => $destination,
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'max_attempts' => SmsSettings::collectionCodeMaxAttempts(),
                'expires_at' => now()->addMinutes(SmsSettings::collectionCodeExpiryMinutes()),
                'issued_by' => Auth::id(),
                'issued_ip' => Request::ip(),
            ]);
        });

        try {
            $result = $this->gateway->send($destination, $this->message($code, $subject));
        } catch (\Throwable $e) {
            /*
             | Nobody received this one, so it must not sit there holding the cooldown
             | shut or shadowing a later code. Burned rather than deleted: the row is
             | the record that an issue was attempted and failed, which is half of why
             | the override exists.
             */
            $verification->forceFill(['burned_at' => now()])->save();

            throw $e;
        }

        // sent_at is what makes the code usable, and what starts the cooldown. Set
        // only once the gateway has taken the message.
        $verification->forceFill([
            'sent_at' => now(),
            'gateway_message_id' => $result->messageId,
            'gateway_status' => $result->statusGroup,
        ])->save();

        return new IssuedCode($verification, $result);
    }

    /* ---------------------------------------------------------------------
     | Verifying
     * ------------------------------------------------------------------ */

    /**
     * Check a code against the live one for this target.
     *
     * The target is half of the check. A code is looked up by what it was issued for
     * and never by its own value, so a code texted for one collection cannot be
     * entered against another: there is simply no row to match it.
     */
    public function verify(Model $target, string $code): CodeCheck
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        /** @var CollectionVerification|null $verification */
        $verification = $this->pending($target)->first();

        if ($verification === null) {
            return new CodeCheck(CodeCheck::NONE);
        }

        if ($verification->hasExpired()) {
            $verification->forceFill(['burned_at' => now()])->save();
            $this->logAttempt($verification, CollectionVerificationAttempt::OUTCOME_EXPIRED);

            return new CodeCheck(CodeCheck::EXPIRED, $verification);
        }

        if ($code === '' || ! Hash::check($code, $verification->code_hash)) {
            $verification->increment('attempts');
            $verification->refresh();

            $exhausted = $verification->attemptsLeft() <= 0;

            if ($exhausted) {
                $verification->forceFill(['burned_at' => now()])->save();
            }

            $this->logAttempt(
                $verification,
                $exhausted
                    ? CollectionVerificationAttempt::OUTCOME_BURNED
                    : CollectionVerificationAttempt::OUTCOME_WRONG,
            );

            return new CodeCheck(
                $exhausted ? CodeCheck::BURNED : CodeCheck::WRONG,
                $verification,
                $verification->attemptsLeft(),
            );
        }

        // Single use. verified_at records the success, burned_at is what stops it
        // being used a second time, and they are set together so there is no window
        // in which a spent code is still live.
        $verification->forceFill([
            'verified_at' => now(),
            'verified_by' => Auth::id(),
            'burned_at' => now(),
        ])->save();

        $this->logAttempt($verification, CollectionVerificationAttempt::OUTCOME_VERIFIED);

        return new CodeCheck(CodeCheck::VERIFIED, $verification);
    }

    /* ---------------------------------------------------------------------
     | Asking about state
     * ------------------------------------------------------------------ */

    /**
     * The code currently waiting to be entered for this target, if there is one.
     *
     * Exposed so a caller can check what the code was texted to before spending an
     * attempt on it. The shop uses it to refuse a handover whose collector phone
     * does not match the number the code actually went to — otherwise the record
     * could name one person while the code reached another.
     */
    public function pendingFor(Model $target): ?CollectionVerification
    {
        return $this->pending($target)->first();
    }

    /**
     * When another code may be sent for this target, or null when one may go now.
     *
     * Counted from the last code the gateway actually accepted, so a failed send can
     * be retried at once. A failure is the moment the counter most needs another go.
     */
    public function cooldownEndsAt(Model $target): ?Carbon
    {
        $minutes = SmsSettings::collectionCodeCooldownMinutes();

        $lastSent = $this->forTarget($target)
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->value('sent_at');

        if ($lastSent === null) {
            return null;
        }

        $endsAt = Carbon::parse($lastSent)->addMinutes($minutes);

        return $endsAt->isFuture() ? $endsAt : null;
    }

    /* ---------------------------------------------------------------------
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Six digits, from the cryptographic generator.
     *
     * random_int rather than rand or mt_rand: the whole mechanism rests on the code
     * being unguessable, and the fast generators are seeded predictably enough that
     * a run of codes can be reconstructed.
     */
    private function freshCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The text message.
     *
     * Deliberately plain and short. One segment where it can be, no links, and it
     * names what the code is for so somebody holding two of them can tell them
     * apart. The subject comes from the caller, which is the only thing that makes
     * this generic across the shop and the event side.
     */
    private function message(string $code, string $subject): string
    {
        return sprintf(
            '%s is the collection code for %s. Valid %d minutes. Give it only to the staff handing the goods over.',
            $code,
            Str::limit(trim($subject), self::SUBJECT_LIMIT, '...'),
            SmsSettings::collectionCodeExpiryMinutes(),
        );
    }

    /**
     * Every code ever issued for one target.
     */
    private function forTarget(Model $target): Builder
    {
        return CollectionVerification::query()
            ->where('verifiable_type', $target->getMorphClass())
            ->where('verifiable_id', $target->getKey());
    }

    /**
     * Codes that have gone out and have not been spent, newest first.
     *
     * Expiry is deliberately not in this clause. An expired code still has to be
     * found so the operator can be told it expired, rather than told there is no
     * code at all and sent looking for a fault that is not there.
     */
    private function pending(Model $target): Builder
    {
        return $this->forTarget($target)
            ->whereNotNull('sent_at')
            ->whereNull('verified_at')
            ->whereNull('burned_at')
            ->orderByDesc('id');
    }

    /**
     * Codes for this target that are still usable, for the purpose of killing them.
     */
    private function live(Model $target): Builder
    {
        return $this->forTarget($target)
            ->whereNull('verified_at')
            ->whereNull('burned_at');
    }

    /**
     * How many codes this number has been sent since a moment.
     */
    private function sentToNumberSince(string $destination, Carbon $since): int
    {
        return CollectionVerification::query()
            ->where('phone', $destination)
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->count();
    }

    /**
     * Record that somebody entered a code, and how it went.
     *
     * Never the digits entered. A wrong guess is still somebody's near miss at a
     * live code, and a table of near misses is a table of hints.
     */
    private function logAttempt(CollectionVerification $verification, string $outcome): void
    {
        $user = Auth::user();

        CollectionVerificationAttempt::create([
            'collection_verification_id' => $verification->id,
            'outcome' => $outcome,
            'user_id' => $user?->id,
            'actor_label' => $user?->logLabel(),
            'ip_address' => Request::ip(),
        ]);
    }
}
