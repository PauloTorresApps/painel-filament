<?php

namespace App\Filament\Resources\Systems\Pages;

use App\Filament\Resources\Systems\SystemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSystem extends EditRecord
{
    protected static string $resource = SystemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
