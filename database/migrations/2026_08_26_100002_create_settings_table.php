<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            // Grouping used by the settings screens, e.g. general, backup,
            // maintenance, email, api, payments, sms, telegram.
            $table->string('group')->index();
            $table->text('value')->nullable();
            // Secret values (API keys, tokens, passwords) are stored encrypted.
            // The model decides how to cast based on this flag.
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
