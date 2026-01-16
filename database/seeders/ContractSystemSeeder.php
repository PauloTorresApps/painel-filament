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

        // 3. Criar AiPrompt padrão para ANÁLISE de contratos
        $analysisPrompt = AiPrompt::firstOrCreate(
            [
                'system_id' => $system->id,
                'prompt_type' => AiPrompt::TYPE_ANALYSIS,
                'is_default' => true,
            ],
            [
                'title' => 'Análise de Contratos',
                'content' => $this->getAnalysisPrompt(),
                'ai_provider' => 'gemini',
                'deep_thinking_enabled' => false,
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para ANÁLISE de contratos criado/verificado (ID: {$analysisPrompt->id}).");

        // 4. Criar AiPrompt padrão para PARECER JURÍDICO
        $legalOpinionPrompt = AiPrompt::firstOrCreate(
            [
                'system_id' => $system->id,
                'prompt_type' => AiPrompt::TYPE_LEGAL_OPINION,
                'is_default' => true,
            ],
            [
                'title' => 'Parecer Jurídico',
                'content' => $this->getLegalOpinionPrompt(),
                'ai_provider' => 'gemini',
                'deep_thinking_enabled' => false,
                'analysis_strategy' => 'evolutionary',
                'is_active' => true,
            ]
        );

        $this->command->info("AiPrompt padrão para PARECER JURÍDICO criado/verificado (ID: {$legalOpinionPrompt->id}).");
    }

    /**
     * Retorna o prompt padrão para ANÁLISE de contratos
     */
    private function getAnalysisPrompt(): string
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

    /**
     * Retorna o prompt padrão para PARECER JURÍDICO
     */
    private function getLegalOpinionPrompt(): string
    {
        return <<<'PROMPT'
Você é um advogado especialista em direito contratual. Elabore um PARECER JURÍDICO formal sobre o contrato fornecido, seguindo a estrutura abaixo:

---

# PARECER JURÍDICO

## I. EMENTA
Breve resumo do objeto do parecer em no máximo 3 linhas.

## II. RELATÓRIO
Descreva os fatos relevantes, incluindo:
- Identificação das partes
- Objeto do contrato
- Contexto da consulta

## III. FUNDAMENTAÇÃO JURÍDICA

### 3.1. Enquadramento Legal
- Identifique a legislação aplicável (Código Civil, CDC, leis especiais, etc.)
- Cite os artigos pertinentes

### 3.2. Análise das Cláusulas
Para cada cláusula relevante:
- Transcreva ou resuma a cláusula
- Analise sua validade jurídica
- Cite jurisprudência ou doutrina quando aplicável

### 3.3. Riscos Jurídicos Identificados
- Liste os riscos de nulidade ou anulabilidade
- Identifique possíveis cláusulas abusivas
- Avalie riscos de litígio

### 3.4. Conformidade Legal
- Verifique adequação à LGPD (se aplicável)
- Analise conformidade com normas setoriais
- Avalie aspectos tributários relevantes

## IV. CONCLUSÃO
Apresente sua opinião jurídica fundamentada sobre:
- Viabilidade da contratação
- Recomendação de aprovação, rejeição ou revisão
- Alterações necessárias antes da assinatura

## V. RESSALVAS
Mencione limitações da análise e questões que demandam verificação adicional.

---

**Observações:**
- Utilize linguagem técnica jurídica apropriada
- Fundamente todas as conclusões em dispositivos legais
- Seja objetivo mas completo na análise
PROMPT;
    }
}
