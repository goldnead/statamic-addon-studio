<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current state: the table the gate reads before anything is sent.
 *
 * Two decisions are baked into the columns and both are load-bearing.
 *
 * **`brand_id` is NOT NULL with a default of 0.** 0 means "every brand". The
 * obvious alternative — NULL for global — cannot be used, because MySQL treats
 * NULLs as distinct inside a unique index: two global rows for the same address
 * would both be accepted and the table would quietly grow duplicates of the one
 * fact it exists to state once. `tests/Feature/NullableUniqueTest.php` writes
 * that second row and requires the database to refuse it.
 *
 * **Nothing is ever deleted.** A release sets `released_at` and the gate filters
 * on it. The prior implementation in the FamilyStack backend deleted the row
 * instead, and a delete that missed left the address blocked with no record of
 * why — the defect this shape exists to make impossible.
 *
 * There is no foreign key to a message table. This package is a foundation: it
 * has no idea whether the host installed statamic-marketing, so provider and
 * message identifiers are carried as plain strings and correlated by the addon
 * that owns those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppressions')) {
            return;
        }

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // 0 = global (applies to every brand). Any other value = that brand
            // only. Deliberately NOT nullable — see the class docblock.
            $table->unsignedBigInteger('brand_id')->default(0);

            $table->string('email_normalized');
            $table->string('reason', 32);
            $table->string('source', 64)->nullable();

            // Correlation to whatever the sending addon calls a message. Plain
            // strings, no constraint: this package does not know that table.
            $table->string('provider_message_id')->nullable();
            $table->string('message_uuid', 64)->nullable();

            $table->timestamp('suppressed_at');
            $table->timestamp('expires_at')->nullable();   // NULL = permanent

            // Release audit trail. Nullable at the column level because an
            // unreleased row has none of them. For reason = complaint the
            // service treats all three as mandatory and refuses a release
            // without them; a conditional NOT NULL is not portable across the
            // SQLite and MySQL targets this package supports, so the invariant
            // lives in SuppressionService and in tests rather than in DDL.
            $table->timestamp('released_at')->nullable();
            $table->string('released_by')->nullable();
            $table->string('release_reason')->nullable();

            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // One row per (brand, address). A global hard bounce (brand_id = 0)
            // and a brand-scoped complaint (brand_id = 3) coexist as two rows,
            // because they are different facts with different reversibility.
            $table->unique(['brand_id', 'email_normalized'], 'supp_brand_email_unique');
            $table->index('email_normalized', 'supp_email_index');
            $table->index(['reason', 'expires_at'], 'supp_reason_expiry_index');
            $table->index('brand_id', 'supp_brand_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
