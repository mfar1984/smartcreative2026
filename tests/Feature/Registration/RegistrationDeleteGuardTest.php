<?php

namespace Tests\Feature\Registration;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventRegistrationPayment;
use App\Models\Role;
use App\Models\User;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * What stops a registration being deleted.
 *
 * An entry that names RM 40.00 and holds none of it was being refused, and the
 * refusal said the RM 40.00 had been taken. It never had: `amount` is the invoice
 * and a `payment_reference` is one checkout somebody opened, so neither is
 * evidence that money arrived. Only a sum received, a receipt in the ledger, or an
 * amount already refunded is.
 *
 * Most of this file is therefore about the two sides of that line: what must now
 * be removable, and what must still be refused, with the refusal quoting the
 * figure actually taken rather than the one invoiced.
 */
class RegistrationDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'Fun Run',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => Event::MODE_GROUPING,
            'fee' => 40,
            'seats_total' => 100,
            'seats_taken' => 5,
            'min_players' => 2,
            'max_players' => 5,
        ]);
    }

    private function registration(Event $event, array $overrides = []): EventRegistration
    {
        return EventRegistration::create($overrides + [
            'event_id' => $event->id,
            'reference' => 'REG-' . strtoupper(substr(uniqid(), -8)),
            'mode' => $event->registration_mode,
            'team_name' => 'The Rahmans',
            'status' => EventRegistration::STATUS_CONFIRMED,
            'registration_fee' => 40,
            'amount' => 40,
        ]);
    }

    private function member(EventRegistration $registration, int $n): EventParticipant
    {
        return EventParticipant::create([
            'event_registration_id' => $registration->id,
            'role' => ParticipantOptions::ROLE_PARTICIPANT,
            'full_name' => 'Member ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'phone' => '01200000' . $n,
            'email' => 'member' . $n . '@example.test',
            'gender' => 'female',
            'race' => 'malay',
        ]);
    }

    private function receipt(EventRegistration $registration, float $amount): EventRegistrationPayment
    {
        return EventRegistrationPayment::create([
            'event_registration_id' => $registration->id,
            'amount' => $amount,
            'received_at' => now(),
            'source' => EventRegistrationPayment::SOURCE_MANUAL,
        ]);
    }

    /**
     * Somebody who may read and remove every registration.
     *
     * The super-admin slug rather than a built permission list, because
     * User::hasPermission() short-circuits to true for that role and so satisfies
     * the permission:participants.delete middleware on the route.
     */
    private function administrator(): User
    {
        $role = Role::create([
            'slug' => Role::SUPER_ADMIN,
            'name' => 'Super Admin',
            'is_active' => true,
        ]);

        return User::create([
            'name' => 'Admin',
            'username' => 'admin-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'secret-password',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function remove(EventRegistration $registration)
    {
        return $this->actingAs($this->administrator())
            ->delete(route('admin.event.participants.destroy', $registration));
    }

    /* ---------------------------------------------------------------------
     | What must now be removable
     * ------------------------------------------------------------------ */

    public function test_an_invoiced_but_unpaid_registration_can_be_deleted(): void
    {
        // The reported entry: RM 40.00 owed, nothing received, no receipts.
        $event = $this->event();
        $registration = $this->registration($event, [
            'amount' => 40,
            'amount_paid' => 0,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'payment_reference' => null,
        ]);

        $this->member($registration, 1);
        $this->member($registration, 2);

        $response = $this->remove($registration);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.event.participants'));
        $response->assertSessionHas('status');

        $this->assertSame(0, EventRegistration::query()->count());

        // And the places it was holding went back, which is the half of destroy()
        // the guard was keeping unreachable.
        $this->assertSame(3, $event->fresh()->seats_taken);
    }

    public function test_an_abandoned_checkout_does_not_block_a_delete(): void
    {
        /*
         | A purchase id is written the moment a checkout is opened, so a payer who
         | reached the gateway and closed the tab left one behind. Treating it as
         | money taken made that entry permanently undeletable.
         */
        $registration = $this->registration($this->event(), [
            'amount' => 40,
            'amount_paid' => 0,
            'payment_status' => EventRegistration::PAYMENT_PENDING,
            'payment_reference' => 'some-purchase-id',
        ]);

        $this->member($registration, 1);

        $this->remove($registration)->assertSessionHasNoErrors();

        $this->assertSame(0, EventRegistration::query()->count());
    }

    /* ---------------------------------------------------------------------
     | What must still be refused
     * ------------------------------------------------------------------ */

    public function test_a_paid_registration_is_still_refused(): void
    {
        $registration = $this->registration($this->event(), [
            'amount' => 40,
            'amount_paid' => 40,
            'payment_status' => EventRegistration::PAYMENT_PAID,
        ]);

        $this->member($registration, 1);
        $this->receipt($registration, 40);

        $this->remove($registration)->assertSessionHasErrors('registration');

        $this->assertNotNull($registration->fresh());

        $this->assertStringContainsString(
            'RM 40.00',
            session('errors')->first('registration'),
        );
    }

    public function test_a_part_paid_registration_is_refused_and_the_message_quotes_what_was_taken(): void
    {
        // RM 15.00 of RM 40.00. The refusal has to name the fifteen that arrived,
        // not the forty that was asked for.
        $registration = $this->registration($this->event(), [
            'amount' => 40,
            'amount_paid' => 15,
            'payment_status' => EventRegistration::PAYMENT_PARTIAL,
        ]);

        $this->member($registration, 1);
        $this->receipt($registration, 15);

        $this->remove($registration)->assertSessionHasErrors('registration');

        $this->assertNotNull($registration->fresh());

        $message = session('errors')->first('registration');

        $this->assertStringContainsString('RM 15.00', $message);
        $this->assertStringNotContainsString('RM 40.00', $message);
    }

    public function test_a_receipt_on_record_refuses_the_delete_on_its_own(): void
    {
        /*
         | amount_paid is a denormalised sum kept for the money screens, and the
         | migration that added it says it must always be able to prove itself. A
         | receipt in the ledger with a stale column beside it is still money taken,
         | so the ledger is asked as well as the column.
         */
        $registration = $this->registration($this->event(), [
            'amount' => 40,
            'amount_paid' => 0,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
        ]);

        $this->member($registration, 1);
        $this->receipt($registration, 40);

        $this->remove($registration)->assertSessionHasErrors('registration');

        $this->assertNotNull($registration->fresh());
    }

    /* ---------------------------------------------------------------------
     | The screens that have to agree with the controller
     * ------------------------------------------------------------------ */

    public function test_the_list_offers_delete_for_an_invoiced_but_unpaid_entry(): void
    {
        // The list's own comment says the button mirrors the controller, so a fix
        // the controller allows but the list hides is a fix nobody can reach.
        // Individual mode because that is the tab the screen opens on.
        $event = $this->event([
            'registration_mode' => Event::MODE_INDIVIDUAL,
            'min_players' => null,
            'max_players' => null,
        ]);

        $registration = $this->registration($event, [
            'amount' => 40,
            'amount_paid' => 0,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'payment_reference' => null,
        ]);

        $this->member($registration, 1);

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants'));

        $response->assertOk();
        $response->assertSee(route('admin.event.participants.destroy', $registration), false);
    }

    public function test_the_detail_screen_draws_the_error_panel_only_once(): void
    {
        /*
         | The layout already includes the shared flash partial inside <main>, so the
         | page's own copy of it printed the red panel twice: once above the heading
         | and once inside the Registration card.
         |
         | Asked with an error bag in the session, because the panel is only drawn
         | when there is something to draw, and a page with no errors would satisfy
         | the count whether the duplicate was removed or not.
         |
         | payment_reference stays blank: show() calls refreshPayment(), which
         | returns early on a blank reference and so never reaches the gateway.
         */
        $registration = $this->registration($this->event(), [
            'amount' => 40,
            'amount_paid' => 0,
            'payment_status' => EventRegistration::PAYMENT_UNPAID,
            'payment_reference' => null,
        ]);

        $this->member($registration, 1);

        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag([
            'registration' => 'Something needed correcting.',
        ]));

        $response = $this->actingAs($this->administrator())
            ->withSession(['errors' => $errors])
            ->get(route('admin.event.participants.show', $registration));

        $response->assertOk();

        $this->assertSame(
            1,
            substr_count($response->getContent(), 'Please correct the following:'),
        );
    }
}
