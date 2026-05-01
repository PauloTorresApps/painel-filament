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
        Schema::table('contract_analyses', function (Blueprint $table) {
            $table->foreignId('legal_opinion_prompt_id')->nullable()->after('prompt_id')->constrained('ai_prompts')->nullOnDelete();
            $table->string('legal_opinion_status')->nullable()->after('status');
            $table->string('legal_opinion_ai_provider')->nullable()->after('ai_provider');
            $table->longText('legal_opinion_result')->nullable()->after('analysis_result');
            $table->string('legal_opinion_error')->nullable()->after('error_message');
            $table->integer('legal_opinion_processing_time_ms')->nullable()->after('processing_time_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_analyses', function (Blueprint $table) {
            $table->dropForeign(['legal_opinion_prompt_id']);
            $table->dropColumn([
                'legal_opinion_prompt_id',
                'legal_opinion_status',
                'legal_opinion_ai_provider',
                'legal_opinion_result',
                'legal_opinion_error',
                'legal_opinion_processing_time_ms',
            ]);
        });
    }
};
