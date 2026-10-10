<?php

namespace Tests\Feature\Event;

use App\Models\ContactMessage;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Analytic Reporting, and the public contact form that feeds one of its cards.
 *
 * These exist because NOTHING covered either of them, and that is precisely how the
 * contact_messages table came to be missing from the schema while 1,524 tests passed
 * over the gap. The controller calls ContactMessage::count() and the public form
 * calls ContactMessage::create(), so both were answering 500 on the live site and no
 * test said a word.
 *
 * So the assertions here are deliberately dull. The point is not to check a figure;
 * it is that opening the screen as STAFF, and submitting the public form, both have
 * to execute the queries that touch this table. A missing table or a renamed column
 * fails them immediately, which is the thing that was absent before.
 */
class AnalyticReportingScreenTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $slug = Role::SUPER_ADMIN): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::create([
            'name' => 'Reporting Staff',
            'username' => 'reporting-' . uniqid(),
            'email' => uniqid() . '@example.test',
            'password' => 'Reporting-Pass-123!',
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    public function test_a_staff_account_can_open_analytic_reporting(): void
    {
        $staff = $this->staff();

        /*
         | The enquiries card is drawn for everyone who is not a monitor, and drawing
         | it is what runs ContactMessage::count(). This request is the one that was
         | returning 500 on the live site.
         */
        $this->actingAs($staff)
            ->get(route('admin.event.reporting'))
            ->assertOk()
            ->assertSee('Contact Enquiries');
    }

    public function test_the_enquiries_card_counts_what_is_in_the_table(): void
    {
        $staff = $this->staff();

        ContactMessage::create([
            'name' => 'Aminah Binti Yusof',
            'email' => 'aminah@example.test',
            'phone' => '012-3456789',
            'service' => 'event-management',
            'message' => 'We would like a quotation for a school sports day.',
            'ip_address' => '203.0.113.10',
        ]);

        ContactMessage::create([
            'name' => 'Tan Wei Ming',
            'email' => 'tan@example.test',
            'service' => 'digital-creative',
            'message' => 'Asking about the design packages you offer.',
            'ip_address' => '203.0.113.11',
        ]);

        $this->assertSame(2, ContactMessage::count());

        $this->actingAs($staff)
            ->get(route('admin.event.reporting'))
            ->assertOk();
    }

    public function test_the_public_contact_form_saves_an_enquiry(): void
    {
        /*
         | ContactController::store() writes to this table. It has been failing on the
         | live site, and the enquiry was lost every time somebody used the form.
         */
        $this->post(route('contact.store'), [
            'name' => 'Nurul Athirah',
            'email' => 'nurul@example.test',
            'phone' => '019-8765432',
            'service' => 'online-registration',
            'message' => 'I would like to know more about your registration system.',
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_messages', [
            'email' => 'nurul@example.test',
            'service' => 'online-registration',
        ]);
    }

    public function test_a_message_at_the_validated_maximum_fits_the_column(): void
    {
        /*
         | The form accepts 3,000 characters, which is why `message` is a text column
         | and not a string. A varchar would have truncated or thrown here, and the
         | failure would have been a visitor's enquiry silently cut in half.
         */
        $this->post(route('contact.store'), [
            'name' => str_repeat('A', 120),
            'email' => 'long@example.test',
            'service' => 'other',
            'message' => str_repeat('b', 3000),
        ])->assertRedirect();

        $saved = ContactMessage::where('email', 'long@example.test')->firstOrFail();

        $this->assertSame(3000, strlen($saved->message));
        $this->assertSame(120, strlen($saved->name));
    }
}
