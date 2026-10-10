<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public contact form.
 *
 * WHY THIS ARRIVES SO LATE, which is the part worth knowing
 *
 * The migration was never written, though the table itself exists on the live
 * database: somebody created it there by hand. So the contact form and the Analytic
 * Reporting card have been working in production all along, and the fault is not a
 * broken site — it is that the schema could not be rebuilt from its own migrations.
 * A clone, a new environment, or a restored backup followed by `migrate` would come
 * up WITHOUT this table, and then ContactController::store() calling
 * ContactMessage::create() and AnalyticReportingController calling
 * ContactMessage::count() would both fail there.
 *
 * It stayed invisible because nothing covered either screen; 1,524 tests passed over
 * the gap, on a test database that genuinely lacked the table. The 2026_09_26
 * player_messages migration even reasons about "a row in contact_messages" and about
 * the analytics screen counting them, so later work assumed the table rather than
 * noticing no migration made it.
 *
 * up() is therefore guarded rather than unconditional — see the note on it. A test
 * on the reporting screen and on the public form ships beside this, so the same kind
 * of hole cannot reopen quietly.
 *
 * THE SHAPE
 *
 * Columns and widths are taken from the two things that already define them, so the
 * database agrees with what the form will accept: App\Models\ContactMessage's
 * $fillable, and StoreContactMessageRequest's rules (name 120, email 190, phone 30,
 * message 3000). `message` is a text column rather than a string because 3,000
 * characters does not fit a default varchar and the limit belongs in validation
 * anyway.
 *
 * Mirrors player_messages, which was modelled on this table's intended shape, so the
 * two read as the siblings they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         | Guarded, because the live database ALREADY HAS this table and a fresh
         | install does not.
         |
         | It was created there by hand at some point, which is why the contact form
         | works in production while no migration accounts for the table. So the
         | hole this migration closes is not a broken live site; it is that the
         | schema could never be rebuilt from scratch. A clone, a new environment or
         | a restored backup followed by `migrate` would come up without it, and the
         | contact form and Analytic Reporting would fail there.
         |
         | Without this guard the migration fails on the very database that most
         | needs the rest of the batch to apply, and `migrate` stops partway with
         | everything after it unrun.
         */
        if (Schema::hasTable('contact_messages')) {
            return;
        }

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 30)->nullable();

            /*
             | Which service the enquiry is about. A key from
             | ContactMessage::SERVICES, validated against that same list, and stored
             | as the key rather than the label so renaming a service on screen does
             | not orphan the rows already filed under it.
             */
            $table->string('service', 40);

            $table->text('message');

            // The only trail available if the form is abused, which is the same
            // reason player_messages keeps it. 45 characters holds an IPv6 address.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            /*
             | The two reads this table gets. Analytic Reporting counts everything and
             | counts the last thirty days, and the office reads newest first; all
             | three are served by created_at. `service` is indexed with it because
             | the breakdown by service is the obvious next question once the totals
             | are on screen.
             */
            $table->index('created_at');
            $table->index(['service', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
