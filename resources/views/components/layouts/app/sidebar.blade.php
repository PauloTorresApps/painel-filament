<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky stashable class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <x-app-logo />
            </a>

            @if(auth()->user()->can('access_admin'))
                <flux:navlist variant="outline">
                    <flux:navlist.group :heading="__('Plataforma')" class="grid">
                        <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Painel') }}</flux:navlist.item>
                    </flux:navlist.group>
                </flux:navlist>
            @endif

            @if(auth()->user()->can('access_admin'))
                <flux:navlist variant="outline">
                    <flux:navlist.group :heading="__('Administrador')" class="grid">
                        <flux:navlist.item icon="home" :href="route('filament.admin.pages.dashboard')" :current="request()->routeIs('filament.admin.pages.dashboard')" wire:navigate>{{ __('Painel Administrativo') }}</flux:navlist.item>
                    </flux:navlist.group>
                </flux:navlist>
            @endif

            @if(auth()->user()->can('analyze_process'))
                <flux:navlist variant="outline">
                    <flux:navlist.group :heading="__('Análise de Processos')" class="grid">
                        <flux:navlist.item icon="home" :href="route('eproc')" :current="request()->routeIs('eproc')" wire:navigate>{{ __('EPROC-TO') }}</flux:navlist.item>
                    </flux:navlist.group>
                </flux:navlist>
            @endif

            @if(auth()->user()->can('analyze_contract'))
                <flux:navlist variant="outline">
                    <flux:navlist.group :heading="__('Análise de Documentos')" class="grid">
                        <flux:navlist.item icon="home" href="/analises/contract-analysis" :current="request()->is('analises/contract-analysis')" wire:navigate>{{ __('Análise de Contratos') }}</flux:navlist.item>
                    </flux:navlist.group>
                </flux:navlist>
            @endif

            <flux:navlist variant="outline">
                <flux:navlist.group :heading="__('Conta')" class="grid">
                    <flux:navlist.item icon="cog-6-tooth" :href="route('notifications.edit')" :current="request()->routeIs('notifications.edit')" wire:navigate>{{ __('Configurações') }}</flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            <!-- Desktop User Menu -->
            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :avatar="auth()->user()->profilePhotoUrl()"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                    data-test="sidebar-menu-button"
                />

                <flux:menu class="w-[220px]">
                    <div class="p-0 text-sm font-normal">
                        <a href="{{ route('profile.edit') }}" wire:navigate class="flex items-center gap-2 px-1 py-1.5 text-start text-sm rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <x-user-avatar :user="auth()->user()" size="sm" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach(auth()->user()->getRoleNames() as $role)
                                        <span class="inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 ring-1 ring-inset ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700">
                                            {{ $role }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </a>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Configurações') }}</flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Sair') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :avatar="auth()->user()->profilePhotoUrl()"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <div class="p-0 text-sm font-normal">
                        <a href="{{ route('profile.edit') }}" wire:navigate class="flex items-center gap-2 px-1 py-1.5 text-start text-sm rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-700 transition-colors">
                            <x-user-avatar :user="auth()->user()" size="sm" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach(auth()->user()->getRoleNames() as $role)
                                        <span class="inline-flex items-center rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 ring-1 ring-inset ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700">
                                            {{ $role }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </a>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Configurações') }}</flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full" data-test="logout-button">
                            {{ __('Sair') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <x-loading />

        @fluxScripts
    </body>
</html>
