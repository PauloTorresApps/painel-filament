<?php

namespace App\Filament\Resources\JudicialUsers\Pages;

use App\Filament\Resources\JudicialUsers\JudicialUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJudicialUsers extends ListRecords
{
    protected static string $resource = JudicialUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
