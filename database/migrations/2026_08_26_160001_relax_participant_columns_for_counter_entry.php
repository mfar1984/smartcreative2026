<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let a participant exist without the details a counter cannot collect.
     *
     * A substitute player hands over an identity card at the door. That gives a
     * name, a card number, and a phone number if asked. Nobody types a full
     * postal address at a queue, and copying the outgoing player's address onto
     * the person replacing them would be plainly wrong.
     *
     * These columns stay required on the public registration form, which is
     * where someone has the time to fill them in. This only changes what the
     * database will accept, so a counter swap can record who actually turned up
     * instead of being blocked or padded with invented values.
     */
    public function up(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->string('address_line_1')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('gender', 20)->nullable()->change();
            $table->string('race', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('event_participants', function (Blueprint $table) {
            $table->string('address_line_1')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('state')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('gender', 20)->nullable(false)->change();
            $table->string('race', 40)->nullable(false)->change();
        });
    }
};
