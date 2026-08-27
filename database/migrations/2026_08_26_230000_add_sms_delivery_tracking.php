<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room to record what happened to a text message after we let go of it.
     *
     * Accepted by a gateway and delivered to a handset are different facts. Until
     * now only the first was recorded, and the screens had to describe it as
     * "sent" because that was all they knew. A phone switched off, out of credit
     * or simply wrong all look identical from our side.
     *
     * Deliberately separate columns rather than more values on `status`. Keeping
     * both means a report can say "we handed over 400 and 380 arrived", which is
     * the useful sentence; collapsing them would lose the numerator or the
     * denominator.
     */
    public function up(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            // The gateway's own id for the message. Indexed because the delivery
            // report arrives carrying nothing else to find the row by.
            $table->string('provider_message_id', 120)->nullable()->after('recipient')->index();

            // The gateway's status group: DELIVERED, UNDELIVERABLE, EXPIRED,
            // REJECTED, PENDING. Stored as given rather than mapped, so a value
            // nobody anticipated is still readable.
            $table->string('delivery_status', 40)->nullable()->after('provider_message_id');
            $table->string('delivery_detail')->nullable()->after('delivery_status');
            $table->timestamp('delivered_at')->nullable()->after('delivery_detail');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('provider_message_id', 120)->nullable()->after('address')->index();
            $table->string('delivery_status', 40)->nullable()->after('provider_message_id');
            $table->string('delivery_detail')->nullable()->after('delivery_status');
            $table->timestamp('delivered_at')->nullable()->after('delivery_detail');
        });
    }

    public function down(): void
    {
        Schema::table('event_notifications', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'delivery_status', 'delivery_detail', 'delivered_at']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'delivery_status', 'delivery_detail', 'delivered_at']);
        });
    }
};
