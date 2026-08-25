<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The other half of the fix.
 *
 * Correcting the create-migration reaches new installs only: every host that
 * already ran `2026_01_01_000001_create_activities_table` has it recorded and
 * will never look at it again. This one brings those hosts to the same schema.
 *
 * What changes: `act_subject_idx` spanned `(subject_type, subject_id)`, two
 * `varchar(255)` columns, which under utf8mb4 is 2040 of InnoDB's 3072 bytes —
 * the widest key in this addon, two thirds of the limit, and the only composite
 * index that did not lead with `brand_id`. It is replaced by
 * `act_brand_subject_idx` on `(brand_id, subject_type, subject_id)` with the two
 * columns narrowed to what they actually hold: 1284 bytes.
 *
 * Idempotent: a second run finds the new index already in place and stops.
 */
return new class extends Migration
{
    private const SUBJECT_TYPE_MAX = 191;

    private const SUBJECT_ID_MAX = 128;

    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        if (Schema::hasIndex('activities', 'act_brand_subject_idx')) {
            return;
        }

        $this->refuseToTruncate();

        if (Schema::hasIndex('activities', 'act_subject_idx')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->dropIndex('act_subject_idx');
            });
        }

        Schema::table('activities', function (Blueprint $table) {
            $table->string('subject_type', self::SUBJECT_TYPE_MAX)->nullable()->change();
            $table->string('subject_id', self::SUBJECT_ID_MAX)->nullable()->change();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->index(['brand_id', 'subject_type', 'subject_id'], 'act_brand_subject_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('activities') || ! Schema::hasIndex('activities', 'act_brand_subject_idx')) {
            return;
        }

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('act_brand_subject_idx');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->change();
            $table->string('subject_id')->nullable()->change();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id'], 'act_subject_idx');
        });
    }

    /**
     * The ledger is append-only, so nothing here may quietly shorten a value
     * that is already recorded. On MySQL in strict mode the shrink would fail
     * anyway, but on SQLite it would appear to succeed while the two engines
     * drifted apart — which is the exact failure mode this release exists to
     * close. Better to stop with an explanation than to leave that difference
     * behind.
     */
    private function refuseToTruncate(): void
    {
        $offenders = DB::table('activities')
            ->selectRaw('id, LENGTH(subject_type) AS type_length, LENGTH(subject_id) AS id_length')
            ->whereRaw('LENGTH(subject_type) > ?', [self::SUBJECT_TYPE_MAX])
            ->orWhereRaw('LENGTH(subject_id) > ?', [self::SUBJECT_ID_MAX])
            ->limit(5)
            ->get();

        if ($offenders->isEmpty()) {
            return;
        }

        throw new RuntimeException(
            'activities: '.$offenders->count().' or more rows carry a subject_type longer than '
            .self::SUBJECT_TYPE_MAX.' or a subject_id longer than '.self::SUBJECT_ID_MAX
            .' characters and would be truncated by this migration (ids: '
            .$offenders->pluck('id')->implode(', ').'). The ledger is append-only, so nothing is '
            .'shortened automatically. Shorten the producer that writes these values, or widen the '
            .'limits in this migration and in the create-migration together — and re-measure with '
            .'tests/Unit/IndexKeyLengthTest.php, because the index has to stay under 1536 bytes.'
        );
    }
};
