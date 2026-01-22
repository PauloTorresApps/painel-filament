<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_analyses', function (Blueprint $table) {
            $table->foreignId('infographic_storyboard_prompt_id')
                ->nullable()
                ->after('legal_opinion_prompt_id')
                ->constrained('ai_prompts')
                ->nullOnDelete();

            $table->foreignId('infographic_html_prompt_id')
                ->nullable()
                ->after('infographic_storyboard_prompt_id')
                ->constrained('ai_prompts')
                ->nullOnDelete();

            $table->string('infographic_status')
                ->nullable()
                ->after('legal_opinion_status');

            $table->longText('infographic_storyboard_json')
                ->nullable()
                ->after('legal_opinion_result');

            $table->longText('infographic_html_result')
                ->nullable()
                ->after('infographic_storyboard_json');

            $table->json('infographic_ai_metadata')
                ->nullable()
                ->after('legal_opinion_ai_metadata');

            $table->string('infographic_error')
                ->nullable()
                ->after('legal_opinion_error');

            $table->integer('infographic_processing_time_ms')
                ->nullable()
                ->after('legal_opinion_processing_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('contract_analyses', function (Blueprint $table) {
            $table->dropForeign(['infographic_storyboard_prompt_id']);
            $table->dropForeign(['infographic_html_prompt_id']);
            $table->dropColumn([
                'infographic_storyboard_prompt_id',
                'infographic_html_prompt_id',
                'infographic_status',
                'infographic_storyboard_json',
                'infographic_html_result',
                'infographic_ai_metadata',
                'infographic_error',
                'infographic_processing_time_ms',
            ]);
        });
    }
};
