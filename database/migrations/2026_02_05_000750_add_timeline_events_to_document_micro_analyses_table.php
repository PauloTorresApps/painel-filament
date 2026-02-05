<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_micro_analyses', function (Blueprint $table) {
            $table->json('timeline_events')->nullable()->after('processing_time_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_micro_analyses', function (Blueprint $table) {
            $table->dropColumn('timeline_events');
        });
    }
};
