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
            // Tipo do prompt: analysis (Análise de Contrato) ou legal_opinion (Parecer Jurídico)
            // Pode ser null para prompts de outros sistemas que não usam essa distinção
            $table->string('prompt_type')->nullable()->after('system_id');

            // Índice para busca por sistema + tipo + padrão
            $table->index(['system_id', 'prompt_type', 'is_default']);
        });

        // Atualiza prompts existentes do sistema Contratos para tipo 'analysis'
        DB::table('ai_prompts')
            ->join('systems', 'ai_prompts.system_id', '=', 'systems.id')
            ->where('systems.name', 'Contratos')
            ->update(['prompt_type' => 'analysis']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->dropIndex(['system_id', 'prompt_type', 'is_default']);
            $table->dropColumn('prompt_type');
        });
    }
};
