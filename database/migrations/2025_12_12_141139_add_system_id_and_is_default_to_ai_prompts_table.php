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
            // Adiciona foreign key para systems
            $table->foreignId('system_id')->after('user_id')->constrained()->onDelete('cascade');

            // Adiciona flag de prompt padrão
            $table->boolean('is_default')->default(false)->after('is_active');

            // Cria índice único para garantir apenas um prompt padrão por sistema
            $table->unique(['system_id', 'is_default'], 'unique_default_prompt_per_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->dropUnique('unique_default_prompt_per_system');
            $table->dropForeign(['system_id']);
            $table->dropColumn(['system_id', 'is_default']);
        });
    }
};
