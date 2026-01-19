<x-filament-panels::page>
    @php
        $canViewProcesses = $this->canViewProcesses();
        $canViewContracts = $this->canViewContracts();
        $showTabs = $canViewProcesses && $canViewContracts;
    @endphp

    @if($showTabs)
        {{-- Tabs Navigation --}}
        <div class="mb-6">
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    {{-- Tab Processos --}}
                    <button
                        wire:click="setActiveTab('processos')"
                        @class([
                            'group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium transition-colors',
                            'border-primary-500 text-primary-600 dark:text-primary-400' => $activeTab === 'processos',
                            'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $activeTab !== 'processos',
                        ])
                    >
                        <x-heroicon-o-document-magnifying-glass @class([
                            '-ml-0.5 mr-2 h-5 w-5',
                            'text-primary-500' => $activeTab === 'processos',
                            'text-gray-400 group-hover:text-gray-500' => $activeTab !== 'processos',
                        ]) />
                        <span>Análises de Processos</span>
                        @if($this->isDefaultTab('processos'))
                            <x-heroicon-s-star class="ml-2 h-4 w-4 text-amber-500" />
                        @endif
                    </button>

                    {{-- Tab Contratos --}}
                    <button
                        wire:click="setActiveTab('contratos')"
                        @class([
                            'group inline-flex items-center border-b-2 py-4 px-1 text-sm font-medium transition-colors',
                            'border-primary-500 text-primary-600 dark:text-primary-400' => $activeTab === 'contratos',
                            'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $activeTab !== 'contratos',
                        ])
                    >
                        <x-heroicon-o-document-chart-bar @class([
                            '-ml-0.5 mr-2 h-5 w-5',
                            'text-primary-500' => $activeTab === 'contratos',
                            'text-gray-400 group-hover:text-gray-500' => $activeTab !== 'contratos',
                        ]) />
                        <span>Análises de Contratos</span>
                        @if($this->isDefaultTab('contratos'))
                            <x-heroicon-s-star class="ml-2 h-4 w-4 text-amber-500" />
                        @endif
                    </button>
                </nav>
            </div>

            {{-- Botão para definir aba padrão --}}
            <div class="mt-3 flex justify-end">
                <button
                    wire:click="setDefaultTab('{{ $activeTab }}')"
                    class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 transition-colors"
                    title="Definir esta aba como padrão"
                >
                    @if($this->isDefaultTab($activeTab))
                        <x-heroicon-s-star class="h-4 w-4 text-amber-500" />
                        <span>Esta é sua aba padrão</span>
                    @else
                        <x-heroicon-o-star class="h-4 w-4" />
                        <span>Definir como aba padrão</span>
                    @endif
                </button>
            </div>
        </div>
    @endif

    {{-- Widgets Content --}}
    <div class="grid grid-cols-1 gap-6">
        @if($activeTab === 'processos' && $canViewProcesses)
            @foreach($this->getProcessWidgets() as $widget)
                @livewire($widget, [], key($widget))
            @endforeach
        @elseif($activeTab === 'contratos' && $canViewContracts)
            @foreach($this->getContractWidgets() as $widget)
                @livewire($widget, [], key($widget))
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
