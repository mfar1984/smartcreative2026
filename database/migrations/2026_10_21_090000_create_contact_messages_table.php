<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enquiries from the public contact form.
 *
 * WHY THIS ARRIVES SO LATE, which is the part worth knowing
 *
 * It was never written. The model, the form request, the controller, the mailable,
 * the staff alert and the Analytic Reporting card were all built against this table
 * and none of them could work: ContactController::store() calls
 * ContactMessage::create() and AnalyticReportingController counts
 * ContactMessage::count(), so the public contact form has been failing on submit
 * and Analytic Reporting has been answering 500 for every staff account.
 *
 * It stayed invisible because nothing covered either screen. 1,524 tests passed over
 * it. The 2026_09_26 player_messages migration even reasons about "a row in
 * contact_messages" and about the analytics screen counting them, so the table was
 * assumed by later work rather than noticed as missing. A test on the reporting
 * screen ships beside this migration so the same hole cannot reopen quietly.
 *
 * Analytic Reporting hid the card from a monitor, who is therefore the only account
 * that could open that screen successfully — which is how the fault finally surfaced.
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
