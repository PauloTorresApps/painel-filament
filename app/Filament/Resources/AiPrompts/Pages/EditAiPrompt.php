<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use App\Models\AiPrompt;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiPrompt extends EditRecord
{
    protected static string $resource = AiPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Remove HTML tags e scripts para proteção contra XSS
        if (isset($data['content'])) {
            $data['content'] = strip_tags($data['content']);
            $data['content'] = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
        }

        // Se este prompt está sendo marcado como padrão, remove o padrão dos outros prompts do mesmo sistema
        if (isset($data['is_default']) && $data['is_default'] && isset($data['system_id'])) {
            AiPrompt::where('system_id', $data['system_id'])
                ->where('is_default', true)
                ->where('id', '!=', $this->record->id) // Exclui o registro atual
                ->update(['is_default' => false]);
        }

        return $data;
    }
}
