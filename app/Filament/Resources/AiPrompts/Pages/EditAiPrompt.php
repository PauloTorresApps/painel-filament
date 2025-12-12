<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
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

        return $data;
    }
}
