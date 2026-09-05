<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kd3_parse_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_file_id')->constrained('source_files')->restrictOnDelete();
            $table->string('parser_version');
            $table->string('spec_version');
            $table->string('status');
            $table->unsignedBigInteger('record_count')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('error_category')->nullable();
            $table->string('error_file')->nullable();
            $table->unsignedBigInteger('error_record_number')->nullable();
            $table->string('error_field')->nullable();
            $table->timestampsTz();
            $table->index(['source_file_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kd3_parse_runs');
    }
};
