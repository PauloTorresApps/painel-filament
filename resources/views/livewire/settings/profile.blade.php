<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $name = '';
    public string $email = '';
    public $photo = null;
    public $logo = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
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

        $this->dispatch('profile-updated', name: $user->name);
    }

    public function deletePhoto(): void
    {
        Auth::user()->deleteProfilePhoto();
        $this->dispatch('profile-updated', name: Auth::user()->name);
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

        $this->dispatch('profile-updated', name: $user->name);
    }

    public function deleteLogo(): void
    {
        Auth::user()->deleteCompanyLogo();
        $this->dispatch('profile-updated', name: Auth::user()->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Perfil')" :subheading="__('Atualize sua foto de perfil, nome e endereço de e-mail')">

        {{-- Foto de Perfil --}}
        <div class="my-6 w-full space-y-4">
            <flux:heading size="sm">{{ __('Foto de Perfil') }}</flux:heading>
            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    @if($photo)
                        <img src="{{ $photo->temporaryUrl() }}" alt="Preview" class="h-16 w-16 rounded-lg object-cover" />
                    @else
                        <x-user-avatar :user="auth()->user()" size="xl" />
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <input
                        type="file"
                        wire:model="photo"
                        accept="image/*"
                        class="text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                    />
                    @error('photo') <span class="text-sm text-red-500">{{ $message }}</span> @enderror

                    <div class="flex gap-2">
                        @if($photo)
                            <flux:button wire:click="savePhoto" variant="primary" size="sm">
                                {{ __('Salvar Foto') }}
                            </flux:button>
                        @endif
                        @if(auth()->user()->hasProfilePhoto())
                            <flux:button wire:click="deletePhoto" variant="danger" size="sm">
                                {{ __('Remover') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <flux:separator />

        {{-- Logo da Empresa/Escritório --}}
        <div class="my-6 w-full space-y-4">
            <flux:heading size="sm">{{ __('Logo da Empresa/Escritório') }}</flux:heading>
            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    @if($logo)
                        <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="h-16 w-16 rounded-lg object-cover" />
                    @elseif(auth()->user()->companyLogoUrl())
                        <img src="{{ auth()->user()->companyLogoUrl() }}" alt="Logo" class="h-16 w-16 rounded-lg object-cover" />
                    @else
                        <div class="flex h-16 w-16 items-center justify-center rounded-lg bg-neutral-200 dark:bg-neutral-700">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-8 w-8 text-zinc-400">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                            </svg>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <input
                        type="file"
                        wire:model="logo"
                        accept="image/*"
                        class="text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                    />
                    @error('logo') <span class="text-sm text-red-500">{{ $message }}</span> @enderror

                    <div class="flex gap-2">
                        @if($logo)
                            <flux:button wire:click="saveLogo" variant="primary" size="sm">
                                {{ __('Salvar Logo') }}
                            </flux:button>
                        @endif
                        @if(auth()->user()->companyLogoUrl())
                            <flux:button wire:click="deleteLogo" variant="danger" size="sm">
                                {{ __('Remover') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <flux:separator />

        {{-- Nome e Email --}}
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Nome')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Seu endereço de e-mail não foi verificado.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Clique aqui para reenviar o e-mail de verificação.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('Um novo link de verificação foi enviado para o seu e-mail.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Salvar') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Salvo.') }}
                </x-action-message>
            </div>
        </form>

        <flux:separator />

        {{-- Link para Configurações de Conta --}}
        <div class="my-6">
            <flux:heading size="sm">{{ __('Configurações de Conta') }}</flux:heading>
            <flux:subheading class="mt-1 mb-3">
                {{ __('Gerencie suas configurações avançadas de conta, como notificações e preferências de análise.') }}
            </flux:subheading>
            <flux:button variant="subtle" :href="'/analises/user-settings'" icon="arrow-top-right-on-square">
                {{ __('Ir para Configurações de Conta') }}
            </flux:button>
        </div>

        <flux:separator />

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
