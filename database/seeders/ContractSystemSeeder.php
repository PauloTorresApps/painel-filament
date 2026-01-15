<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use App\Models\System;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class ContractSystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Criar role "Analista de Contrato"
        $role = Role::firstOrCreate(
            ['name' => 'Analista de Contrato'],
            ['guard_name' => 'web']
        );

        $this->command->info("Role 'Analista de Contrato' criada/verificada.");

        // 2. Criar System "Contratos"
        $system = System::firstOrCreate(
            ['name' => 'Contratos'],
            [
                'description' => 'Sistema de análise de contratos',
                'is_active' => true,
            ]
        );

        $this->command->info("System 'Contratos' criado/verificado (ID: {$system->id}).");

        // 3. Criar AiPrompt padrão para análise de contratos
        $prompt = AiPrompt::firstOrCreate(
            [
                'system_id' => $system->id,
                'is_default' => true,
            ],
            [
                'title' => 'Análise de Contratos',
                'content' => $this->getDefaultPrompt(),
                'ai_provider' => 'gemini',
                'deep_thinking_enabled' => false,
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para contratos criado/verificado (ID: {$prompt->id}).");
    }

    /**
     * Retorna o prompt padrão para análise de contratos
     */
    private function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
Você é um analista jurídico especializado em contratos. Analise o contrato fornecido e produza um relatório estruturado contendo:

## 1. IDENTIFICAÇÃO DO CONTRATO
- Tipo de contrato (prestação de serviços, compra e venda, locação, etc.)
- Partes envolvidas (qualificação completa)
- Data de assinatura
- Vigência/Prazo

## 2. OBJETO DO CONTRATO
- Descrição clara do objeto
- Especificações técnicas (se houver)
- Escopo dos serviços/produtos

## 3. OBRIGAÇÕES DAS PARTES
### Contratante:
- Liste as principais obrigações

### Contratada:
- Liste as principais obrigações

## 4. CONDIÇÕES FINANCEIRAS
- Valor total do contrato
- Forma de pagamento
- Reajustes previstos
- Multas e penalidades

## 5. CLÁUSULAS IMPORTANTES
- Confidencialidade
- Propriedade intelectual
- Rescisão
- Foro de eleição

## 6. PONTOS DE ATENÇÃO (RISCOS)
Identifique cláusulas que possam representar riscos ou que mereçam atenção especial, incluindo:
- Cláusulas abusivas
- Obrigações desproporcionais
- Prazos muito curtos
- Penalidades excessivas
- Ausência de cláusulas importantes

## 7. RECOMENDAÇÕES
Sugira melhorias ou pontos que devem ser negociados antes da assinatura.

## 8. RESUMO EXECUTIVO
Forneça um resumo de no máximo 5 parágrafos com os pontos mais importantes do contrato.

---
Seja objetivo, preciso e destaque os pontos críticos que requerem atenção imediata.
PROMPT;
    }
}
