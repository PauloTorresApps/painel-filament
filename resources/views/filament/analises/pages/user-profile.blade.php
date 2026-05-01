<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Perfis/Roles do Usuário --}}
        <x-filament::section>
            <x-slot name="heading">Perfis</x-slot>
            <x-slot name="description">Seus perfis de acesso na plataforma.</x-slot>

            <div class="flex flex-wrap gap-2">
                @foreach(auth()->user()->getRoleNames() as $role)
                    <span class="inline-flex items-center rounded-full bg-primary-50 px-3 py-1 text-sm font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30">
                        {{ $role }}
                    </span>
                @endforeach
            </div>
        </x-filament::section>

        {{-- Foto de Perfil --}}
        <x-filament::section>
            <x-slot name="heading">Foto de Perfil</x-slot>
            <x-slot name="description">A foto será exibida no menu e em seu perfil.</x-slot>

            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    @if($photo)
                        <img src="{{ $photo->temporaryUrl() }}" alt="Preview" class="h-20 w-20 rounded-full object-cover ring-2 ring-gray-200 dark:ring-gray-700" />
                    @elseif(auth()->user()->hasProfilePhoto())
                        <img src="{{ auth()->user()->profilePhotoUrl() }}" alt="{{ auth()->user()->name }}" class="h-20 w-20 rounded-full object-cover ring-2 ring-gray-200 dark:ring-gray-700" />
                    @else
                        <div class="flex h-20 w-20 items-center justify-center rounded-full bg-primary-100 text-xl font-bold text-primary-600 dark:bg-primary-800 dark:text-primary-400">
                            {{ auth()->user()->initials() }}
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-3">
                    <input
                        type="file"
                        wire:model="photo"
                        accept="image/*"
                        class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-gray-700 dark:file:text-gray-300"
                    />
                    @error('photo') <span class="text-sm text-danger-500">{{ $message }}</span> @enderror

                    <div class="flex gap-2">
                        @if($photo)
                            <x-filament::button wire:click="savePhoto" size="sm">
                                Salvar Foto
                            </x-filament::button>
                        @endif
                        @if(auth()->user()->hasProfilePhoto())
                            <x-filament::button wire:click="deletePhoto" size="sm" color="danger">
                                Remover
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Logo da Empresa/Escritório --}}
        <x-filament::section>
            <x-slot name="heading">Logo da Empresa/Escritório</x-slot>
            <x-slot name="description">Adicione a logo da sua empresa ou escritório.</x-slot>

            <div class="flex items-center gap-6">
                <div class="shrink-0">
                    @if($logo)
                        <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="h-20 w-20 rounded-lg object-cover ring-2 ring-gray-200 dark:ring-gray-700" />
                    @elseif(auth()->user()->companyLogoUrl())
                        <img src="{{ auth()->user()->companyLogoUrl() }}" alt="Logo" class="h-20 w-20 rounded-lg object-cover ring-2 ring-gray-200 dark:ring-gray-700" />
                    @else
                        <div class="flex h-20 w-20 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800">
                            <x-heroicon-o-building-office class="h-10 w-10 text-gray-400" />
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-3">
                    <input
                        type="file"
                        wire:model="logo"
                        accept="image/*"
                        class="text-sm text-gray-500 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary-700 hover:file:bg-primary-100 dark:file:bg-gray-700 dark:file:text-gray-300"
                    />
                    @error('logo') <span class="text-sm text-danger-500">{{ $message }}</span> @enderror

                    <div class="flex gap-2">
                        @if($logo)
                            <x-filament::button wire:click="saveLogo" size="sm">
                                Salvar Logo
                            </x-filament::button>
                        @endif
                        @if(auth()->user()->companyLogoUrl())
                            <x-filament::button wire:click="deleteLogo" size="sm" color="danger">
                                Remover
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{-- Informações Pessoais --}}
        <form wire:submit="updateProfile">
            <x-filament::section>
                <x-slot name="heading">Informações Pessoais</x-slot>
                <x-slot name="description">Atualize seu nome e endereço de e-mail.</x-slot>

                <div class="space-y-4">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nome</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" wire:model="name" id="name" required />
                        </x-filament::input.wrapper>
                        @error('name') <span class="text-sm text-danger-500">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">E-mail</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="email" wire:model="email" id="email" required />
                        </x-filament::input.wrapper>
                        @error('email') <span class="text-sm text-danger-500">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex justify-end">
                        <x-filament::button type="submit">
                            Salvar Informações
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        </form>

        {{-- Alterar Senha --}}
        <form wire:submit="updatePassword">
            <x-filament::section>
                <x-slot name="heading">Alterar Senha</x-slot>
                <x-slot name="description">Utilize uma senha forte e única para manter sua conta segura.</x-slot>

                <div class="space-y-4">
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Senha Atual</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="password" wire:model="current_password" id="current_password" required />
                        </x-filament::input.wrapper>
                        @error('current_password') <span class="text-sm text-danger-500">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nova Senha</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="password" wire:model="password" id="password" required />
                        </x-filament::input.wrapper>
                        @error('password') <span class="text-sm text-danger-500">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirmar Nova Senha</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="password" wire:model="password_confirmation" id="password_confirmation" required />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="flex justify-end">
                        <x-filament::button type="submit">
                            Alterar Senha
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        </form>

        {{-- Link para Configurações de Conta --}}
        <x-filament::section>
            <x-slot name="heading">Configurações de Conta</x-slot>
            <x-slot name="description">Gerencie suas configurações avançadas de conta, como notificações e preferências de análise.</x-slot>

            <x-filament::button
                :href="App\Filament\Analises\Pages\UserSettings::getUrl()"
                tag="a"
                color="gray"
                icon="heroicon-o-cog-6-tooth"
            >
                Ir para Configurações de Conta
            </x-filament::button>
        </x-filament::section>

    </div>
</x-filament-panels::page>
