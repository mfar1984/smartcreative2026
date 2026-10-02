<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            // Existing add-ons remain quantity inputs. Radio is opt-in per item.
            $table->string('selection_type', 20)
                ->default('quantity')
                ->after('per_participant');
        });
    }

    public function down(): void
    {
        Schema::table('event_addons', function (Blueprint $table) {
            $table->dropColumn('selection_type');
        });
    }
};
