<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An option's price stops replacing the add-on price and starts adding to it.
 *
 * The add-on already carries the price of the thing: a shirt is RM50. What differs
 * by size is not the price, it is the extra, because a 5XL costs more cloth. Asking
 * for the whole figure again on every size meant repeating RM50 four times to say
 * nothing, and it made blank and zero mean two subtly different things that nobody
 * could tell apart on the form.
 *
 * As a surcharge there is no ambiguity left. Blank and zero both mean "no extra",
 * so the option costs whatever the add-on costs. To make the item itself free,
 * price the add-on at zero.
 *
 * Renamed rather than reinterpreted in place. A column still called price that no
 * longer holds a price is how the next person introduces a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_addon_variants', function (Blueprint $table) {
            $table->decimal('price_extra', 10, 2)->nullable()->after('price');
        });

        /*
        | Convert what is stored so nothing already sold changes price.
        |
        | An old value was the whole figure charged for that option, so the extra is
        | whatever it was above the add-on. Clamped at zero because an extra is an
        | addition: an option priced BELOW its add-on cannot be expressed and rises
        | to the add-on price. Nothing in this database is in that state, and saying
        | so here is cheaper than a discount feature nobody asked for.
        |
        | A stored zero previously meant "free". It becomes "no extra", which is the
        | add-on price. That is the point of the change: free is now said once, by
        | pricing the add-on at zero, rather than repeated on every size.
        |
        | Walked in PHP rather than written as one UPDATE ... JOIN. That syntax is
        | MySQL's, and the test suite runs these same migrations against SQLite,
        | where it is a syntax error. There are only ever a handful of options per
        | add-on, so a loop costs nothing and works on both.
        */
        $addonPrices = DB::table('event_addons')->pluck('price', 'id');

        foreach (DB::table('event_addon_variants')->whereNotNull('price')->get(['id', 'event_addon_id', 'price']) as $variant) {
            $base = (float) ($addonPrices[$variant->event_addon_id] ?? 0);

            DB::table('event_addon_variants')
                ->where('id', $variant->id)
                ->update(['price_extra' => max(round((float) $variant->price - $base, 2), 0)]);
        }

        Schema::table('event_addon_variants', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }

    public function down(): void
    {
        Schema::table('event_addon_variants', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('label');
        });

        // Back to a whole figure: the add-on price plus whatever the extra was.
        // Same loop, same reason: this has to run on SQLite too.
        $addonPrices = DB::table('event_addons')->pluck('price', 'id');

        foreach (DB::table('event_addon_variants')->whereNotNull('price_extra')->get(['id', 'event_addon_id', 'price_extra']) as $variant) {
            $base = (float) ($addonPrices[$variant->event_addon_id] ?? 0);

            DB::table('event_addon_variants')
                ->where('id', $variant->id)
                ->update(['price' => round($base + (float) $variant->price_extra, 2)]);
        }

        Schema::table('event_addon_variants', function (Blueprint $table) {
            $table->dropColumn('price_extra');
        });
    }
};
