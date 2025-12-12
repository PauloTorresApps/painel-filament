<?php

namespace App\Filament\Resources\AiPrompts\Pages;

use App\Filament\Resources\AiPrompts\AiPromptResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAiPrompt extends CreateRecord
{
    protected static string $resource = AiPromptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Remove HTML tags e scripts para proteção contra XSS
        if (isset($data['content'])) {
            $data['content'] = strip_tags($data['content']);
            $data['content'] = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
        }

        // Garante que o user_id está definido
        if (!isset($data['user_id'])) {
            $data['user_id'] = Auth::id();
        }

        return $data;
    }
}
