<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_files', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('artifact_type');
            $table->date('race_date');
            $table->string('original_filename');
            $table->string('storage_disk');
            $table->text('storage_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->text('source_url');
            $table->timestampTz('downloaded_at');
            $table->timestampsTz();
            $table->unique(['source_system', 'artifact_type', 'race_date', 'sha256'], 'source_files_version_unique');
            $table->index(['race_date', 'artifact_type']);
        });

        DB::statement("ALTER TABLE source_files ADD CONSTRAINT source_files_sha256_check CHECK (sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE source_files ADD CONSTRAINT source_files_size_check CHECK (size_bytes >= 0)');

        Schema::create('kd3_artifact_statuses', function (Blueprint $table) {
            $table->id();
            $table->date('race_date');
            $table->string('artifact_type');
            $table->string('status')->default('pending');
            $table->foreignId('latest_source_file_id')->nullable()->constrained('source_files')->restrictOnDelete();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->smallInteger('last_http_status')->nullable();
            $table->string('last_error_category')->nullable();
            $table->timestampsTz();
            $table->unique(['race_date', 'artifact_type']);
        });

        DB::statement('ALTER TABLE kd3_artifact_statuses ADD CONSTRAINT kd3_artifact_statuses_attempt_count_check CHECK (attempt_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('kd3_artifact_statuses');
        Schema::dropIfExists('source_files');
    }
};
