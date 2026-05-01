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
        // Altera o default da coluna ai_provider para 'openrouter'
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->string('ai_provider', 50)->default('openrouter')->change();
        });

        // Atualiza registros existentes com providers antigos para 'openrouter'
        DB::table('ai_prompts')
            ->whereIn('ai_provider', ['gemini', 'openai', 'deepseek'])
            ->update(['ai_provider' => 'openrouter']);

        // Desativa modelos de providers antigos na tabela ai_models
        DB::table('ai_models')
            ->whereIn('provider', ['gemini', 'openai', 'deepseek'])
            ->update(['is_active' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->string('ai_provider', 50)->default('gemini')->change();
        });
    }
};
