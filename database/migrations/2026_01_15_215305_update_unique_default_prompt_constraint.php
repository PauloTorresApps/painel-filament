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
        // Remove o índice único antigo que impõe apenas um prompt padrão por sistema
        DB::statement('DROP INDEX IF EXISTS unique_default_prompt_per_system');

        // Cria novo índice único que permite um prompt padrão por sistema E tipo
        // Isso permite ter um prompt padrão de "analysis" e outro de "legal_opinion" no mesmo sistema
        DB::statement('CREATE UNIQUE INDEX unique_default_prompt_per_system_type ON ai_prompts (system_id, prompt_type) WHERE (is_default = true AND prompt_type IS NOT NULL)');

        // Para sistemas que não usam prompt_type (prompts antigos), mantém a regra de um padrão por sistema
        DB::statement('CREATE UNIQUE INDEX unique_default_prompt_per_system_no_type ON ai_prompts (system_id) WHERE (is_default = true AND prompt_type IS NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unique_default_prompt_per_system_type');
        DB::statement('DROP INDEX IF EXISTS unique_default_prompt_per_system_no_type');

        // Restaura o índice antigo
        DB::statement('CREATE UNIQUE INDEX unique_default_prompt_per_system ON ai_prompts (system_id) WHERE (is_default = true)');
    }
};
