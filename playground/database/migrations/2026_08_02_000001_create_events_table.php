<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            // Brand scoping is present from the very first migration — this table
            // is never backfilled the way the older addons in the family had to be.
            $table->unsignedBigInteger('brand_id')->index();

            // The public identifier. Ids are never exposed: an ICS UID and a feed
            // URL are handed to strangers, and a sequential integer there tells
            // them how many events exist and lets them walk the list.
            $table->uuid('uuid')->unique();

            $table->string('title');

            // Narrower than the default 255 on purpose: it sits in a unique
            // alongside brand_id, and under utf8mb4 a varchar(255) costs 1020 of
            // InnoDB's 3072 bytes. A slug longer than 191 characters is not a
            // slug. See tests/Unit/IndexKeyLengthTest.php.
            $table->string('slug', 191);

            $table->text('description')->nullable();

            // A free string, not an enum: the option list in config/events.php is
            // what the Control Panel offers, and removing an option there must
            // never orphan the events already carrying it.
            $table->string('type', 64);

            // public | unlisted | private — see Enums\Visibility.
            $table->string('visibility', 16);

            // draft | published — see Enums\EventStatus.
            $table->string('status', 16);

            // The IANA identifier the event's dates are read in. Occurrences may
            // override it; a tour plays in more than one timezone.
            $table->string('timezone', 64);

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Short index names throughout: MySQL caps identifiers at 64 characters
            // and the generated names for these column combinations exceed it.
            $table->unique(['brand_id', 'slug'], 'evt_brand_slug_unique');
            $table->index(['brand_id', 'type', 'status'], 'evt_brand_type_status_idx');
            $table->index(['brand_id', 'visibility', 'status'], 'evt_brand_vis_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
