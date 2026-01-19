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
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->foreignId('ai_model_id')->nullable()->after('ai_provider')->constrained('ai_models')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->dropForeign(['ai_model_id']);
            $table->dropColumn('ai_model_id');
        });
    }
};
