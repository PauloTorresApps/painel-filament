<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove a constraint antiga que incluía is_default = false
        DB::statement('ALTER TABLE ai_prompts DROP CONSTRAINT IF EXISTS unique_default_prompt_per_user_system');

        // Cria uma constraint parcial que só se aplica quando is_default = true
        // Isso permite múltiplos prompts com is_default = false, mas apenas um com is_default = true
        DB::statement('
            CREATE UNIQUE INDEX unique_default_prompt_per_user_system
            ON ai_prompts (user_id, system_id)
            WHERE is_default = true
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove a constraint parcial
        DB::statement('DROP INDEX IF EXISTS unique_default_prompt_per_user_system');

        // Recria a constraint antiga (com o problema)
        DB::statement('
            ALTER TABLE ai_prompts
            ADD CONSTRAINT unique_default_prompt_per_user_system
            UNIQUE (user_id, system_id, is_default)
        ');
    }
};
