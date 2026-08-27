<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Something extra a registrant may pay for, such as a jersey.
        Schema::create('event_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            // Price per unit. A variant may override it.
            $table->decimal('price', 10, 2)->default(0);

            // A required add-on is charged on every registration.
            $table->boolean('is_required')->default(false);

            // Null means no cap beyond available stock.
            $table->unsignedSmallInteger('max_quantity')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'is_active']);
        });

        // A choice within an add-on, such as a shirt size.
        Schema::create('event_addon_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_addon_id')->constrained()->cascadeOnDelete();

            $table->string('label', 60);

            // Null falls back to the add-on price, so sizes that cost the same
            // do not have to repeat it. Set it when, say, XXL costs more.
            $table->decimal('price', 10, 2)->nullable();

            // Null means unlimited.
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('stock_taken')->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_addon_id', 'label']);
        });

        // What a registration actually ordered.
        Schema::create('event_registration_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_registration_id')->constrained()->cascadeOnDelete();

            // Kept nullable so an order line survives the catalogue entry being
            // removed; the snapshot columns below carry what was bought.
            $table->foreignId('event_addon_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_addon_variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('variant_label', 60)->nullable();

            // Snapshot of the price charged, so later catalogue edits never
            // rewrite an invoice that has already been issued.
            $table->decimal('unit_price', 10, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 10, 2);

            $table->timestamps();

            $table->index('event_registration_id');
        });

        // The total was a single figure. It now needs to show its working.
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->decimal('registration_fee', 10, 2)->default(0)->after('payment_reference');
            $table->decimal('addons_total', 10, 2)->default(0)->after('registration_fee');
        });
    }

    public function down(): void
    {
        Schema::table('event_registrations', function (Blueprint $table) {
            $table->dropColumn(['registration_fee', 'addons_total']);
        });

        Schema::dropIfExists('event_registration_addons');
        Schema::dropIfExists('event_addon_variants');
        Schema::dropIfExists('event_addons');
    }
};
