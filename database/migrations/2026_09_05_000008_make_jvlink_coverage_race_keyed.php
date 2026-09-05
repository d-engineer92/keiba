<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jvlink_backfill_coverages', function (Blueprint $table) {
            $table->dropUnique('jv_backfill_coverage_unique');
            $table->string('source_race_key', 12)->nullable()->after('id');
            $table->string('venue_code', 2)->nullable()->after('coverage_date');
            $table->unsignedSmallInteger('race_no')->nullable()->after('venue_code');
            $table->unique(['source_race_key', 'data_kind'], 'jv_backfill_coverage_race_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('jvlink_backfill_coverages', function (Blueprint $table) {
            $table->dropUnique('jv_backfill_coverage_race_key_unique');
            $table->dropColumn(['source_race_key', 'venue_code', 'race_no']);
            $table->unique(['coverage_date', 'race_id', 'data_kind'], 'jv_backfill_coverage_unique');
        });
    }
};
