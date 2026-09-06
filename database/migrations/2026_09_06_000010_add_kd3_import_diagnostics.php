<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kd3_import_runs', function (Blueprint $table) {
            $table->string('error_file')->nullable()->after('error_key');
            $table->unsignedBigInteger('error_record_number')->nullable()->after('error_file');
            $table->unsignedBigInteger('error_byte_offset')->nullable()->after('error_record_number');
            $table->string('error_field')->nullable()->after('error_byte_offset');
            $table->text('error_message')->nullable()->after('error_field');
        });
    }

    public function down(): void
    {
        Schema::table('kd3_import_runs', function (Blueprint $table) {
            $table->dropColumn([
                'error_file',
                'error_record_number',
                'error_byte_offset',
                'error_field',
                'error_message',
            ]);
        });
    }
};
