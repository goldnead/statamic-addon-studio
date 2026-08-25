<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();

            // Brand scoping is present from the very first migration — this table
            // is never backfilled the way the older addons had to be.
            $table->unsignedBigInteger('brand_id')->index();

            // Two independent idempotency keys. `event_id` guards against the same
            // physical event being delivered twice (webhook retry, job retry);
            // `dedupe_key` guards against the same *fact* being recorded twice by
            // different producers.
            $table->uuid('event_id')->unique();
            $table->string('event_type')->index();

            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->uuid('contact_uuid')->nullable();
            $table->string('user_id')->nullable();
            $table->string('anonymous_id')->nullable();
            $table->string('session_id')->nullable();

            $table->string('source')->nullable();

            // Narrower than the default 255 on purpose. Both go into an index,
            // and under utf8mb4 a varchar(255) costs 1020 bytes of InnoDB's
            // 3072 — two of them made `act_subject_idx` the widest key in this
            // addon by a wide margin. Neither column needs the room: subject_type
            // holds a class name this addon writes itself, subject_id a database
            // identifier (an integer, a UUID, a Statamic ID). See
            // tests/Unit/IndexKeyLengthTest.php.
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 128)->nullable();

            $table->string('dedupe_key')->nullable();

            $table->json('properties')->nullable();
            $table->json('context')->nullable();

            // Set once the personal fields have been stripped, so a repeated
            // anonymisation run is a no-op rather than a fresh sweep.
            $table->boolean('anonymized')->default(false)->index();

            $table->timestamp('occurred_at')->index();
            $table->timestamp('received_at');

            // Short index names throughout: MySQL caps identifiers at 64 chars and
            // the generated names for these column combinations exceed it.
            $table->unique(['brand_id', 'dedupe_key'], 'act_brand_dedupe_unique');
            $table->index(['brand_id', 'event_type', 'occurred_at'], 'act_brand_type_time_idx');
            $table->index(['brand_id', 'contact_uuid'], 'act_brand_contact_idx');
            $table->index(['brand_id', 'user_id'], 'act_brand_user_idx');
            $table->index(['brand_id', 'anonymous_id'], 'act_brand_anon_idx');
            // Brand-led like every other index here: each read runs under the
            // brand scope, so an index starting at subject_type could not serve
            // one. 1284 bytes.
            $table->index(['brand_id', 'subject_type', 'subject_id'], 'act_brand_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
