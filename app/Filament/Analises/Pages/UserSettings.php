<?php

namespace App\Filament\Analises\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configurações';

    protected static ?string $title = 'Configurações';

    protected static UnitEnum|string|null $navigationGroup = 'Conta';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.analises.pages.user-settings';

    public bool $email_notifications_enabled = false;
    public bool $email_notify_process_analysis = false;
    public bool $email_notify_contract_analysis = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->email_notifications_enabled = (bool) $user->email_notifications_enabled;
        $this->email_notify_process_analysis = (bool) $user->email_notify_process_analysis;
        $this->email_notify_contract_analysis = (bool) $user->email_notify_contract_analysis;
    }

    public function save(): void
    {
        if (!$this->email_notifications_enabled) {
            $this->email_notify_process_analysis = false;
            $this->email_notify_contract_analysis = false;
        }

        Auth::user()->update([
            'email_notifications_enabled' => $this->email_notifications_enabled,
            'email_notify_process_analysis' => $this->email_notify_process_analysis,
            'email_notify_contract_analysis' => $this->email_notify_contract_analysis,
        ]);

        Notification::make()
            ->title('Configurações salvas')
            ->body('Suas preferências de notificação foram atualizadas.')
            ->success()
            ->send();
    }
}
