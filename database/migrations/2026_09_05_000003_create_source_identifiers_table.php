<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_identifiers', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('entity_type');
            // Generic mapping spans multiple entity tables; no single physical FK is valid.
            $table->bigInteger('entity_id');
            $table->string('identifier_type');
            $table->string('identifier_value');
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['source_system', 'entity_type', 'identifier_type', 'identifier_value'],
                'source_identifiers_external_unique',
            );
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_identifiers');
    }
};
