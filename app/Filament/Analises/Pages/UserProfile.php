<?php

namespace App\Filament\Analises\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Livewire\WithFileUploads;
use UnitEnum;

class UserProfile extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Meu Perfil';

    protected static ?string $title = 'Meu Perfil';

    protected static UnitEnum|string|null $navigationGroup = 'Conta';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.analises.pages.user-profile';

    public string $name = '';
    public string $email = '';
    public $photo = null;
    public $logo = null;
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function updateProfile(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . Auth::id()],
        ]);

        Auth::user()->update([
            'name' => $this->name,
            'email' => $this->email,
        ]);

        Notification::make()
            ->title('Perfil atualizado')
            ->body('Suas informações foram salvas com sucesso.')
            ->success()
            ->send();
    }

    public function updatedPhoto(): void
    {
        $this->validate([
            'photo' => ['image', 'max:2048'],
        ]);
    }

    public function savePhoto(): void
    {
        $this->validate([
            'photo' => ['required', 'image', 'max:2048'],
        ]);

        $user = Auth::user();

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $path = $this->photo->store('profile-photos', 'public');
        $user->update(['profile_photo_path' => $path]);
        $this->photo = null;

        Notification::make()
            ->title('Foto atualizada')
            ->success()
            ->send();
    }

    public function deletePhoto(): void
    {
        Auth::user()->deleteProfilePhoto();

        Notification::make()
            ->title('Foto removida')
            ->success()
            ->send();
    }

    public function updatedLogo(): void
    {
        $this->validate([
            'logo' => ['image', 'max:2048'],
        ]);
    }

    public function saveLogo(): void
    {
        $this->validate([
            'logo' => ['required', 'image', 'max:2048'],
        ]);

        $user = Auth::user();

        if ($user->company_logo_path) {
            Storage::disk('public')->delete($user->company_logo_path);
        }

        $path = $this->logo->store('company-logos', 'public');
        $user->update(['company_logo_path' => $path]);
        $this->logo = null;

        Notification::make()
            ->title('Logo atualizada')
            ->success()
            ->send();
    }

    public function deleteLogo(): void
    {
        Auth::user()->deleteCompanyLogo();

        Notification::make()
            ->title('Logo removida')
            ->success()
            ->send();
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        Auth::user()->update([
            'password' => Hash::make($this->password),
        ]);

        $this->current_password = '';
        $this->password = '';
        $this->password_confirmation = '';

        Notification::make()
            ->title('Senha atualizada')
            ->body('Sua senha foi alterada com sucesso.')
            ->success()
            ->send();
    }
}
