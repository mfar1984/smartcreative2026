<?php

namespace Tests\Feature\Registration;

use App\Mail\PlayerEnquiryReceived;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\EventRegistration;
use App\Models\EventTemplate;
use App\Models\PlayerMessage;
use App\Models\Role;
use App\Models\User;
use App\Models\WifiCredential;
use App\Services\EventTemplateRenderer;
use App\Support\EventTemplates;
use App\Support\ParticipantOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switching the group name off for a grouping event.
 *
 * A few colleagues or one family signing up together have no group to name, and the only
 * honest answer would be the first person's own name typed into a second box. The setting
 * takes the question away: the public form skips the field and the first person is simply
 * Participant 1 rather than the group contact.
 *
 * On by default, which is the assertion that matters most here. Every event that existed
 * before the column did must keep asking, so most of this file is about what does *not*
 * change — a grouping event built without the key, a manager event told to switch it off,
 * and an individual event that never had a name in the first place.
 */
class GroupNameOptionTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $mode, array $overrides = []): Event
    {
        return Event::create($overrides + [
            'slug' => 'event-' . uniqid(),
            'title' => 'Family Fun Day',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'status' => Event::STATUS_OPEN,
            'registration_mode' => $mode,
            'fee' => 10,
            'seats_total' => 100,
            'min_players' => 3,
            'max_players' => 5,
        ]);
    }

    private function person(int $n, string $role = ParticipantOptions::ROLE_PARTICIPANT): array
    {
        return [
            'role' => $role,
            'full_name' => 'Member ' . $n,
            'ic_number' => '900101' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'address_line_1' => $n . ' Jalan Dua',
            'city' => 'Shah Alam',
            'state' => 'Selangor',
            'country' => 'Malaysia',
            'phone' => '01200000' . $n,
            'email' => 'member' . $n . '@example.test',
            'gender' => 'female',
            'race' => 'malay',
        ];
    }

    /** @param array<int, array<string, mixed>> $payload */
    private function submit(Event $event, array $payload)
    {
        return $this->post(route('registration.store', ['event' => $event->slug]), $payload);
    }

    private function group(): array
    {
        return [$this->person(1), $this->person(2), $this->person(3)];
    }

    /**
     * The distinct card headings on the public form, in the order they appear.
     *
     * Matched on the heading's own markup in registration-participant.blade.php
     * rather than hunted for as loose text, so wording that happens to appear
     * elsewhere on the page cannot satisfy it. Distinct, because what is being
     * pinned is whether the first card differs from its siblings, not how many
     * cards the form happens to open with.
     *
     * @return array<int, string>
     */
    private function participantTitles(string $html): array
    {
        preg_match_all('/font-bold text-gray-900 truncate">([^<]*)</', $html, $matches);

        return array_values(array_unique(array_map('trim', $matches[1])));
    }

    /**
     * Somebody who may read and correct every admin screen.
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

    /* ---------------------------------------------------------------------
     | On by default: nothing an existing event does may change
     * ------------------------------------------------------------------ */

    public function test_an_event_created_without_touching_the_setting_still_asks_for_a_group_name(): void
    {
        // Built with no mention of the new key, exactly as every row stored
        // before the column existed.
        $event = $this->event(Event::MODE_GROUPING);

        $this->assertTrue($event->fresh()->requires_group_name);
        $this->assertTrue($event->fresh()->usesGroupName());
    }

    public function test_the_public_form_asks_for_the_group_name_by_default(): void
    {
        $event = $this->event(Event::MODE_GROUPING);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        $response->assertSee('name="team_name"', false);
        $response->assertSee('Group / Organisation Name');
        $response->assertSee('Group Contact / Participant 1');
        $response->assertSee('The first person is also the group contact');

        // The first card is the one that differs, which is what the heading is
        // for when a group has a name and somebody has to be its contact.
        $this->assertSame(
            ['Group Contact / Participant 1', 'Participant'],
            $this->participantTitles($response->getContent()),
        );
    }

    public function test_the_group_name_is_still_compulsory_by_default(): void
    {
        $event = $this->event(Event::MODE_GROUPING);

        // Omitted: refused, and nothing is written.
        $this->submit($event, ['participants' => $this->group()])
            ->assertSessionHasErrors('team_name');

        $this->assertSame(0, EventRegistration::query()->count());

        // Supplied: accepted and kept, which is the assertion that pins today's
        // behaviour for every event that already exists.
        $this->submit($event, [
            'team_name' => 'The Rahmans',
            'participants' => $this->group(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('The Rahmans', EventRegistration::query()->sole()->team_name);
    }

    /* ---------------------------------------------------------------------
     | Switched off
     * ------------------------------------------------------------------ */

    public function test_the_public_form_skips_the_group_name_when_it_is_switched_off(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        $response->assertDontSee('name="team_name"', false);
        $response->assertDontSee('Group / Organisation Name');

        // The phrase appears nowhere, in either case.
        $response->assertDontSee('Group Contact');
        $response->assertDontSee('group contact');

        /*
         | Every card is headed identically, the first one included.
         |
         | With nobody acting as group contact the first person is not special, so
         | the numbered badge beside the heading is the only thing that tells the
         | rows apart. "Participant 1" would have put the number on that one card
         | twice and nowhere else.
         */
        $this->assertSame(['Participant'], $this->participantTitles($response->getContent()));
        $response->assertDontSee('Participant 1');
    }

    public function test_a_group_registers_with_no_group_name_when_it_is_switched_off(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);

        $this->submit($event, ['participants' => $this->group()])
            ->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertNull($registration->team_name);
        $this->assertSame(Event::MODE_GROUPING, $registration->mode);
        $this->assertCount(3, $registration->participants);

        // The money is untouched by this setting: one fee for the group.
        $this->assertSame('10.00', $registration->registration_fee);
        $this->assertSame('10.00', $registration->amount);

        // And places are still counted per head.
        $this->assertSame(3, $event->fresh()->seats_taken);
    }

    public function test_a_posted_group_name_is_discarded_when_the_event_does_not_ask_for_one(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);

        // The form never drew the field, so anything under that name was added by
        // hand. Cleared rather than refused: there is nothing for a registrant to
        // correct, and the event is not given a name it stopped asking for.
        $this->submit($event, [
            'team_name' => 'Injected By Hand',
            'participants' => $this->group(),
        ])->assertSessionHasNoErrors();

        $this->assertNull(EventRegistration::query()->sole()->team_name);
    }

    /* ---------------------------------------------------------------------
     | The modes the setting must not reach
     * ------------------------------------------------------------------ */

    public function test_manager_mode_keeps_its_team_name_even_when_the_flag_is_off(): void
    {
        // A squad's team name is its identity on every bracket and standing, so
        // the setting is grouping-only and a stored false means nothing here.
        $event = $this->event(Event::MODE_MANAGER, ['requires_group_name' => false]);

        $this->assertTrue($event->fresh()->usesGroupName());

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        $response->assertSee('name="team_name"', false);
        $response->assertSee('Team / Organisation Name');

        $this->submit($event, [
            'participants' => [
                $this->person(1, ParticipantOptions::ROLE_MANAGER),
                $this->person(2, ParticipantOptions::ROLE_PLAYER),
                $this->person(3, ParticipantOptions::ROLE_PLAYER),
            ],
        ])->assertSessionHasErrors('team_name');

        $this->assertSame(0, EventRegistration::query()->count());
    }

    public function test_individual_mode_is_untouched_by_the_flag(): void
    {
        $event = $this->event(Event::MODE_INDIVIDUAL, [
            'requires_group_name' => false,
            'min_players' => null,
            'max_players' => null,
        ]);

        // An individual entry never had a name, so the setting cannot give it one
        // nor take one away.
        $this->assertFalse($event->fresh()->usesGroupName());

        $response = $this->get(route('registration', ['register' => $event->slug]));

        $response->assertOk();
        $response->assertDontSee('name="team_name"', false);
        $response->assertSee('Your Details');

        $this->submit($event, ['participants' => [$this->person(1)]])
            ->assertSessionHasNoErrors();

        $registration = EventRegistration::query()->sole();

        $this->assertNull($registration->team_name);
        $this->assertSame('10.00', $registration->amount);
        $this->assertSame(1, $event->fresh()->seats_taken);
    }

    /* ---------------------------------------------------------------------
     | The admin entry screen follows the event
     * ------------------------------------------------------------------ */

    private function registration(Event $event, ?string $teamName): EventRegistration
    {
        return EventRegistration::create([
            'event_id' => $event->id,
            'reference' => 'REG-' . strtoupper(substr(uniqid(), -8)),
            'mode' => $event->registration_mode,
            'team_name' => $teamName,
            'status' => EventRegistration::STATUS_CONFIRMED,
            'registration_fee' => 10,
            'amount' => 10,
        ]);
    }

    public function test_an_admin_may_clear_the_group_name_when_the_event_stopped_asking_for_one(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);
        $registration = $this->registration($event, 'Typed Before The Setting Changed');

        $this->actingAs($this->administrator())
            ->put(route('admin.event.participants.entry.update', $registration), [
                'team_name' => '',
                'notes' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($registration->fresh()->team_name);
    }

    public function test_an_admin_still_cannot_clear_the_group_name_on_an_event_that_asks_for_one(): void
    {
        $event = $this->event(Event::MODE_GROUPING);
        $registration = $this->registration($event, 'The Rahmans');

        $this->actingAs($this->administrator())
            ->put(route('admin.event.participants.entry.update', $registration), [
                'team_name' => '',
                'notes' => '',
            ])
            ->assertSessionHasErrors('team_name');

        $this->assertSame('The Rahmans', $registration->fresh()->team_name);
    }

    /* ---------------------------------------------------------------------
     | The admin control
     * ------------------------------------------------------------------ */

    /**
     * The least an event needs to be saved from the admin form.
     *
     * @return array<string, mixed>
     */
    private function eventForm(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Admin Made Event',
            'category' => 'Community',
            'starts_at' => now()->addWeek()->toDateString(),
            'ends_at' => now()->addWeek()->toDateString(),
            'location' => 'Sibu',
            'seats_total' => 100,
            'status' => Event::STATUS_DRAFT,
            'registration_mode' => Event::MODE_GROUPING,
            'min_players' => 3,
            'max_players' => 5,
        ];
    }

    /**
     * The admin checkbox drawn in the ticked state.
     *
     * A regex rather than a literal string, because @checked sits on its own line
     * in form.blade.php: a newline and a long run of spaces separate it from
     * value="1", so any literal spelling of the two together can never match and
     * would pin nothing at all. [^>] cannot cross the tag's own closing bracket,
     * so only this input can satisfy it.
     */
    private const TICKED_BOX = '/<input[^>]*\bid="requires_group_name"[^>]*\bchecked\b[^>]*>/';

    public function test_the_create_screen_carries_the_group_name_control_ticked(): void
    {
        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.registration.create'));

        $response->assertOk();
        $response->assertSee('id="requires_group_name"', false);
        $response->assertSee('Ask for a group / organisation name');

        // Ticked, not merely present. A new event asks for a group name unless
        // somebody says otherwise, and this is where that is decided.
        $this->assertMatchesRegularExpression(self::TICKED_BOX, $response->getContent());

        // Hidden until Grouping is chosen, because the form opens on Individual
        // and only grouping may switch the name off.
        $this->assertMatchesRegularExpression(
            '/id="group-name-rule" class="hidden"/',
            $response->getContent(),
        );
    }

    public function test_an_unticked_box_is_saved_and_comes_back_unticked(): void
    {
        $admin = $this->administrator();

        $this->actingAs($admin)
            ->post(route('admin.event.registration.store'), $this->eventForm([
                'requires_group_name' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $event = Event::query()->sole();

        $this->assertFalse($event->requires_group_name);
        $this->assertFalse($event->usesGroupName());

        // And the edit form draws it unticked rather than resetting to the default.
        $response = $this->actingAs($admin)
            ->get(route('admin.event.registration.edit', $event));

        $response->assertOk();

        // Both halves are needed. Without the first, a box that had vanished
        // from the form altogether would satisfy the second.
        $response->assertSee('id="requires_group_name"', false);
        $this->assertDoesNotMatchRegularExpression(self::TICKED_BOX, $response->getContent());
    }

    public function test_a_mode_that_is_not_grouping_keeps_the_group_name_on(): void
    {
        // The row is hidden rather than removed, so its hidden 0 still submits.
        // An individual or manager event must not be stored as one that collects
        // no name, whatever arrives in the payload.
        $this->actingAs($this->administrator())
            ->post(route('admin.event.registration.store'), $this->eventForm([
                'registration_mode' => Event::MODE_MANAGER,
                'requires_group_name' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Event::query()->sole()->requires_group_name);
    }

    public function test_the_admin_entry_screen_drops_the_compulsory_mark_when_the_name_is_not_asked_for(): void
    {
        $admin = $this->administrator();

        // The red star sits immediately after the label, so the pair is matched
        // together rather than hunting for a class that appears all over the page.
        $marked = '/Group or organisation name\s*<span class="text-red-600"/';

        $asked = $this->registration($this->event(Event::MODE_GROUPING), 'The Rahmans');

        $response = $this->actingAs($admin)
            ->get(route('admin.event.participants.show', $asked));

        $response->assertOk();
        $response->assertSee('Group or organisation name');
        $this->assertMatchesRegularExpression($marked, $response->getContent());

        $notAsked = $this->registration(
            $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]),
            null,
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.event.participants.show', $notAsked));

        $response->assertOk();
        // The field is still there to fill in if somebody wants to; it is simply
        // no longer compulsory.
        $response->assertSee('Group or organisation name');
        $this->assertDoesNotMatchRegularExpression($marked, $response->getContent());
    }

    public function test_the_group_name_setting_round_trips_through_the_admin_update_path(): void
    {
        $admin = $this->administrator();

        // Saved with no mention of the key at all, which is the DEFAULT 1 on the
        // column doing the deciding rather than an absent box reading as false.
        $this->actingAs($admin)
            ->post(route('admin.event.registration.store'), $this->eventForm())
            ->assertSessionHasNoErrors();

        $event = Event::query()->sole();

        $this->assertTrue($event->requires_group_name);

        // Off...
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm([
                'requires_group_name' => '0',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($event->fresh()->requires_group_name);

        // ...and back on, because a setting that cannot be undone is a trap.
        $this->actingAs($admin)
            ->put(route('admin.event.registration.update', $event), $this->eventForm([
                'requires_group_name' => '1',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($event->fresh()->requires_group_name);
    }

    /* ---------------------------------------------------------------------
     | What reads a team_name that is now allowed to be NULL
     |
     | Five places take the stored name and show it to somebody. None of them
     | could be handed a blank one before this setting existed, so each is
     | exercised against an entry that genuinely has none.
     * ------------------------------------------------------------------ */

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

    public function test_the_wifi_slips_group_a_nameless_entry_under_the_people_on_it(): void
    {
        $event = $this->event(Event::MODE_GROUPING, [
            'requires_group_name' => false,
            'offers_wifi' => true,
        ]);

        $registration = $this->registration($event, null);

        foreach ([1, 2] as $n) {
            $person = $this->member($registration, $n);

            WifiCredential::create([
                'event_id' => $event->id,
                'event_registration_id' => $registration->id,
                'event_participant_id' => $person->id,
                'username' => 'guest' . $person->id,
                'password' => 'secret' . $person->id,
                'expires_on' => now()->addWeek()->toDateString(),
                'provisioned_at' => now(),
            ]);
        }

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.registration.wifi.slips', $event));

        $response->assertOk();

        /*
         | The heading names the entry, and both slips sit under it.
         |
         | Separating the credentials per entry is the only reason this sheet is
         | grouped at all. Grouped on the stored name, every slip on an event that
         | asks for no name would collapse under one "Unassigned" heading and the
         | person at the counter would be back to reading a flat list.
         */
        $response->assertSee('<p class="team">Member 1 &middot; 2</p>', false);
        $response->assertDontSee('Unassigned');
    }

    public function test_the_participant_export_leaves_the_group_name_cell_empty_when_there_is_none(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);
        $registration = $this->registration($event, null);
        $this->member($registration, 1);

        $response = $this->actingAs($this->administrator())
            ->get(route('admin.event.participants.export', ['event' => $event->id]));

        $response->assertOk();

        // Byte order mark stripped: it is there for Excel, not for a test.
        $lines = preg_split('/\r?\n/', trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF")));
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', '\\'), $lines);

        $this->assertSame('Team / Entry', $rows[0][1]);

        // Empty, not the word "null", and the cells after it still line up with
        // their headings rather than having shifted along by one.
        $this->assertSame('', $rows[1][1]);
        $this->assertSame('Grouping', $rows[1][2]);
        $this->assertSame('Member 1', $rows[1][5]);
    }

    public function test_the_team_name_placeholder_names_the_first_person_when_there_is_no_group_name(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);
        $registration = $this->registration($event, null);
        $person = $this->member($registration, 1);

        $template = EventTemplate::create([
            'key' => 'registration.manager',
            'channel' => EventTemplates::CHANNEL_EMAIL,
            'subject' => 'Entry for {{team_name}}',
            'body' => 'Hello {{ team_name }}, your reference is {{reference}}.',
            'is_active' => true,
        ]);

        $rendered = app(EventTemplateRenderer::class)
            ->render($template, $registration->fresh(), [$person]);

        // Neither blanked, which would leave "Entry for" hanging, nor left as the
        // placeholder, which would go out looking broken.
        $this->assertSame('Entry for Member 1', $rendered['subject']);
        $this->assertStringContainsString('Hello Member 1,', $rendered['body']);
        $this->assertStringNotContainsString('team_name', $rendered['body']);
    }

    public function test_the_staff_enquiry_email_names_the_entry_rather_than_calling_it_individual(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);
        $registration = $this->registration($event, null);
        $person = $this->member($registration, 1);

        $message = PlayerMessage::create([
            'event_participant_id' => $person->id,
            'name' => 'Enquirer',
            'email' => 'enquirer@example.test',
            'phone' => '0123456789',
            'message' => 'Please pass this on.',
            'ip_address' => '127.0.0.1',
        ]);

        $body = (new PlayerEnquiryReceived($message, $person->fresh(), 'Member M.'))->render();

        // A group with no name is still a group. Calling it an individual entry
        // would tell the office something that is not true.
        $this->assertStringContainsString('Member 1', $body);
        $this->assertStringNotContainsString('Individual entry', $body);
    }

    public function test_the_staff_enquiry_email_still_says_individual_entry_for_a_solo_registration(): void
    {
        $event = $this->event(Event::MODE_INDIVIDUAL, [
            'min_players' => null,
            'max_players' => null,
        ]);

        $registration = $this->registration($event, null);
        $person = $this->member($registration, 1);

        $message = PlayerMessage::create([
            'event_participant_id' => $person->id,
            'name' => 'Enquirer',
            'email' => 'enquirer@example.test',
            'phone' => '0123456789',
            'message' => 'Please pass this on.',
            'ip_address' => '127.0.0.1',
        ]);

        $body = (new PlayerEnquiryReceived($message, $person->fresh(), 'Member M.'))->render();

        $this->assertStringContainsString('Individual entry', $body);
    }

    public function test_the_payment_page_opens_for_an_entry_with_no_group_name(): void
    {
        $event = $this->event(Event::MODE_GROUPING, ['requires_group_name' => false]);

        $submitted = $this->submit($event, ['participants' => $this->group()]);

        $submitted->assertSessionHasNoErrors();

        // The signed URL the controller handed back, which is the only way in.
        $page = $this->get($submitted->headers->get('Location'));

        $page->assertOk();
        $page->assertSee(EventRegistration::query()->sole()->reference);

        // The row carrying the name is drawn only when there is a name to put in
        // it, so there is no label sitting over an empty space.
        $page->assertDontSee('<span class="font-semibold text-gray-500">Group:</span>', false);
    }
}
