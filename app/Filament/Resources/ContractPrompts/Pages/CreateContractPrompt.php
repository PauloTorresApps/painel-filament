<?php

namespace App\Filament\Resources\ContractPrompts\Pages;

use App\Filament\Resources\ContractPrompts\ContractPromptResource;
use App\Models\AiPrompt;
use Filament\Resources\Pages\CreateRecord;

class CreateContractPrompt extends CreateRecord
{
    protected static string $resource = ContractPromptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Garante que o system_id é do sistema de Contratos
        $systemId = ContractPromptResource::getContractSystemId();
        $data['system_id'] = $systemId;

        // Remove HTML tags e scripts para proteção contra XSS
        if (isset($data['content'])) {
            $data['content'] = strip_tags($data['content']);
            $data['content'] = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
        }

        // Se este prompt está sendo marcado como padrão, remove o padrão dos outros prompts
        // do mesmo sistema E do mesmo tipo (analysis ou legal_opinion)
        if (isset($data['is_default']) && $data['is_default'] && isset($data['prompt_type'])) {
            AiPrompt::where('system_id', $systemId)
                ->where('prompt_type', $data['prompt_type'])
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
