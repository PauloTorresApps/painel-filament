<?php

namespace App\Filament\Resources\JudicialUsers\Pages;

use App\Filament\Resources\JudicialUsers\JudicialUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJudicialUser extends CreateRecord
{
    protected static string $resource = JudicialUserResource::class;
}
