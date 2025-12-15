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
            // Remove o índice antigo que não incluía user_id
            $table->dropUnique('unique_default_prompt_per_system');

            // Cria o novo índice correto incluindo user_id
            $table->unique(['user_id', 'system_id', 'is_default'], 'unique_default_prompt_per_user_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            // Remove o novo índice
            $table->dropUnique('unique_default_prompt_per_user_system');

            // Recria o índice antigo
            $table->unique(['system_id', 'is_default'], 'unique_default_prompt_per_system');
        });
    }
};
