<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('races', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_calendar_id')->constrained('race_calendars')->restrictOnDelete();
            $table->smallInteger('race_no');
            $table->string('name')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->string('status')->default('scheduled');
            $table->timestampsTz();
            $table->unique(['race_calendar_id', 'race_no']);
        });

        DB::statement('ALTER TABLE races ADD CONSTRAINT races_race_no_positive CHECK (race_no > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('races');
    }
};
