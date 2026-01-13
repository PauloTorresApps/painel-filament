<?php

namespace App\Console\Commands;

use App\Models\AiPrompt;
use Illuminate\Console\Command;

class SetPromptStrategy extends Command
{
    protected $signature = 'prompt:set-strategy {prompt_id?} {strategy?}';

    protected $description = 'Define a estratégia de análise para um prompt de IA';

    public function handle()
    {
        // Lista os prompts disponíveis
        $prompts = AiPrompt::with('system', 'user')->get();

        if ($prompts->isEmpty()) {
            $this->error('Nenhum prompt encontrado no sistema.');
            return 1;
        }

        // Mostra lista de prompts
        $this->info('Prompts disponíveis:');
        $this->info('');

        $promptsTable = [];
        foreach ($prompts as $prompt) {
            $promptsTable[] = [
                'ID' => $prompt->id,
                'Título' => $prompt->title,
                'Sistema' => $prompt->system->name ?? 'N/A',
                'Usuário' => $prompt->user->name ?? 'N/A',
                'Estratégia Atual' => $prompt->analysis_strategy === 'evolutionary' ? 'Resumo Evolutivo' : 'Pipeline Hierárquico',
            ];
        }

        $this->table(
            ['ID', 'Título', 'Sistema', 'Usuário', 'Estratégia Atual'],
            $promptsTable
        );

        // Pega o ID do prompt (do argumento ou pergunta)
        $promptId = $this->argument('prompt_id');
        if (!$promptId) {
            $promptId = $this->ask('Digite o ID do prompt que deseja configurar');
        }

        $prompt = AiPrompt::find($promptId);
        if (!$prompt) {
            $this->error("Prompt ID {$promptId} não encontrado.");
            return 1;
        }

        // Pega a estratégia (do argumento ou pergunta)
        $strategy = $this->argument('strategy');
        if (!$strategy) {
            $strategy = $this->choice(
                'Escolha a estratégia de análise',
                ['hierarchical' => 'Pipeline Hierárquico (padrão)', 'evolutionary' => 'Resumo Evolutivo'],
                $prompt->analysis_strategy
            );
            // Remove a descrição e pega só a chave
            $strategy = array_search($strategy, ['hierarchical' => 'Pipeline Hierárquico (padrão)', 'evolutionary' => 'Resumo Evolutivo']);
        }

        // Valida a estratégia
        if (!in_array($strategy, ['hierarchical', 'evolutionary'])) {
            $this->error("Estratégia inválida. Use 'hierarchical' ou 'evolutionary'.");
            return 1;
        }

        // Atualiza o prompt
        $prompt->analysis_strategy = $strategy;
        $prompt->save();

        $strategyName = $strategy === 'evolutionary' ? 'Resumo Evolutivo' : 'Pipeline Hierárquico';

        $this->info('');
        $this->info("✅ Estratégia atualizada com sucesso!");
        $this->info("Prompt: {$prompt->title}");
        $this->info("Nova estratégia: {$strategyName}");
        $this->info('');

        if ($strategy === 'evolutionary') {
            $this->line('💡 <comment>Resumo Evolutivo</comment> é recomendado para processos com muitos documentos.');
            $this->line('   Cada documento é analisado sequencialmente, mantendo o contexto completo dos anteriores.');
        } else {
            $this->line('💡 <comment>Pipeline Hierárquico</comment> envia todos os documentos juntos em uma única análise.');
            $this->line('   É mais rápido, mas tem limite de quantidade de documentos.');
        }

        return 0;
    }
}
