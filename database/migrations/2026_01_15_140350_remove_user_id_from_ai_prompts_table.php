<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Remover o índice único parcial existente (baseado em user_id, system_id)
        DB::statement('DROP INDEX IF EXISTS unique_default_prompt_per_user_system');

        // 2. Remover a foreign key e a coluna user_id
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        // 3. Criar novo índice único parcial apenas por system_id (um prompt padrão por sistema)
        DB::statement('
            CREATE UNIQUE INDEX unique_default_prompt_per_system
            ON ai_prompts (system_id)
            WHERE is_default = true
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Remover o novo índice
        DB::statement('DROP INDEX IF EXISTS unique_default_prompt_per_system');

        // 2. Adicionar a coluna user_id de volta
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
        });

        // 3. Recriar o índice único parcial original
        DB::statement('
            CREATE UNIQUE INDEX unique_default_prompt_per_user_system
            ON ai_prompts (user_id, system_id)
            WHERE is_default = true
        ');
    }
};
