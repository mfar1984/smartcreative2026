<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the public registration form insists on, per event.
 *
 * Email and telephone have been unconditionally required in the form for as long as it
 * has existed, so these two default to true: switching them into settings must not
 * quietly relax a rule every event already runs under. What they add is the ability to
 * turn one off for an event where it does not apply — a children's competition where the
 * players have no email of their own, or a walk-in course taken at the counter.
 *
 * Unique contact is the new rule and the reason this migration exists. Nothing stopped a
 * manager from entering his own email and phone for every player on his squad, and the
 * data shows that is exactly what happens: ninety-six competitors on one event share
 * seventy-seven addresses between them. That was harmless while contact detail was only
 * ever used to reach the person who registered. It stopped being harmless the moment
 * every competitor gets their own Wi-Fi login delivered to the address on their row,
 * because nineteen of them would have theirs delivered to somebody else.
 *
 * Off by default, unlike the other two. It would reject registrations that today's rules
 * accept, and a setting that changes what the public form will take should be turned on
 * deliberately rather than arriving with an upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            /*
             | One address and one number per person, across the whole event.
             |
             | Within the event rather than within the squad, because the squad-only
             | version still lets one manager spread his own details across two teams he
             | enters, which produces the same problem one step further out.
             */
            $table->boolean('requires_unique_contact')
                ->default(false)
                ->after('offers_wifi');

            // True, to preserve the behaviour every existing event registered under.
            $table->boolean('requires_email')->default(true)->after('requires_unique_contact');
            $table->boolean('requires_phone')->default(true)->after('requires_email');

            /*
             | Both sides of an identity card, per person.
             |
             | Off by default. It asks every competitor to upload two photographs of a
             | government identity document, which is the most sensitive thing this system
             | would ever hold, so no event acquires the obligation by accident.
             */
            $table->boolean('requires_ic_attachment')->default(false)->after('requires_phone');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'requires_unique_contact',
                'requires_email',
                'requires_phone',
                'requires_ic_attachment',
            ]);
        });
    }
};
