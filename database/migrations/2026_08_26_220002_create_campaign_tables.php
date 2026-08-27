<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        | Reusable wording, separate from event_templates on purpose.
        |
        | Those answer fixed moments: a registration arrives, a payment clears.
        | The set of them is decided by code. These are written when somebody has
        | something to say, and there can be any number of them.
        */
        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel', 20)->index();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('channel', 20)->index();

            // Which template it started from, for reference only.
            $table->foreignId('campaign_template_id')->nullable()->constrained()->nullOnDelete();

            /*
            | The wording as it went out, copied rather than read back through the
            | template. Editing a template next month must not rewrite what was
            | said last month: a report is worthless if the message it reports on
            | can change underneath it.
            */
            $table->string('subject')->nullable();
            $table->text('body');

            // all | event | paid | attended | enquiries
            $table->string('audience_type', 20);
            $table->foreignId('audience_event_id')->nullable()->constrained('events')->nullOnDelete();

            // draft | sending | sent | cancelled
            $table->string('status', 20)->default('draft')->index();

            /*
            | Counters, kept on the row rather than counted from recipients each
            | time. A report over a campaign of several thousand would otherwise
            | aggregate the whole recipient table on every page load.
            */
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            // Nulled rather than cascaded: deleting a contact must not erase the
            // record that a campaign was sent to them.
            $table->foreignId('campaign_contact_id')->nullable()->constrained()->nullOnDelete();

            // The address as it was actually sent to, so the record survives the
            // contact later changing their email.
            $table->string('address');

            // queued | sent | failed | skipped
            $table->string('status', 20)->default('queued')->index();
            $table->text('reason')->nullable();

            /*
            | Identifies this one send in a tracking pixel, a click and an
            | unsubscribe link. Per recipient rather than per contact so a report
            | can say which campaign was opened, not just that somebody opened
            | something.
            */
            $table->string('token', 64)->unique();

            $table->timestamp('sent_at')->nullable();

            // First open, and how many times. Both, because "opened once" and
            // "opened eleven times" are different pieces of news.
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);

            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);

            $table->timestamp('unsubscribed_at')->nullable();

            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });

        /*
        | Every link found in a campaign body, stored before sending.
        |
        | This exists so a click can be redirected by id. A tracker that took the
        | destination from the request would be an open redirect: anyone could
        | hand out a link on this domain that lands on a site of their choosing,
        | which is exactly the shape of a phishing attack. Resolving through this
        | table means the only reachable destinations are ones that were in the
        | message.
        */
        Schema::create('campaign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('clicked_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['campaign_link_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_link_clicks');
        Schema::dropIfExists('campaign_links');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('campaign_templates');
    }
};
