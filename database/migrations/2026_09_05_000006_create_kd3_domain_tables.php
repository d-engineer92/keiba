<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['horses', 'jockeys', 'trainers'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->timestampsTz();
            });
        }

        Schema::create('race_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->string('race_name')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->string('surface_code', 1)->nullable();
            $table->string('course_direction_code', 1)->nullable();
            $table->string('course_code', 1)->nullable();
            $table->unsignedSmallInteger('distance')->nullable();
            $table->string('grade_code', 1)->nullable();
            $table->string('age_condition_code', 1)->nullable();
            $table->string('class_code', 5)->nullable();
            $table->string('weight_condition_code', 2)->nullable();
            $table->unsignedSmallInteger('declared_runner_count');
            $table->timestampsTz();
        });

        Schema::create('race_entry_runners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('horse_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('horse_no');
            $table->unsignedSmallInteger('frame_no')->nullable();
            $table->foreignId('jockey_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('assigned_weight', 4, 1)->nullable();
            $table->string('entry_mark_code', 1)->nullable();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->timestampsTz();
            $table->unique(['race_entry_id', 'horse_id']);
            $table->unique(['race_entry_id', 'horse_no']);
        });

        foreach (['horse_entry_snapshots', 'horse_result_snapshots'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
                $table->foreignId('horse_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('source_record_number');
                $table->string('horse_name')->nullable();
                $table->string('sex_code', 1)->nullable();
                $table->date('birth_date')->nullable();
                $table->string('color_code', 2)->nullable();
                $table->string('breed_code', 2)->nullable();
                $table->foreignId('trainer_id')->nullable()->constrained()->restrictOnDelete();
                $table->timestampsTz();
                $table->unique(['source_file_id', 'horse_id']);
            });
        }

        Schema::create('runner_workouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_entry_runner_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence_no');
            $table->date('training_date')->nullable();
            $table->string('rider')->nullable();
            $table->string('place', 32)->nullable();
            $table->string('course_code', 8)->nullable();
            $table->string('track_condition', 8)->nullable();
            $table->string('clock_8f', 16)->nullable();
            $table->string('clock_7f', 16)->nullable();
            $table->string('clock_6f', 16)->nullable();
            $table->string('clock_5f', 16)->nullable();
            $table->string('clock_4f', 16)->nullable();
            $table->string('clock_3f', 16)->nullable();
            $table->string('clock_1f', 16)->nullable();
            $table->string('position_code', 1)->nullable();
            $table->string('evaluation')->nullable();
            $table->text('exception_text')->nullable();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->timestampsTz();
            $table->unique(['race_entry_runner_id', 'sequence_no']);
        });

        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->string('result_status')->default('official');
            $table->string('weather_code', 1)->nullable();
            $table->string('track_condition_code', 1)->nullable();
            $table->string('pace_code', 1)->nullable();
            $table->unsignedSmallInteger('declared_runner_count');
            $table->unsignedSmallInteger('cancelled_runner_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('race_result_runners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_result_id')->constrained()->restrictOnDelete();
            $table->foreignId('horse_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('horse_no');
            $table->foreignId('jockey_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('finish_position')->nullable();
            $table->string('finish_status_code', 2)->nullable();
            $table->unsignedInteger('finish_time_tenths')->nullable();
            $table->string('margin')->nullable();
            $table->string('passing_order')->nullable();
            $table->decimal('last_3f', 4, 1)->nullable();
            $table->unsignedSmallInteger('body_weight')->nullable();
            $table->smallInteger('body_weight_delta')->nullable();
            $table->decimal('final_odds', 10, 1)->nullable();
            $table->unsignedSmallInteger('popularity')->nullable();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->timestampsTz();
            $table->unique(['race_result_id', 'horse_id']);
            $table->unique(['race_result_id', 'horse_no']);
        });

        Schema::create('race_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->foreignId('horse_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('jockey_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('category_code')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->timestampsTz();
            $table->unique(['source_file_id', 'source_record_number']);
        });

        Schema::create('race_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->string('artifact_type', 2);
            $table->string('comment_type');
            $table->foreignId('race_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('horse_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('comment_text');
            $table->timestampsTz();
            $table->unique(['source_file_id', 'source_record_number', 'comment_type'], 'race_comments_source_unique');
            $table->index(['horse_id', 'comment_type']);
        });

        Schema::create('horse_race_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('horse_id')->constrained()->restrictOnDelete();
            $table->string('history_key', 64);
            $table->date('race_date');
            $table->string('venue_code', 2);
            $table->string('meeting_no', 2)->nullable();
            $table->string('meeting_day', 2)->nullable();
            $table->string('race_no', 2)->nullable();
            $table->string('source_category_code', 1)->nullable();
            $table->string('discipline_code', 1)->nullable();
            $table->string('surface_code', 1)->nullable();
            $table->foreignId('reference_race_id')->nullable()->constrained('races')->restrictOnDelete();
            $table->string('mapping_status');
            $table->unsignedSmallInteger('horse_no')->nullable();
            $table->unsignedSmallInteger('finish_position')->nullable();
            $table->unsignedInteger('finish_time_tenths')->nullable();
            $table->decimal('odds', 10, 1)->nullable();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['horse_id', 'history_key']);
            $table->index(['horse_id', 'race_date']);
            $table->index(['mapping_status', 'race_date']);
        });

        Schema::create('runner_speed_indices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_entry_runner_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_race_id')->constrained('races')->restrictOnDelete();
            $table->foreignId('horse_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('central_flat_run_back');
            $table->decimal('speed_index', 6, 1)->nullable();
            $table->foreignId('reference_race_id')->nullable()->constrained('races')->restrictOnDelete();
            $table->unsignedSmallInteger('actual_run_back')->nullable();
            $table->string('mapping_status');
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_record_number');
            $table->timestampsTz();
            $table->unique(['race_entry_runner_id', 'central_flat_run_back'], 'runner_speed_indices_slot_unique');
        });
        DB::statement('ALTER TABLE runner_speed_indices ADD CONSTRAINT runner_speed_indices_run_back_check CHECK (central_flat_run_back BETWEEN 1 AND 5)');

        Schema::create('race_speed_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('central_flat_run_back');
            $table->unsignedInteger('valid_count');
            $table->unsignedInteger('excluded_count')->default(0);
            $table->decimal('mean', 12, 6)->nullable();
            $table->decimal('median', 12, 6)->nullable();
            $table->decimal('stddev', 12, 6)->nullable();
            $table->decimal('min', 12, 6)->nullable();
            $table->decimal('max', 12, 6)->nullable();
            $table->decimal('mad', 12, 6)->nullable();
            $table->string('calculation_version');
            $table->timestampTz('calculated_at');
            $table->unique(['race_id', 'central_flat_run_back', 'calculation_version'], 'race_speed_statistics_version_unique');
        });

        Schema::create('race_speed_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('runner_speed_index_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('speed_rank')->nullable();
            $table->decimal('percentile', 8, 6)->nullable();
            $table->decimal('zscore', 12, 6)->nullable();
            $table->decimal('deviation_score', 12, 6)->nullable();
            $table->decimal('robust_zscore', 12, 6)->nullable();
            $table->decimal('robust_deviation_score', 12, 6)->nullable();
            $table->boolean('is_outlier')->nullable();
            $table->string('outlier_rule_version')->nullable();
            $table->string('calculation_version');
            $table->timestampsTz();
            $table->unique(['runner_speed_index_id', 'calculation_version'], 'race_speed_metrics_version_unique');
        });

        Schema::create('race_odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->string('odds_phase');
            $table->string('bet_type');
            $table->string('combination_key', 16);
            $table->unsignedSmallInteger('selection_1')->nullable();
            $table->unsignedSmallInteger('selection_2')->nullable();
            $table->unsignedSmallInteger('selection_3')->nullable();
            $table->decimal('odds', 14, 1)->nullable();
            $table->decimal('odds_min', 14, 1)->nullable();
            $table->decimal('odds_max', 14, 1)->nullable();
            $table->unsignedInteger('popularity')->nullable();
            $table->string('status')->nullable();
            $table->timestampsTz();
            $table->unique(['race_id', 'odds_phase', 'bet_type', 'combination_key'], 'race_odds_market_unique');
            $table->index(['race_id', 'bet_type', 'odds_phase']);
        });

        Schema::create('kd3_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_file_id')->constrained()->restrictOnDelete();
            $table->string('importer_version');
            $table->string('parser_version');
            $table->string('spec_version');
            $table->string('status');
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_category')->nullable();
            $table->string('error_entity')->nullable();
            $table->string('error_key')->nullable();
            $table->timestampsTz();
            $table->index(['source_file_id', 'started_at']);
        });
    }

    public function down(): void
    {
        foreach (['kd3_import_runs', 'race_odds', 'race_speed_metrics', 'race_speed_statistics', 'runner_speed_indices', 'horse_race_histories', 'race_comments', 'race_sanctions', 'race_result_runners', 'race_results', 'runner_workouts', 'horse_result_snapshots', 'horse_entry_snapshots', 'race_entry_runners', 'race_entries', 'trainers', 'jockeys', 'horses'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
