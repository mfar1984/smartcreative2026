<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Wi-Fi login per competitor, for one day.
 *
 * Issued here and pulled by the router, never pushed to it. The alternative was opening
 * the router's management interface to the internet so this application could reach in
 * and create accounts, and that trades the security of the one box on the network whose
 * compromise takes everything with it for the convenience of a single form. So the
 * router asks us, on a schedule, over a connection it opens itself.
 *
 * Because of that the rows here are the authority and the router holds a copy. That is
 * why `provisioned_at` is separate from `created_at`: a credential can exist, be
 * printed, and still not work, simply because nothing has fetched it yet. Keeping the
 * two apart is what lets the admin screen say which of those a competitor is looking at
 * instead of leaving somebody to guess at a login that is correct but not yet live.
 *
 * One row per person, not per squad. A shared login would be refused for everybody
 * after the first, since the hotspot allows one session per account unless told
 * otherwise, and it would also defeat the per-device fairness the venue's queueing is
 * built on: five phones behind one name look like one claimant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wifi_credentials', function (Blueprint $table) {
            $table->id();

            /*
             | The event is carried even though it could be reached through the
             | registration.
             |
             | Every question asked of this table is asked per event: issue for this
             | event, export this event's script, wipe this event afterwards. Going
             | through registrations for all of those means a join on every one, and the
             | export runs while a venue full of people is waiting on it.
             */
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();

            /*
             | One credential per person, enforced rather than trusted.
             |
             | Issuing runs more than once by design: when a registration is paid, and
             | again whenever somebody presses the backfill for an event that was
             | switched on after the fact. Without this constraint the second run would
             | quietly hand the same competitor a second login and the first one would
             | stop being the one on their slip.
             */
            $table->foreignId('event_participant_id')->unique()->constrained()->cascadeOnDelete();

            /*
             | Unique across the whole table, not per event.
             |
             | The router keeps one flat list of hotspot users. Two events provisioning
             | onto the same router with per-event uniqueness would collide there, and
             | the second import would fail on a name that already exists.
             */
            $table->string('username', 40)->unique();

            /*
             | Held in the clear as far as the application is concerned, encrypted at
             | rest by the model's cast.
             |
             | It cannot be hashed. The router needs the actual password to create the
             | account, and the competitor needs to be told what to type. Encryption is
             | therefore the only protection available, and it is worth having: a stolen
             | database dump is the realistic threat, and these rows would otherwise be
             | a list of working logins.
             */
            $table->text('password');

            /*
             | The day it stops working, which is the day of the event.
             |
             | This is the real defence rather than the secrecy of the export URL. A
             | credential that leaks is worth nothing tomorrow, which is a far more
             | dependable guarantee than hoping a link was never forwarded.
             */
            $table->date('expires_on');

            // When the router last took a copy. Null means the login is correct but
            // will not work yet, which is a different problem from a wrong password.
            $table->timestamp('provisioned_at')->nullable();

            // When the competitor was told. Null means issued but nobody knows it.
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            // The export reads one event's unexpired rows, and the admin screen reads
            // the same set ordered by who has not been told yet.
            $table->index(['event_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wifi_credentials');
    }
};
