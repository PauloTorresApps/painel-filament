<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
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
        // Se o toggle geral está desligado, desliga os individuais também
        if (!$this->email_notifications_enabled) {
            $this->email_notify_process_analysis = false;
            $this->email_notify_contract_analysis = false;
        }

        Auth::user()->update([
            'email_notifications_enabled' => $this->email_notifications_enabled,
            'email_notify_process_analysis' => $this->email_notify_process_analysis,
            'email_notify_contract_analysis' => $this->email_notify_contract_analysis,
        ]);

        $this->dispatch('notifications-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Notificações')" :subheading="__('Configure como deseja receber notificações sobre suas análises')">
        <form wire:submit="save" class="my-6 w-full space-y-6">

            {{-- Toggle geral --}}
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="sm">{{ __('Notificações por E-mail') }}</flux:heading>
                    <flux:subheading size="sm" class="mt-1">
                        {{ __('Receba os resultados das análises diretamente no seu e-mail, com o arquivo PDF anexo.') }}
                    </flux:subheading>
                </div>
                <flux:switch wire:model.live="email_notifications_enabled" />
            </div>

            {{-- Serviços individuais --}}
            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 {{ !$email_notifications_enabled ? 'opacity-50' : '' }}">
                <flux:heading size="sm">{{ __('Serviços') }}</flux:heading>
                <flux:subheading size="sm" class="mt-1 mb-4">
                    {{ __('Selecione para quais serviços deseja receber e-mail ao concluir a análise.') }}
                </flux:subheading>

                <label class="flex items-center gap-3 cursor-pointer">
                    <flux:checkbox
                        wire:model="email_notify_process_analysis"
                        :disabled="!$email_notifications_enabled"
                    />
                    <div>
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Análise de Processos') }}</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Análises de processos judiciais via EPROC') }}</p>
                    </div>
                </label>

                <label class="flex items-center gap-3 cursor-pointer">
                    <flux:checkbox
                        wire:model="email_notify_contract_analysis"
                        :disabled="!$email_notifications_enabled"
                    />
                    <div>
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Análise de Contratos') }}</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Análises de documentos contratuais') }}</p>
                    </div>
                </label>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">
                        {{ __('Salvar') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="notifications-updated">
                    {{ __('Salvo.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
