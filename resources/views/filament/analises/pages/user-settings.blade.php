<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">

        {{-- Toggle geral --}}
        <x-filament::section>
            <x-slot name="heading">Notificações por E-mail</x-slot>
            <x-slot name="description">Receba os resultados das análises diretamente no seu e-mail, com o arquivo PDF anexo.</x-slot>

            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Habilitar notificações por e-mail</span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model.live="email_notifications_enabled" class="sr-only peer">
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary-300 dark:peer-focus:ring-primary-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:after:border-gray-600 peer-checked:bg-primary-600"></div>
                </label>
            </div>
        </x-filament::section>

        {{-- Serviços individuais --}}
        <x-filament::section class="{{ !$email_notifications_enabled ? 'opacity-50' : '' }}">
            <x-slot name="heading">Serviços</x-slot>
            <x-slot name="description">Selecione para quais serviços deseja receber e-mail ao concluir a análise.</x-slot>

            <div class="space-y-4">
                <label class="flex items-start gap-3 cursor-pointer {{ !$email_notifications_enabled ? 'pointer-events-none' : '' }}">
                    <input
                        type="checkbox"
                        wire:model="email_notify_process_analysis"
                        {{ !$email_notifications_enabled ? 'disabled' : '' }}
                        class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                    >
                    <div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Análise de Processos</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Análises de processos judiciais via EPROC</p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer {{ !$email_notifications_enabled ? 'pointer-events-none' : '' }}">
                    <input
                        type="checkbox"
                        wire:model="email_notify_contract_analysis"
                        {{ !$email_notifications_enabled ? 'disabled' : '' }}
                        class="mt-0.5 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700"
                    >
                    <div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Análise de Contratos</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Análises de documentos contratuais</p>
                    </div>
                </label>
            </div>
        </x-filament::section>

        <div class="flex justify-end">
            <x-filament::button type="submit">
                Salvar Configurações
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
