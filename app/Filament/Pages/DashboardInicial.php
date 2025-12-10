<?php

namespace App\Filament\Pages;

use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class DashboardInicial extends Page
{
    protected string $view = 'filament.pages.dashboard-inicial';
    // protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationLabel = 'Dashboard Inicial';
    // protected static string|null|UnitEnum $navigationGroup = 'Aplicação';

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::UserCircle;

    public function mount()
    {
        return redirect()->route('dashboard');
    }
}
