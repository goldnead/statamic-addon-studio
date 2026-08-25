<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('brand_id')->index();

            $table->unsignedBigInteger('event_id');

            // Public identifier: it is the ICS UID and the segment of the
            // per-occurrence download URL.
            $table->uuid('uuid')->unique();

            // Always UTC. The event (or this row) carries the IANA zone the
            // instant is *read* in — a date belongs at its place, not at the
            // viewer's. Storing local time instead makes every DST boundary a
            // silent one-hour bug.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // An all-day date has no time of day. starts_at then holds local
            // midnight of the effective zone, converted to UTC, and the ICS
            // writer emits VALUE=DATE rather than a timestamp.
            $table->boolean('all_day')->default(false);

            // Null means: use the event's zone. Set only when this date happens
            // somewhere else.
            $table->string('timezone', 64)->nullable();

            // scheduled | cancelled — see Enums\OccurrenceStatus. A cancelled
            // occurrence is never deleted: subscribers who already imported it
            // need the cancellation to reach them, which requires the same UID.
            $table->string('status', 16);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            // Location. At least one of venue_name / online_url is required —
            // enforced in Occurrence::assertLocatable(), because a CHECK
            // constraint spanning two nullable columns is not portable and
            // SQLite would not enforce it anyway.
            $table->string('venue_name', 191)->nullable();
            $table->string('venue_address')->nullable();
            $table->string('venue_city', 191)->nullable();
            $table->string('venue_country', 2)->nullable();
            $table->string('online_url', 512)->nullable();

            // RFC 5545 SEQUENCE. Bumped on every reschedule so a calendar client
            // that already holds this UID accepts the newer version instead of
            // keeping the stale one.
            $table->unsignedInteger('sequence')->default(0);

            $table->timestamps();

            $table->index(['brand_id', 'event_id', 'starts_at'], 'evtocc_brand_event_start_idx');
            $table->index(['brand_id', 'status', 'starts_at'], 'evtocc_brand_status_start_idx');

            $table->foreign('event_id', 'evtocc_event_fk')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
    }
};
