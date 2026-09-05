<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jvlink_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_event_id')->unique();
            $table->string('event_type');
            $table->string('source_data_spec')->nullable();
            $table->string('source_record_type', 8)->nullable();
            $table->timestampTz('source_published_at')->nullable();
            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampTz('received_at');
            $table->char('payload_sha256', 64);
            $table->timestampsTz();
            $table->index(['event_type', 'captured_at']);
        });

        Schema::create('race_odds_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jvlink_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->string('source_kind');
            $table->timestampTz('snapshot_at')->nullable();
            $table->timestampsTz();
            $table->index(['race_id', 'snapshot_at']);
        });

        Schema::create('race_odds_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('race_odds_snapshots')->restrictOnDelete();
            $table->string('bet_type');
            $table->unsignedSmallInteger('horse_no');
            $table->foreignId('horse_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('odds', 14, 1)->nullable();
            $table->decimal('odds_min', 14, 1)->nullable();
            $table->decimal('odds_max', 14, 1)->nullable();
            $table->string('status')->nullable();
            $table->timestampsTz();
            $table->unique(['snapshot_id', 'bet_type', 'horse_no'], 'jv_odds_snapshot_item_unique');
            $table->index(['horse_id', 'bet_type']);
        });
        DB::statement("ALTER TABLE race_odds_snapshot_items ADD CONSTRAINT jv_odds_bet_type_check CHECK (bet_type IN ('win', 'place'))");

        Schema::create('runner_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jvlink_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('horse_no');
            $table->foreignId('horse_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status_type');
            $table->string('reason_code')->nullable();
            $table->timestampsTz();
            $table->index(['race_id', 'horse_no']);
        });
        DB::statement("ALTER TABLE runner_status_events ADD CONSTRAINT runner_status_type_check CHECK (status_type IN ('cancelled', 'excluded'))");

        Schema::create('jockey_change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jvlink_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('horse_no');
            $table->foreignId('horse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('old_jockey_id')->nullable()->constrained('jockeys')->restrictOnDelete();
            $table->foreignId('new_jockey_id')->nullable()->constrained('jockeys')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['race_id', 'horse_no']);
        });

        Schema::create('body_weight_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jvlink_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('horse_no');
            $table->foreignId('horse_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('body_weight')->nullable();
            $table->smallInteger('body_weight_delta')->nullable();
            $table->string('source_status_code')->nullable();
            $table->timestampsTz();
            $table->index(['race_id', 'horse_no']);
        });

        Schema::create('weather_track_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jvlink_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('race_calendar_id')->constrained()->restrictOnDelete();
            $table->foreignId('venue_id')->constrained()->restrictOnDelete();
            $table->string('change_type')->nullable();
            $table->string('weather')->nullable();
            $table->string('turf_condition')->nullable();
            $table->string('dirt_condition')->nullable();
            $table->timestampsTz();
            $table->index(['race_calendar_id', 'jvlink_event_id']);
        });

        Schema::create('jvlink_backfill_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_run_id')->unique();
            $table->date('requested_from');
            $table->date('requested_to');
            $table->date('actual_min_date')->nullable();
            $table->date('actual_max_date')->nullable();
            $table->string('status');
            $table->unsignedInteger('races_requested')->default(0);
            $table->unsignedInteger('races_found')->default(0);
            $table->unsignedInteger('snapshots_inserted')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_category')->nullable();
            $table->timestampsTz();
        });

        Schema::create('jvlink_backfill_coverages', function (Blueprint $table) {
            $table->id();
            $table->date('coverage_date');
            $table->foreignId('race_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('data_kind');
            $table->string('status');
            $table->timestampTz('first_snapshot_at')->nullable();
            $table->timestampTz('last_snapshot_at')->nullable();
            $table->unsignedInteger('snapshot_count')->default(0);
            $table->timestampTz('last_checked_at');
            $table->timestampsTz();
            $table->unique(['coverage_date', 'race_id', 'data_kind'], 'jv_backfill_coverage_unique');
            $table->index(['data_kind', 'status', 'coverage_date']);
        });
    }

    public function down(): void
    {
        foreach (['jvlink_backfill_coverages', 'jvlink_backfill_runs', 'weather_track_events', 'body_weight_snapshots', 'jockey_change_events', 'runner_status_events', 'race_odds_snapshot_items', 'race_odds_snapshots', 'jvlink_events'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
