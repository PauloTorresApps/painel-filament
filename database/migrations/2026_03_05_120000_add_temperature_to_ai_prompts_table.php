<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->decimal('temperature', 3, 2)->nullable()->after('analysis_strategy');
        });

        DB::table('ai_prompts')
            ->whereIn('prompt_type', ['final_opinion', 'legal_opinion'])
            ->update(['temperature' => 0.4]);

        DB::table('ai_prompts')
            ->whereNull('temperature')
            ->update(['temperature' => 0.3]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->dropColumn('temperature');
        });
    }
};
