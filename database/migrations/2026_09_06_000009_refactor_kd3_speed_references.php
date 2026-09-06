<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Use the same transaction-scoped lock as Kd3DomainImporter so DDL never races an
        // in-flight KD3 domain transaction in fresh or secondary environments.
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('kd3-domain-import'))");

        Schema::table('race_results', function (Blueprint $table) {
            $table->string('source_category_code', 1)->nullable()->after('result_status');
            $table->string('discipline_code', 1)->nullable()->after('source_category_code');
        });

        Schema::table('race_result_runners', function (Blueprint $table) {
            $table->string('cancellation_type_code', 1)->nullable()->after('finish_status_code');
            $table->index(['horse_id', 'race_result_id'], 'race_result_runners_horse_result_index');
        });

        // Fixed-width KD3 has five physical slots, but blank slots are not domain facts.
        // Remove old placeholder rows before making the source value mandatory.
        DB::statement('DELETE FROM race_speed_metrics WHERE runner_speed_index_id IN (SELECT id FROM runner_speed_indices WHERE speed_index IS NULL)');
        DB::statement('DELETE FROM runner_speed_indices WHERE speed_index IS NULL');

        Schema::table('runner_speed_indices', function (Blueprint $table) {
            $table->dropForeign(['target_race_id']);
            $table->dropForeign(['horse_id']);
            $table->dropForeign(['reference_race_id']);
        });

        Schema::table('runner_speed_indices', function (Blueprint $table) {
            $table->dropColumn(['target_race_id', 'horse_id', 'reference_race_id', 'actual_run_back', 'mapping_status']);
        });
        DB::statement('ALTER TABLE runner_speed_indices ALTER COLUMN speed_index SET NOT NULL');

        Schema::create('runner_speed_index_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('runner_speed_index_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reference_race_result_runner_id')->constrained('race_result_runners')->restrictOnDelete();
            $table->string('resolver_version');
            $table->timestampTz('resolved_at');
            $table->timestampsTz();
        });

        // kol_uma past-race blocks are presentation snapshots. Canonical race/result rows are the source of truth.
        Schema::dropIfExists('horse_race_histories');
    }

    public function down(): void
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('kd3-domain-import'))");

        Schema::dropIfExists('runner_speed_index_references');

        Schema::table('runner_speed_indices', function (Blueprint $table) {
            $table->foreignId('target_race_id')->nullable()->after('race_entry_runner_id');
            $table->foreignId('horse_id')->nullable()->after('target_race_id');
            $table->foreignId('reference_race_id')->nullable()->after('speed_index');
            $table->unsignedSmallInteger('actual_run_back')->nullable()->after('reference_race_id');
            $table->string('mapping_status')->nullable()->after('actual_run_back');
        });

        DB::statement(<<<'SQL'
            UPDATE runner_speed_indices rsi
            SET target_race_id = re.race_id,
                horse_id = rer.horse_id,
                mapping_status = 'unresolved'
            FROM race_entry_runners rer
            JOIN race_entries re ON re.id = rer.race_entry_id
            WHERE rer.id = rsi.race_entry_runner_id
        SQL);
        DB::statement('ALTER TABLE runner_speed_indices ALTER COLUMN target_race_id SET NOT NULL');
        DB::statement('ALTER TABLE runner_speed_indices ALTER COLUMN horse_id SET NOT NULL');
        DB::statement('ALTER TABLE runner_speed_indices ALTER COLUMN mapping_status SET NOT NULL');
        DB::statement('ALTER TABLE runner_speed_indices ALTER COLUMN speed_index DROP NOT NULL');

        Schema::table('runner_speed_indices', function (Blueprint $table) {
            $table->foreign('target_race_id')->references('id')->on('races')->restrictOnDelete();
            $table->foreign('horse_id')->references('id')->on('horses')->restrictOnDelete();
            $table->foreign('reference_race_id')->references('id')->on('races')->restrictOnDelete();
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

        Schema::table('race_result_runners', function (Blueprint $table) {
            $table->dropIndex('race_result_runners_horse_result_index');
            $table->dropColumn('cancellation_type_code');
        });

        Schema::table('race_results', function (Blueprint $table) {
            $table->dropColumn(['source_category_code', 'discipline_code']);
        });
    }
};
