<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->restrictOnDelete();
            $table->date('race_date')->index();
            $table->smallInteger('meeting_no')->nullable();
            $table->smallInteger('meeting_day')->nullable();
            $table->string('status')->default('scheduled');
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('source_updated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['race_date', 'venue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_calendars');
    }
};
