<?php

namespace App\Filament\Resources\ContractPrompts\Pages;

use App\Filament\Resources\ContractPrompts\ContractPromptResource;
use App\Models\AiPrompt;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditContractPrompt extends EditRecord
{
    protected static string $resource = ContractPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Garante que o system_id é do sistema de Contratos (não pode ser alterado)
        $data['system_id'] = ContractPromptResource::getContractSystemId();

        // Remove HTML tags e scripts para proteção contra XSS
        if (isset($data['content'])) {
            $data['content'] = strip_tags($data['content']);
            $data['content'] = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
        }

        // Prompts padrão devem estar sempre ativos
        if (!empty($data['is_default'])) {
            $data['is_active'] = true;
        }

        // Se este prompt está sendo marcado como padrão, remove o padrão dos outros prompts
        // do mesmo sistema E do mesmo tipo
        if (!empty($data['is_default']) && !empty($data['prompt_type'])) {
            AiPrompt::where('system_id', $data['system_id'])
                ->where('prompt_type', $data['prompt_type'])
                ->where('is_default', true)
                ->where('id', '!=', $this->record->id)
                ->update(['is_default' => false]);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
