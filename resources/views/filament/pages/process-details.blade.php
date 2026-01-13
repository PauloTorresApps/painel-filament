<x-filament-panels::page>
    <div>
        <style>
            /* Smooth transitions */
            .movimento-item {
                transition: all 0.2s ease-in-out !important;
            }
            .movimento-item.no-documents {
                opacity: 0.7 !important;
            }
            .documento-card {
                transition: all 0.15s ease-in-out !important;
            }
            .documento-card:hover {
                transform: translateX(2px) !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08) !important;
            }

            /* Cores suaves e profissionais */
            .stat-card-neutral {
                background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            }
            .dark .stat-card-neutral {
                background: linear-gradient(135deg, rgba(17, 24, 39, 0.5) 0%, rgba(31, 41, 55, 0.5) 100%);
            }

            /* Badge suave */
            .badge-soft {
                background-color: rgba(99, 102, 241, 0.08);
                color: rgb(79, 70, 229);
            }
            .dark .badge-soft {
                background-color: rgba(129, 140, 248, 0.15);
                color: rgb(165, 180, 252);
            }

            /* Movimento badge */
            .movimento-badge {
                background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
                border: 1px solid #cbd5e1;
            }
            .dark .movimento-badge {
                background: linear-gradient(135deg, rgba(51, 65, 85, 0.4) 0%, rgba(71, 85, 105, 0.4) 100%);
                border: 1px solid rgba(100, 116, 139, 0.3);
            }
        </style>

        {{-- Filtro de movimentos --}}
        <div class="mb-6 flex justify-end gap-3">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                <input
                    type="checkbox"
                    id="hideEmptyMovements"
                    class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600"
                    onchange="toggleEmptyMovements(this.checked)"
                    checked
                >
                <label for="hideEmptyMovements" class="text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer select-none">
                    Ocultar movimentos sem documentos
                </label>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            {{-- Sidebar --}}
            <div class="lg:col-span-1 space-y-6">
                {{-- Informações Básicas --}}
                @if(!empty($dadosBasicos))
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span class="font-semibold">Informações</span>
                            </div>
                        </x-slot>

                        <div class="space-y-4">
                            @if(isset($dadosBasicos['valorCausa']))
                                <div class="p-4 rounded-lg bg-slate-50 dark:bg-slate-900/20 border border-slate-200 dark:border-slate-700">
                                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1">Valor da Causa</p>
                                    <p class="text-xl font-bold text-slate-900 dark:text-slate-100">R$ {{ number_format($dadosBasicos['valorCausa'], 2, ',', '.') }}</p>
                                </div>
                            @endif

                            @if(isset($dadosBasicos['classeProcessual']))
                                <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Classe</p>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $dadosBasicos['classeProcessualNome'] ?? $dadosBasicos['classeProcessual'] }}
                                    </p>
                                    @if(isset($dadosBasicos['classeProcessualNome']))
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Código: {{ $dadosBasicos['classeProcessual'] }}</p>
                                    @endif
                                </div>
                            @endif

                            @if(isset($dadosBasicos['nivelSigilo']))
                                <div class="p-3 rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Nível de Sigilo</p>
                                    <div class="mt-2">
                                        @if($dadosBasicos['nivelSigilo'] == 0)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300">
                                                Público
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                                                Sigiloso
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-filament::section>
                @endif

                {{-- Assuntos --}}
                @if(!empty($dadosBasicos['assunto']))
                    <x-filament::section collapsible>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                                </svg>
                                <span class="font-semibold">Assuntos</span>
                            </div>
                        </x-slot>

                        <ul class="space-y-2">
                            @foreach($dadosBasicos['assunto'] as $assunto)
                                <li class="flex items-start gap-2 p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                                    <svg class="w-4 h-4 mt-0.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <div class="flex-1">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            {{ $assunto['nomeAssunto'] ?? $assunto['descricao'] ?? $assunto['codigoAssunto'] ?? $assunto['codigoNacional'] ?? 'Assunto' }}
                                        </span>
                                        @if(isset($assunto['nomeAssunto']) && (isset($assunto['codigoAssunto']) || isset($assunto['codigoNacional'])))
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                Código: {{ $assunto['codigoAssunto'] ?? $assunto['codigoNacional'] }}
                                            </p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-filament::section>
                @endif

                {{-- Partes --}}
                @if(!empty($dadosBasicos['polo']))
                    <x-filament::section collapsible collapsed>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                                <span class="font-semibold">Partes</span>
                            </div>
                        </x-slot>

                        <div class="space-y-4">
                            @foreach($dadosBasicos['polo'] as $poloItem)
                                @php
                                    $tipoPolo = $poloItem['polo'] ?? $poloItem['@attributes']['polo'] ?? 'N/A';
                                    if (is_array($tipoPolo)) {
                                        $tipoPolo = $tipoPolo['@attributes']['polo'] ?? $tipoPolo[0] ?? 'N/A';
                                    }
                                    $tipoPoloTexto = match($tipoPolo) {
                                        'AT' => 'AUTOR',
                                        'PA' => 'PARTE ADVERSA',
                                        'RE' => 'RÉU',
                                        default => is_string($tipoPolo) ? $tipoPolo : 'N/A'
                                    };
                                    $partes = $poloItem['parte'] ?? [];
                                    if (isset($partes['pessoa'])) {
                                        $partes = [$partes];
                                    }
                                @endphp

                                @foreach($partes as $parte)
                                    @php
                                        $nomePessoa = $parte['pessoa']['dadosBasicos']['nome'] ?? $parte['pessoa']['nome'] ?? 'Nome não disponível';
                                        $cpfCnpj = $parte['pessoa']['numeroDocumentoPrincipal'] ?? $parte['pessoa']['dadosBasicos']['numeroDocumentoPrincipal'] ?? null;
                                        $advogados = isset($parte['advogado']) ? (isset($parte['advogado']['nome']) ? [$parte['advogado']] : $parte['advogado']) : [];
                                    @endphp

                                    <div class="border-l-4 border-slate-300 dark:border-slate-600 pl-4 py-2 bg-gray-50 dark:bg-gray-800/50 rounded-r">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 mb-2">
                                            {{ $tipoPoloTexto }}
                                        </span>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 mt-1">
                                            {{ $nomePessoa }}
                                        </p>
                                        @if($cpfCnpj)
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                {{ strlen($cpfCnpj) === 11 ? 'CPF' : 'CNPJ' }}: {{ $cpfCnpj }}
                                            </p>
                                        @endif

                                        @if(!empty($advogados))
                                            <div class="mt-2 pl-3 border-l-2 border-gray-300 dark:border-gray-600 space-y-1">
                                                @foreach($advogados as $advogado)
                                                    <p class="text-xs text-gray-600 dark:text-gray-400">
                                                        <span class="font-medium">Adv:</span> {{ $advogado['nome'] ?? 'N/A' }}
                                                        @if(isset($advogado['inscricao']))
                                                            <span class="text-gray-500">({{ $advogado['inscricao'] }})</span>
                                                        @endif
                                                    </p>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif

                {{-- Estatísticas --}}
                @if(!empty($movimentos))
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center gap-2 text-gray-900 dark:text-white">
                                <svg class="w-5 h-5 text-info-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                                </svg>
                                <span class="font-semibold">Estatísticas</span>
                            </div>
                        </x-slot>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 dark:bg-slate-900/20 border border-slate-200 dark:border-slate-700">
                                <span class="text-sm font-medium text-slate-600 dark:text-slate-300">Total de Movimentos</span>
                                <span class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ count($movimentos) }}</span>
                            </div>
                            <div class="flex items-center justify-between p-3 rounded-lg bg-sky-50 dark:bg-sky-900/15 border border-sky-200/50 dark:border-sky-800/30">
                                <span class="text-sm font-medium text-sky-700 dark:text-sky-300">Com Documentos</span>
                                <span class="text-2xl font-bold text-sky-900 dark:text-sky-100" id="withDocsCount">{{ count(array_filter($movimentos, fn($m) => !empty($m['documentos']))) }}</span>
                            </div>
                            <div class="flex items-center justify-between p-3 rounded-lg bg-indigo-50 dark:bg-indigo-900/15 border border-indigo-200/50 dark:border-indigo-800/30">
                                <span class="text-sm font-medium text-indigo-700 dark:text-indigo-300">Total de Documentos</span>
                                <span class="text-2xl font-bold text-indigo-900 dark:text-indigo-100">{{ count($documentos ?? []) }}</span>
                            </div>
                        </div>
                    </x-filament::section>
                @endif
            </div>

            {{-- Movimentos (Main Content) --}}
            <div class="lg:col-span-3">
                @if(!empty($movimentos))
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 rounded-lg bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-900 border border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                </svg>
                                Movimentos Processuais
                            </h3>
                            <span class="text-sm font-medium text-gray-500 dark:text-gray-400" id="visibleCount">
                                Exibindo {{ count($movimentos) }} de {{ count($movimentos) }}
                            </span>
                        </div>

                        <div class="space-y-4">
                            @foreach($movimentos as $index => $movimento)
                                @php
                                    $hasDocuments = !empty($movimento['documentos']);
                                @endphp

                                <div class="movimento-item {{ $hasDocuments ? 'has-documents' : 'no-documents' }}" data-has-docs="{{ $hasDocuments ? 'true' : 'false' }}">
                                    <div class="p-6 rounded-lg border {{ $hasDocuments ? 'border-indigo-200 dark:border-indigo-800/40 bg-indigo-50/40 dark:bg-indigo-900/5' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' }} shadow-sm hover:shadow-md transition-all">
                                        <div class="flex items-start gap-4">
                                            {{-- ID do Evento --}}
                                            <div class="flex-shrink-0 mt-1">
                                                <div class="w-12 h-12 bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-700 dark:to-slate-600 rounded-full flex items-center justify-center shadow-sm border border-slate-300 dark:border-slate-500">
                                                    <span class="text-slate-700 dark:text-slate-100 font-bold text-xs leading-tight text-center">
                                                        {{ $movimento['idMovimento'] ?? 'N/A' }}
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="flex-1 min-w-0">
                                                {{-- Título --}}
                                                <div class="flex items-start justify-between gap-4 mb-3">
                                                    <h4 class="text-base font-bold text-gray-900 dark:text-white leading-tight">
                                                        {{ $movimento['movimentoLocal']['descricao'] ?? 'Movimento sem descrição' }}
                                                    </h4>
                                                    @if($hasDocuments)
                                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300 whitespace-nowrap">
                                                            {{ count($movimento['documentos']) }} {{ count($movimento['documentos']) === 1 ? 'documento' : 'documentos' }}
                                                        </span>
                                                    @endif
                                                </div>

                                                @if(isset($movimento['dataHora']))
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5 mb-3">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                        </svg>
                                                        {{ \Carbon\Carbon::parse($movimento['dataHora'])->format('d/m/Y H:i') }}
                                                    </p>
                                                @endif

                                                {{-- Complemento --}}
                                                @if(isset($movimento['complemento']) && !empty($movimento['complemento']))
                                                    <details class="mt-3">
                                                        <summary class="text-sm text-indigo-600 dark:text-indigo-400 cursor-pointer hover:text-indigo-700 dark:hover:text-indigo-300 font-medium">
                                                            + Ver detalhes adicionais
                                                        </summary>
                                                        <div class="mt-3 p-4 bg-slate-50 dark:bg-slate-900/30 rounded-lg border-l-3 border-slate-300 dark:border-slate-600">
                                                            <div class="text-sm text-gray-700 dark:text-gray-300 space-y-1">
                                                                @if(is_array($movimento['complemento']))
                                                                    @foreach($movimento['complemento'] as $comp)
                                                                        <p>{{ $comp }}</p>
                                                                    @endforeach
                                                                @else
                                                                    <p>{{ $movimento['complemento'] }}</p>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </details>
                                                @endif

                                                {{-- Documentos --}}
                                                @if($hasDocuments)
                                                    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                                        <h5 class="text-sm font-bold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                                                            </svg>
                                                            Documentos Anexados
                                                        </h5>
                                                        <div class="space-y-2">
                                                            @foreach($movimento['documentos'] as $documento)
                                                                @php
                                                                    $nivelSigilo = $documento['nivelSigilo'] ?? 0;
                                                                    $isSigiloso = $nivelSigilo > 0;

                                                                    // Verifica se é arquivo de mídia (vídeo ou imagem)
                                                                    $descricao = strtolower($documento['descricao'] ?? '');
                                                                    $mimeType = strtolower($documento['mimetype'] ?? '');

                                                                    // Extensões de mídia comuns
                                                                    $extensoesMedia = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'tiff', 'ico'];

                                                                    $isArquivoMedia = false;

                                                                    // Verifica pelo mimetype
                                                                    if (str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/')) {
                                                                        $isArquivoMedia = true;
                                                                    }

                                                                    // Verifica pela descrição/nome do arquivo
                                                                    foreach ($extensoesMedia as $ext) {
                                                                        if (str_ends_with($descricao, '.' . $ext)) {
                                                                            $isArquivoMedia = true;
                                                                            break;
                                                                        }
                                                                    }
                                                                @endphp
                                                                <div class="documento-card flex items-center gap-3 p-3 rounded-lg border {{ $isSigiloso ? 'border-amber-200 dark:border-amber-800/40 bg-amber-50/50 dark:bg-amber-900/5' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800' }} hover:border-indigo-300 dark:hover:border-indigo-700/40 hover:shadow-md">
                                                                    <div class="flex-shrink-0">
                                                                        <div class="w-10 h-10 {{ $isSigiloso ? 'bg-amber-100 dark:bg-amber-900/20' : 'bg-slate-100 dark:bg-slate-800' }} rounded-lg flex items-center justify-center">
                                                                            @if($isSigiloso)
                                                                                <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                                                                </svg>
                                                                            @else
                                                                                <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="currentColor" viewBox="0 0 24 24">
                                                                                    <path d="M9 2a2 2 0 00-2 2v12a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L13 1.586A2 2 0 0011.586 1H9z"/>
                                                                                </svg>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                    <div class="flex-1 min-w-0">
                                                                        <div class="flex items-center gap-2">
                                                                            <p class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                                                                {{ $documento['descricao'] ?? 'Documento' }}
                                                                            </p>
                                                                            @if($isSigiloso)
                                                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 flex-shrink-0">
                                                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                                                                    </svg>
                                                                                    Sigiloso
                                                                                </span>
                                                                            @endif
                                                                        </div>
                                                                        <div class="flex items-center gap-3 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                                            {{-- DEBUG: Mostra campos disponíveis --}}
                                                                            @php
                                                                                // Log para debug
                                                                                \Log::info('DEBUG Documento na View', [
                                                                                    'keys' => array_keys($documento),
                                                                                    'idDocumento' => $documento['idDocumento'] ?? 'N/A',
                                                                                    'sequencia_analise' => $documento['sequencia_analise'] ?? 'NÃO EXISTE',
                                                                                    'sequencia_analise_type' => isset($documento['sequencia_analise']) ? gettype($documento['sequencia_analise']) : 'N/A'
                                                                                ]);
                                                                            @endphp

                                                                            {{-- Exibe sequência global de análise (1, 2, 3... N) --}}
                                                                            @if(isset($documento['sequencia_analise']))
                                                                                <span class="flex items-center gap-1 font-mono font-bold text-indigo-700 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/20 px-2 py-0.5 rounded" title="Sequência: {{ $documento['sequencia_analise'] }}">
                                                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/>
                                                                                    </svg>
                                                                                    #{{ $documento['sequencia_analise'] }}
                                                                                </span>
                                                                            @else
                                                                                <span class="flex items-center gap-1 font-mono text-xs text-red-600 dark:text-red-400" title="Campo sequencia_analise não existe">
                                                                                    ⚠️ SEM SEQUÊNCIA
                                                                                </span>
                                                                            @endif
                                                                            @if(isset($documento['dataHora']))
                                                                                <span class="flex items-center gap-1">
                                                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                                                    </svg>
                                                                                    {{ \Carbon\Carbon::parse($documento['dataHora'])->format('d/m/Y H:i') }}
                                                                                </span>
                                                                            @endif
                                                                            @if(isset($documento['tamanhoConteudo']) && $documento['tamanhoConteudo'] > 0)
                                                                                <span class="flex items-center gap-1">
                                                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>
                                                                                    </svg>
                                                                                    {{ number_format($documento['tamanhoConteudo'] / 1024, 0) }} KB
                                                                                </span>
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                    <div class="flex items-center gap-2">
                                                                        <button
                                                                            onclick="visualizarDocumento('{{ $numeroProcesso }}', '{{ $documento['idDocumento'] }}')"
                                                                            class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 dark:text-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 rounded-lg transition shadow-sm hover:shadow"
                                                                        >
                                                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                                            </svg>
                                                                            Visualizar
                                                                        </button>

                                                                        @if(!$isArquivoMedia)
                                                                            {{-- Documento não é mídia - pode enviar para análise --}}
                                                                            <button
                                                                                type="button"
                                                                                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 dark:text-indigo-300 dark:bg-indigo-900/20 dark:hover:bg-indigo-900/30 rounded-lg transition shadow-sm hover:shadow border border-indigo-200 dark:border-indigo-800/40"
                                                                                title="Enviar documento para análise"
                                                                            >
                                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                                                                                </svg>
                                                                                Enviar para Análise
                                                                            </button>
                                                                        @else
                                                                            {{-- Arquivo de mídia - não pode ser enviado para análise --}}
                                                                            <span class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-gray-700 bg-gray-100 dark:bg-gray-700 dark:text-gray-300 rounded-lg border border-gray-300 dark:border-gray-600" title="Arquivos de mídia (imagens e vídeos) não podem ser enviados para análise">
                                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                                                </svg>
                                                                                Arquivo de Mídia
                                                                            </span>
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="text-center py-16 bg-gray-50 dark:bg-gray-800 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600">
                        <div class="mx-auto w-16 h-16 bg-gray-200 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">
                            Nenhum movimento encontrado
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Não há movimentos disponíveis para este processo.
                        </p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Modal para visualização de documento --}}
        <div id="documentModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 sm:px-6 lg:px-8 py-8">
                <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" onclick="fecharModal()"></div>

                <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl transform transition-all w-full max-w-7xl mx-auto">
                    <div class="bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Visualização de Documento
                            </h3>
                            <button
                                onclick="fecharModal()"
                                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition rounded-lg p-1 hover:bg-gray-200 dark:hover:bg-gray-700"
                            >
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div id="documentContent" class="p-6" style="min-height: 800px;">
                        <div class="flex items-center justify-center py-12">
                            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
                            <p class="ml-3 text-gray-600 dark:text-gray-400">Carregando documento...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @push('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <script>
        // --- 1. CONFIGURAÇÃO DO WORKER ---
        async function configurarPdfWorker() {
            if (window.pdfWorkerConfigured) return;
            const workerUrl = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
            try {
                const response = await fetch(workerUrl);
                const workerCode = await response.text();
                const blob = new Blob([workerCode], { type: "text/javascript" });
                pdfjsLib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(blob);
                window.pdfWorkerConfigured = true;
            } catch (e) {
                console.error("Erro worker:", e);
                pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;
            }
        }

        // --- 2. VARIÁVEIS GLOBAIS ---
        let currentPdf = null;
        let currentScale = 1.2;
        let currentBlobUrl = null; // Para revogar URLs criados

        // --- 3. AUXILIARES ---
        function escapeHtml(text) {
            if(!text) return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
        }

        function toggleEmptyMovements(hide) {
            const items = document.querySelectorAll('.movimento-item');
            const visibleCount = document.getElementById('visibleCount');
            if (!items.length) return;

            let visible = 0;
            items.forEach(item => {
                const hasDocs = item.dataset.hasDocs === 'true';
                if (hide && !hasDocs) { item.style.display = 'none'; }
                else { item.style.display = 'block'; visible++; }
            });
            if(visibleCount) visibleCount.textContent = `Exibindo ${visible} de ${items.length}`;
        }

        // Função para fechar o modal (garante que limpa a memória)
        function fecharModal() {
            document.getElementById('documentModal').classList.add('hidden');
            currentPdf = null; // Limpa referência

            // Revoga URL do blob para liberar memória
            if (currentBlobUrl) {
                URL.revokeObjectURL(currentBlobUrl);
                currentBlobUrl = null;
            }

            const container = document.getElementById('pdf-viewer-container');
            if (container) {
                container.innerHTML = ''; // Limpa DOM
            }
        }

        // --- RENDERIZAÇÃO ---
        async function renderizarPaginas() {
            const container = document.getElementById('pdf-viewer-container');
            if (!container || !currentPdf) return;

            container.innerHTML = '<div class="py-10 flex justify-center"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div></div>';

            const zoomLabel = document.getElementById('zoom-level');
            if(zoomLabel) zoomLabel.innerText = Math.round(currentScale * 100) + '%';

            const fragment = document.createDocumentFragment();

            for (let pageNum = 1; pageNum <= currentPdf.numPages; pageNum++) {
                const page = await currentPdf.getPage(pageNum);
                const viewport = page.getViewport({ scale: currentScale });

                const pageWrapper = document.createElement('div');
                pageWrapper.className = "mb-3 inline-block shadow-md rounded-sm bg-white relative";

                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                canvas.height = viewport.height;
                canvas.width = viewport.width;
                canvas.style.maxWidth = "100%";
                canvas.style.height = "auto";

                pageWrapper.appendChild(canvas);
                fragment.appendChild(pageWrapper);

                page.render({ canvasContext: context, viewport: viewport });
            }

            container.innerHTML = '';
            container.appendChild(fragment);
        }

        // --- 4. FUNÇÃO PRINCIPAL ---
        async function visualizarDocumento(numeroProcesso, idDocumento) {
            const modal = document.getElementById('documentModal');
            const content = document.getElementById('documentContent');

            if (typeof pdfjsLib === 'undefined') { alert("Erro biblio PDF."); return; }
            await configurarPdfWorker();

            // Reset
            currentPdf = null;
            currentScale = 1.2;

            modal.classList.remove('hidden');
            // Altura reduzida no loading também
            content.innerHTML = `
                <div class="flex flex-col items-center justify-center h-[400px]">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-600 mb-3"></div>
                    <p class="text-gray-500 font-medium text-sm">Carregando...</p>
                </div>`;

            fetch('{{ route("eproc.visualizar") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({
                    numero_processo: numeroProcesso,
                    id_documento: idDocumento,
                    judicial_user_id: {{ $judicialUserId ?? 'null' }},
                    password_ws: '{{ $senha ?? "" }}'
                })
            })
            .then(r => r.json())
            .then(async data => {
                if (data.success && data.conteudoBase64) {
                    const docTitle = escapeHtml(data.documento.descricao || 'Documento');

                    // MUDANÇAS AQUI:
                    // 1. Altura ajustada para h-[80vh] (deixa espaço para clicar fora)
                    // 2. Adicionado botão de FECHAR (X) na barra superior
                    content.innerHTML = `
                        <div class="flex flex-col h-[80vh] bg-gray-100 dark:bg-gray-900 rounded-lg overflow-hidden">

                            <div class="flex items-center justify-between p-2 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm shrink-0">

                                 <div class="flex items-center gap-3 overflow-hidden">
                                     <div class="flex items-center gap-1 bg-gray-100 dark:bg-gray-700 rounded-lg p-1">
                                        <button onclick="mudarZoom(-0.2)" class="p-1 hover:bg-gray-200 dark:hover:bg-gray-600 rounded text-gray-600 dark:text-gray-300">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                                        </button>
                                        <span id="zoom-level" class="text-xs font-mono w-10 text-center font-bold text-gray-600 dark:text-gray-300">120%</span>
                                        <button onclick="mudarZoom(0.2)" class="p-1 hover:bg-gray-200 dark:hover:bg-gray-600 rounded text-gray-600 dark:text-gray-300">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                        </button>
                                     </div>
                                     <span class="font-bold text-gray-700 dark:text-gray-200 truncate text-sm" title="${docTitle}">${docTitle}</span>
                                 </div>

                                 <div class="flex items-center gap-2">
                                     <button id="btn-download-pdf" class="flex items-center gap-1 text-xs font-bold bg-primary-600 hover:bg-primary-700 text-white px-3 py-1.5 rounded transition shadow" title="Baixar PDF">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        <span class="hidden sm:inline">Baixar</span>
                                     </button>

                                     <button onclick="fecharModal()" class="p-1.5 text-gray-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-full transition" title="Fechar Visualização">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                     </button>
                                 </div>
                            </div>

                            <div id="pdf-viewer-container" class="flex-1 overflow-y-auto p-2 text-center bg-gray-300 dark:bg-gray-900 scroll-smooth">
                                <div class="animate-pulse flex justify-center mt-4">
                                    <div class="h-64 w-full bg-gray-200 dark:bg-gray-700 rounded"></div>
                                </div>
                            </div>
                        </div>
                    `;

                    // Processamento
                    const base64Clean = data.conteudoBase64.replace(/^data:application\/pdf;base64,/, "").replace(/\s/g, '');
                    const binaryString = atob(base64Clean);
                    const bytes = new Uint8Array(binaryString.length);
                    for (let i = 0; i < binaryString.length; i++) { bytes[i] = binaryString.charCodeAt(i); }

                    // Setup Download
                    const blob = new Blob([bytes], { type: 'application/pdf' });

                    // Revoga URL anterior se existir
                    if (currentBlobUrl) {
                        URL.revokeObjectURL(currentBlobUrl);
                    }

                    currentBlobUrl = URL.createObjectURL(blob);
                    document.getElementById('btn-download-pdf').onclick = () => {
                        const link = document.createElement('a');
                        link.href = currentBlobUrl;
                        link.download = `${docTitle}.pdf`;
                        link.click();
                    };

                    try {
                        currentPdf = await pdfjsLib.getDocument({ data: bytes }).promise;
                        await renderizarPaginas();
                    } catch (renderError) {
                        console.error(renderError);
                        document.getElementById('pdf-viewer-container').innerHTML = `<div class="p-4 text-center text-red-500 text-sm">Erro: ${renderError.message}</div>`;
                    }
                } else {
                    content.innerHTML = '<div class="flex items-center justify-center h-[200px] text-gray-500">Sem conteúdo.</div>';
                }
            })
            .catch(error => {
                console.error(error);
                content.innerHTML = '<div class="flex items-center justify-center h-[200px] text-red-500">Erro de conexão.</div>';
            });
        }

        function mudarZoom(delta) {
            if (!currentPdf) return;
            const novoZoom = currentScale + delta;
            if (novoZoom >= 0.5 && novoZoom <= 3.0) {
                currentScale = novoZoom;
                renderizarPaginas();
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleEmptyMovements(true);
        });
    </script>
@endpush
</x-filament-panels::page>
