<x-filament-panels::page>
    {{-- Mensagens de erro e sucesso --}}
    @if($errors->any())
        <div class="mb-6">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-content p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center rounded-full bg-danger-50 dark:bg-danger-400/10 w-10 h-10">
                            <x-filament::icon
                                icon="heroicon-o-exclamation-circle"
                                class="w-5 h-5 text-danger-600 dark:text-danger-400"
                            />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-danger-600 dark:text-danger-400">
                                Erros encontrados
                            </h3>
                            <ul class="mt-2 space-y-1 text-sm text-danger-600 dark:text-danger-400">
                                @foreach($errors->all() as $error)
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1">•</span>
                                        <span>{{ $error }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-content p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center rounded-full bg-danger-50 dark:bg-danger-400/10 w-10 h-10">
                            <x-filament::icon
                                icon="heroicon-o-exclamation-triangle"
                                class="w-5 h-5 text-danger-600 dark:text-danger-400"
                            />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-danger-600 dark:text-danger-400">
                                Erro
                            </h3>
                            <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">
                                {{ session('error') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="mb-6">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="fi-section-content p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex items-center justify-center rounded-full bg-success-50 dark:bg-success-400/10 w-10 h-10">
                            <x-filament::icon
                                icon="heroicon-o-check-circle"
                                class="w-5 h-5 text-success-600 dark:text-success-400"
                            />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-success-600 dark:text-success-400">
                                Sucesso
                            </h3>
                            <p class="mt-1 text-sm text-success-600 dark:text-success-400">
                                {{ session('success') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Formulário de consulta (2/3 da largura) --}}
        <div class="lg:col-span-2">
            <x-filament::section
                icon="heroicon-o-magnifying-glass"
                icon-color="primary"
            >
                <x-slot name="heading">
                    Consulta de Processos
                </x-slot>

                <x-slot name="description">
                    Digite o número do processo para consultar informações, movimentos e documentos.
                </x-slot>

                <form action="{{ route('eproc.consultar') }}" method="POST" class="space-y-6" id="consultaForm">
                    @csrf

                    {{-- Seleção de Usuário Judicial --}}
                    <div>
                        <label for="user_ws" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                            <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                Usuário do Webservice
                                <sup class="text-danger-600 dark:text-danger-400 font-medium">*</sup>
                            </span>
                        </label>

                        <div class="mt-2">
                            <select
                                name="user_ws"
                                id="user_ws"
                                required
                                class="judicial-user-select"
                                autocomplete="off"
                            >
                                <option value="">Selecione um usuário...</option>
                                @php
                                    $defaultUser = auth()->user()->judicialUsers()->where('is_default', true)->first();
                                    $defaultUserId = old('user_ws', $defaultUser?->id);
                                @endphp
                                @foreach(auth()->user()->judicialUsers as $judicialUser)
                                    <option
                                        value="{{ $judicialUser->id }}"
                                        {{ $defaultUserId == $judicialUser->id ? 'selected' : '' }}
                                    >
                                        {{ $judicialUser->system->name }} - {{ $judicialUser->user_login }}
                                        @if($judicialUser->is_default) ⭐ @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @if(auth()->user()->judicialUsers->isEmpty())
                            <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                                Você ainda não possui usuários cadastrados. <a href="{{ route('filament.admin.resources.judicial-users.index') }}" class="underline font-semibold">Cadastre um usuário judicial</a> antes de consultar processos.
                            </p>
                        @else
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Selecione o usuário do webservice que será utilizado para a consulta
                            </p>
                        @endif
                    </div>

                    {{-- Campo de Senha --}}
                    <div>
                        <x-filament::input.wrapper
                            label="Senha do Webservice"
                            required
                            :error="$errors->first('password_ws')"
                        >
                            <x-filament::input
                                type="password"
                                name="password_ws"
                                id="password_ws"
                                placeholder="Digite a senha do webservice"
                                required
                                autocomplete="new-password"
                            />
                        </x-filament::input.wrapper>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Digite a senha do usuário selecionado para autenticação no webservice
                        </p>
                    </div>

                    {{-- Campo Número do Processo --}}
                    <div>
                        <x-filament::input.wrapper
                            label="Número do Processo"
                            required
                            :error="$errors->first('numero_processo')"
                        >
                            <x-filament::input
                                type="text"
                                id="numero_processo"
                                name="numero_processo"
                                placeholder="0000000-00.0000.0.00.0000"
                                value="{{ old('numero_processo') }}"
                                required
                                class="font-mono"
                                maxlength="25"
                            />
                        </x-filament::input.wrapper>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Digite o número completo do processo no formato padrão CNJ
                        </p>
                    </div>

                    {{-- Campos de Data --}}
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <x-filament::input.wrapper
                            label="Data Inicial (Opcional)"
                            helper-text="Filtrar eventos a partir desta data"
                            :error="$errors->first('data_inicial')"
                        >
                            <x-filament::input
                                type="date"
                                name="data_inicial"
                                value="{{ old('data_inicial') }}"
                            />
                        </x-filament::input.wrapper>

                        <x-filament::input.wrapper
                            label="Data Final (Opcional)"
                            helper-text="Filtrar eventos até esta data"
                            :error="$errors->first('data_final')"
                        >
                            <x-filament::input
                                type="date"
                                name="data_final"
                                value="{{ old('data_final') }}"
                            />
                        </x-filament::input.wrapper>
                    </div>

                    {{-- Botão de Submit --}}
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <x-filament::button
                            type="submit"
                            icon="heroicon-o-magnifying-glass"
                            size="lg"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                        >
                            <span wire:loading.remove wire:target="submit">
                                Consultar Processo
                            </span>
                            <span wire:loading wire:target="submit">
                                <x-filament::loading-indicator class="h-5 w-5" />
                                Consultando...
                            </span>
                        </x-filament::button>
                    </div>
                </form>
            </x-filament::section>
        </div>

        {{-- Informações e ajuda (1/3 da largura) --}}
        <div class="lg:col-span-1">
            <x-filament::section
                icon="heroicon-o-information-circle"
                icon-color="info"
            >
                <x-slot name="heading">
                    Informações
                </x-slot>

                <div class="space-y-4 text-sm">
                    <p class="text-gray-600 dark:text-gray-400">
                        Este sistema permite consultar processos judiciais via webservice SOAP.
                    </p>

                    <div>
                        <p class="font-semibold text-gray-900 dark:text-gray-100 mb-2">
                            Funcionalidades:
                        </p>
                        <ul class="space-y-2">
                            <li class="flex items-start gap-2">
                                <x-filament::icon
                                    icon="heroicon-m-check"
                                    class="w-5 h-5 text-success-500 flex-shrink-0 mt-0.5"
                                />
                                <span class="text-gray-600 dark:text-gray-400">
                                    Consulta de dados do processo
                                </span>
                            </li>
                            <li class="flex items-start gap-2">
                                <x-filament::icon
                                    icon="heroicon-m-check"
                                    class="w-5 h-5 text-success-500 flex-shrink-0 mt-0.5"
                                />
                                <span class="text-gray-600 dark:text-gray-400">
                                    Visualização de movimentos processuais
                                </span>
                            </li>
                            <li class="flex items-start gap-2">
                                <x-filament::icon
                                    icon="heroicon-m-check"
                                    class="w-5 h-5 text-success-500 flex-shrink-0 mt-0.5"
                                />
                                <span class="text-gray-600 dark:text-gray-400">
                                    Lista de documentos vinculados
                                </span>
                            </li>
                            <li class="flex items-start gap-2">
                                <x-filament::icon
                                    icon="heroicon-m-check"
                                    class="w-5 h-5 text-success-500 flex-shrink-0 mt-0.5"
                                />
                                <span class="text-gray-600 dark:text-gray-400">
                                    Visualização da íntegra dos documentos
                                </span>
                            </li>
                        </ul>
                    </div>

                    <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-xs text-gray-500 dark:text-gray-500">
                            <x-filament::icon
                                icon="heroicon-m-information-circle"
                                class="w-4 h-4 inline-block"
                            />
                            Os dados são consultados em tempo real diretamente do sistema E-Proc.
                        </p>
                    </div>
                </div>
            </x-filament::section>
        </div>
    </div>

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

    <style>
        .ts-wrapper {
            width: 100%;
        }
        .ts-control, .ts-dropdown {
            font-size: 0.875rem;
            border-radius: 0.5rem;
        }
        .ts-control {
            border: 1px solid rgb(209 213 219);
            background: white;
            padding: 0.5rem 0.75rem;
            min-height: 42px;
        }
        .dark .ts-control {
            background: rgb(31 41 55);
            border-color: rgb(55 65 81);
            color: white;
        }
        .ts-control:focus {
            border-color: rgb(var(--primary-500));
            outline: none;
            box-shadow: 0 0 0 1px rgb(var(--primary-500));
        }
        .ts-dropdown {
            border: 1px solid rgb(209 213 219);
            background: white;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .dark .ts-dropdown {
            background: rgb(31 41 55);
            border-color: rgb(55 65 81);
        }
        .ts-dropdown .option {
            padding: 0.5rem 1rem;
        }
        .ts-dropdown .option:hover, .ts-dropdown .option.active {
            background: rgb(243 244 246);
        }
        .dark .ts-dropdown .option:hover, .dark .ts-dropdown .option.active {
            background: rgb(55 65 81);
            color: white;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializa Tom Select para o campo user_ws
            const userWsSelect = document.getElementById('user_ws');
            if (userWsSelect && typeof TomSelect !== 'undefined') {
                new TomSelect(userWsSelect, {
                    placeholder: 'Selecione um usuário...',
                    allowEmptyOption: true,
                    create: false,
                    sortField: {
                        field: "text",
                        direction: "asc"
                    }
                });
            }

            const form = document.getElementById('consultaForm');
            const numeroProcessoInput = document.getElementById('numero_processo');

            // Máscara para número de processo CNJ: 0000000-00.0000.0.00.0000
            if (numeroProcessoInput) {
                numeroProcessoInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/\D/g, ''); // Remove tudo que não é número

                    // Limita a 20 dígitos
                    value = value.substring(0, 20);

                    // Aplica a máscara
                    let formatted = '';
                    if (value.length > 0) {
                        formatted = value.substring(0, 7); // 0000000
                        if (value.length > 7) {
                            formatted += '-' + value.substring(7, 9); // -00
                        }
                        if (value.length > 9) {
                            formatted += '.' + value.substring(9, 13); // .0000
                        }
                        if (value.length > 13) {
                            formatted += '.' + value.substring(13, 14); // .0
                        }
                        if (value.length > 14) {
                            formatted += '.' + value.substring(14, 16); // .00
                        }
                        if (value.length > 16) {
                            formatted += '.' + value.substring(16, 20); // .0000
                        }
                    }

                    e.target.value = formatted;
                });

                // Permite colar números com ou sem formatação
                numeroProcessoInput.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                    const numbers = pastedText.replace(/\D/g, '');
                    e.target.value = numbers;
                    e.target.dispatchEvent(new Event('input'));
                });
            }

            if (form) {
                form.addEventListener('submit', function(e) {
                    // Desabilita o botão para evitar múltiplos cliques
                    const submitButton = form.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.innerHTML = '<svg class="animate-spin h-5 w-5 inline-block mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Consultando...';
                    }
                });
            }
        });
    </script>
    @endpush
</x-filament-panels::page>
