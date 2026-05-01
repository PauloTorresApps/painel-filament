<?php

namespace App\Filament\Resources\ContractPrompts\Pages;

use App\Filament\Resources\ContractPrompts\ContractPromptResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContractPrompts extends ListRecords
{
    protected static string $resource = ContractPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Novo Prompt'),
        ];
    }
}
