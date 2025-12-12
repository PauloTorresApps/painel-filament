<?php

namespace App\Filament\Resources\JudicialUsers\Pages;

use App\Filament\Resources\JudicialUsers\JudicialUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJudicialUser extends EditRecord
{
    protected static string $resource = JudicialUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
