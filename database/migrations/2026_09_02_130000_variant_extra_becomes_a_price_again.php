<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * price_extra goes back to being price, where zero means free.
 *
 * The surcharge model read a zero as "no extra", so a size set to RM0.00 was
 * charged the add-on price anyway. That is the opposite of what zero is for. A
 * size priced at nothing is a shirt already covered by the event fee, and the
 * public form should show no money against it at all.
 *
 * So the figure on an option is the figure charged for that option. Blank still
 * means "same as the add-on", which keeps one price from being repeated across
 * four sizes. Zero means zero.
 *
 * An option that costs more, such as a 5XL, carries its own full figure rather
 * than a difference. Less convenient to type once, but it cannot be misread, and
 * being misread is what has gone wrong here twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_addon_variants', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('label');
        });

        /*
        | Restore the whole figure: what an option charged under the surcharge was
        | the add-on price plus its extra, so that is what it charges now.
        |
        | Walked in PHP, not UPDATE ... JOIN, because these migrations also run
        | against SQLite in the test suite where that syntax does not exist.
        |
        | One caveat worth stating plainly: a size that was deliberately set to
        | RM0.00 before the surcharge migration was stored as extra 0, and comes
        | back here as the add-on price. Anywhere that ran the surcharge migration
        | and had free sizes will need those zeros typed in again. There is no way
        | to tell that case apart from a size that simply had no extra, and
        | guessing would silently change what somebody is charged.
        */
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

    public function down(): void
    {
        Schema::table('event_addon_variants', function (Blueprint $table) {
            $table->decimal('price_extra', 10, 2)->nullable()->after('price');
        });

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
};
