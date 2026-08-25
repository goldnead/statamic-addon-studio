<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history: what happened to an address, when, and on whose say-so.
 *
 * It carries three jobs the state table cannot do. It makes a release
 * defensible — who released a complaint, when, and on what stated grounds. It
 * makes thresholds possible, because individual soft bounces are events here
 * and never suppressions. And it makes webhook redelivery harmless through
 * `dedupe_key`.
 *
 * **`dedupe_key` is nullable and unique, and that combination is intentional.**
 * A unique index does not bind NULL, so any number of rows may carry none —
 * which is exactly right for manual actions, since a second, legitimate release
 * of the same address must not be swallowed as a duplicate of the first. Only
 * provider events, which really can arrive twice, carry a key. The two halves
 * of that sentence are asserted separately in
 * `tests/Feature/NullableUniqueTest.php`, because "the index exists" and "the
 * index bites" are different statements.
 *
 * Sized for InnoDB: `dedupe_key` is a 40-character sha1 in a `varchar(64)`
 * rather than the default 255, so the unique costs 256 bytes instead of 1020.
 * `tests/Unit/IndexKeyLengthTest.php` measures the compiled DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppression_events')) {
            return;
        }

        Schema::create('suppression_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('brand_id')->default(0);
            $table->string('email_normalized');

            // suppressed | released | soft_bounce | reasserted | imported
            $table->string('event_type', 32);
            $table->string('reason', 32)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('message_uuid', 64)->nullable();

            // The CP user (or OS user) behind a manual action. A stable
            // identifier, never a display name — a renamed user must not
            // orphan the record.
            $table->string('actor')->nullable();

            $table->string('dedupe_key', 64)->nullable()->unique('suppev_dedupe_unique');

            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('email_normalized', 'suppev_email_index');
            $table->index(['email_normalized', 'event_type', 'occurred_at'], 'suppev_email_type_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppression_events');
    }
};
