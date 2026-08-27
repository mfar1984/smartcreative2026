<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One submission. In individual mode it holds a single participant; in
        // manager mode it holds the manager plus their players.
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            // Human readable handle given back to the registrant.
            $table->string('reference', 32)->unique();

            // Snapshot of the event mode at submission time, so changing the
            // event later does not rewrite history.
            $table->string('mode', 20);

            // Used by team based events. Null for individual registrations.
            $table->string('team_name')->nullable();

            // pending | confirmed | cancelled | waitlisted
            $table->string('status', 20)->default('pending')->index();

            // unpaid | pending | paid | failed | refunded
            $table->string('payment_status', 20)->default('unpaid')->index();

            // What the gateway called this purchase, once one exists.
            $table->string('payment_reference')->nullable();

            // Amount owed at submission time: event fee times billable people.
            $table->decimal('amount', 10, 2)->default(0);

            $table->text('notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });

        // Every person named on a registration, manager and player alike.
        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();

            // manager | player
            $table->string('role', 20)->index();

            $table->string('full_name');
            $table->string('ic_number', 32);

            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('postcode', 12)->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('country')->default('Malaysia');

            $table->string('phone', 30);
            $table->string('email');

            $table->string('gender', 20);
            $table->string('race', 40);

            $table->date('date_of_birth')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();

            $table->timestamps();

            // The same identity card cannot appear twice on one registration.
            $table->unique(['event_registration_id', 'ic_number']);
            $table->index('ic_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('event_registrations');
    }
};
