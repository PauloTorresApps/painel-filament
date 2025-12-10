<?php

namespace App\Filament\Resources\Users\Pages;

use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\Hash;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
