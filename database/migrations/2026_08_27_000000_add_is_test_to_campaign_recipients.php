<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tell a test send apart from a real one.
 *
 * Without this a test was an ordinary recipient row, so pressing "Send Test" on a
 * draft counted towards the campaign totals and, once nothing was left queued,
 * flipped the campaign from draft to sent. The draft was then finished: it could
 * not be edited, could not be sent to its real audience, and could not be deleted,
 * all because somebody checked their own wording first.
 *
 * A column rather than reading the reason text, because behaviour that depends on
 * matching an English sentence breaks the day somebody rewords it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('address');

            // Paired with campaign_id because every read is "the real recipients of
            // this campaign", never tests on their own.
            $table->index(['campaign_id', 'is_test']);
        });

        /*
         | Repair what the old behaviour left behind.
         |
         | A campaign that was really sent has recipients_total written and started_at
         | stamped, both by the send itself. Where neither is set there was never an
         | audience, so every recipient row hanging off it can only have come from a
         | test. That is stronger evidence than the reason text, which the send job
         | overwrites with null the moment a test succeeds.
         */
        $neverSent = DB::table('campaigns')
            ->where('recipients_total', 0)
            ->whereNull('started_at')
            ->pluck('id');

        if ($neverSent->isNotEmpty()) {
            DB::table('campaign_recipients')
                ->whereIn('campaign_id', $neverSent)
                ->update(['is_test' => true]);

            // And the campaign goes back to being the draft it always was.
            DB::table('campaigns')
                ->whereIn('id', $neverSent)
                ->where('status', 'sent')
                ->update([
                    'status' => 'draft',
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'opened_count' => 0,
                    'clicked_count' => 0,
                    'finished_at' => null,
                ]);
        }

        // Older tests on campaigns that were genuinely sent, identified by the reason
        // the controller used to write before this column existed.
        DB::table('campaign_recipients')
            ->where('reason', 'Test send, not part of the audience.')
            ->update(['is_test' => true]);
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'is_test']);
            $table->dropColumn('is_test');
        });
    }
};
