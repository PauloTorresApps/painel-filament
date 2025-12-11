<x-layouts.app title="Detalhes do Processo">
    <div class="flex flex-col gap-4 max-w-7xl mx-auto">
        <!-- Header com ações -->
        <div class="flex items-center justify-between sticky top-0 z-10 bg-white dark:bg-neutral-900 py-4 border-b border-neutral-200 dark:border-neutral-700">
            <div class="flex-1">
                <h1 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $numeroProcesso }}</h1>
                @if(!empty($dadosBasicos['dataAjuizamento']))
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Ajuizado em {{ \Carbon\Carbon::parse($dadosBasicos['dataAjuizamento'])->format('d/m/Y') }}
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <!-- Toggle para ocultar movimentos sem documentos -->
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
                    <input
                        type="checkbox"
                        id="hideEmptyMovements"
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        onchange="toggleEmptyMovements(this.checked)"
                        checked
                    >
                    <span>Ocultar sem documentos</span>
                </label>

                <flux:button href="{{ route('eproc') }}" variant="subtle" size="sm">
                    Voltar
                </flux:button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
            <!-- Sidebar com informações -->
            <div class="lg:col-span-1 space-y-3">
                <!-- Informações Básicas (Collapsible) -->
                @if(!empty($dadosBasicos))
                    <details class="group bg-white dark:bg-neutral-900 rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <summary class="px-4 py-3 cursor-pointer flex items-center justify-between hover:bg-gray-50 dark:hover:bg-neutral-800 rounded-lg transition">
                            <span class="font-medium text-sm text-gray-900 dark:text-white">Informações do Processo</span>
                            <svg class="w-4 h-4 text-gray-500 transition group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </summary>
                        <div class="px-4 pb-3 pt-2 space-y-3 text-xs border-t border-neutral-100 dark:border-neutral-800">
                            @if(isset($dadosBasicos['valorCausa']))
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">Valor da Causa</p>
                                    <p class="font-medium text-gray-900 dark:text-white">R$ {{ number_format($dadosBasicos['valorCausa'], 2, ',', '.') }}</p>
                                </div>
                            @endif

                            @if(isset($dadosBasicos['classeProcessual']))
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">Classe</p>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $dadosBasicos['classeProcessual'] }}</p>
                                </div>
                            @endif

                            @if(isset($dadosBasicos['nivelSigilo']))
                                <div>
                                    <p class="text-gray-500 dark:text-gray-400">Sigilo</p>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $dadosBasicos['nivelSigilo'] == 0 ? 'Público' : 'Sigiloso' }}</p>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif

                <!-- Assuntos (Collapsible) -->
                @if(!empty($dadosBasicos['assunto']))
                    <details class="group bg-white dark:bg-neutral-900 rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <summary class="px-4 py-3 cursor-pointer flex items-center justify-between hover:bg-gray-50 dark:hover:bg-neutral-800 rounded-lg transition">
                            <span class="font-medium text-sm text-gray-900 dark:text-white">Assuntos</span>
                            <svg class="w-4 h-4 text-gray-500 transition group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </summary>
                        <div class="px-4 pb-3 pt-2 space-y-1 text-xs border-t border-neutral-100 dark:border-neutral-800">
                            @foreach($dadosBasicos['assunto'] as $assunto)
                                <p class="text-gray-600 dark:text-gray-400">• {{ $assunto['descricao'] ?? $assunto['codigoAssunto'] ?? 'Assunto' }}</p>
                            @endforeach
                        </div>
                    </details>
                @endif

                <!-- Partes (Collapsible, Collapsed by Default) -->
                @if(!empty($dadosBasicos['polo']))
                    <details class="group bg-white dark:bg-neutral-900 rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <summary class="px-4 py-3 cursor-pointer flex items-center justify-between hover:bg-gray-50 dark:hover:bg-neutral-800 rounded-lg transition">
                            <span class="font-medium text-sm text-gray-900 dark:text-white">Partes</span>
                            <svg class="w-4 h-4 text-gray-500 transition group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </summary>
                        <div class="px-4 pb-3 pt-2 space-y-3 border-t border-neutral-100 dark:border-neutral-800">
                            @foreach($dadosBasicos['polo'] as $poloItem)
                                @php
                                    // O atributo 'polo' pode vir como string ou array (quando é atributo XML)
                                    $tipoPolo = $poloItem['polo'] ?? $poloItem['@attributes']['polo'] ?? 'N/A';

                                    // Se veio como array, pega o primeiro valor ou o atributo
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
                                        $nomePessoa = $parte['pessoa']['dadosBasicos']['nome'] ??
                                                     $parte['pessoa']['nome'] ??
                                                     'Nome não disponível';

                                        $cpfCnpj = $parte['pessoa']['numeroDocumentoPrincipal'] ??
                                                  $parte['pessoa']['dadosBasicos']['numeroDocumentoPrincipal'] ??
                                                  null;

                                        $advogados = isset($parte['advogado']) ?
                                                    (isset($parte['advogado']['nome']) ? [$parte['advogado']] : $parte['advogado']) :
                                                    [];
                                    @endphp

                                    <div class="border-l-2 border-blue-500 pl-3 py-1">
                                        <p class="font-semibold text-blue-700 dark:text-blue-400 text-xs mb-1">
                                            {{ $tipoPoloTexto }}
                                        </p>
                                        <p class="text-xs font-medium text-gray-900 dark:text-gray-100 leading-tight">
                                            {{ $nomePessoa }}
                                        </p>
                                        @if($cpfCnpj)
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                {{ strlen($cpfCnpj) === 11 ? 'CPF' : 'CNPJ' }}: {{ $cpfCnpj }}
                                            </p>
                                        @endif

                                        @if(!empty($advogados))
                                            <div class="mt-1.5 pl-2 border-l border-gray-300 dark:border-gray-600">
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
                    </details>
                @endif

                <!-- Estatísticas -->
                @if(!empty($movimentos))
                    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-950/30 dark:to-indigo-950/30 rounded-lg border border-blue-200 dark:border-blue-800 p-4">
                        <p class="text-xs text-blue-900 dark:text-blue-300 font-medium mb-2">Estatísticas</p>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-blue-700 dark:text-blue-400">Total de Movimentos</span>
                                <span class="text-sm font-bold text-blue-900 dark:text-blue-200">{{ count($movimentos) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-blue-700 dark:text-blue-400">Com Documentos</span>
                                <span class="text-sm font-bold text-blue-900 dark:text-blue-200" id="withDocsCount">{{ count(array_filter($movimentos, fn($m) => !empty($m['documentos']))) }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-xs text-blue-700 dark:text-blue-400">Total de Documentos</span>
                                <span class="text-sm font-bold text-blue-900 dark:text-blue-200">{{ count($documentos ?? []) }}</span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Movimentos (Main Content) -->
            <div class="lg:col-span-3">
                @if(!empty($movimentos))
                    <div class="space-y-2">
                        <div class="flex items-center justify-between mb-3">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                                Movimentos Processuais
                            </h2>
                            <span class="text-xs text-gray-500 dark:text-gray-400" id="visibleCount">
                                Exibindo {{ count($movimentos) }} de {{ count($movimentos) }}
                            </span>
                        </div>

                        @foreach($movimentos as $index => $movimento)
                            @php
                                $hasDocuments = !empty($movimento['documentos']);
                            @endphp

                            <div class="movimento-item {{ $hasDocuments ? 'has-documents' : 'no-documents' }}" data-has-docs="{{ $hasDocuments ? 'true' : 'false' }}">
                                <!-- Movimento Compacto -->
                                <div class="bg-white dark:bg-neutral-900 rounded-lg border {{ $hasDocuments ? 'border-blue-300 dark:border-blue-700' : 'border-neutral-200 dark:border-neutral-700' }} overflow-hidden hover:shadow-md transition">
                                    <!-- Header do Movimento -->
                                    <div class="px-3 py-2 flex items-start gap-3 {{ $hasDocuments ? 'bg-blue-50 dark:bg-blue-950/20' : '' }}">
                                        <div class="flex-shrink-0 mt-0.5">
                                            @if($hasDocuments)
                                                <div class="w-6 h-6 bg-blue-600 dark:bg-blue-500 rounded-full flex items-center justify-center">
                                                    <svg class="w-3.5 h-3.5 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                        <path d="M9 2a2 2 0 00-2 2v8a2 2 0 002 2h6a2 2 0 002-2V6.414A2 2 0 0016.414 5L14 2.586A2 2 0 0012.586 2H9z"/>
                                                        <path d="M3 8a2 2 0 012-2v10h8a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/>
                                                    </svg>
                                                </div>
                                            @else
                                                <div class="w-6 h-6 bg-gray-300 dark:bg-gray-700 rounded-full flex items-center justify-center">
                                                    <div class="w-2 h-2 bg-gray-500 dark:bg-gray-500 rounded-full"></div>
                                                </div>
                                            @endif
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 dark:text-white leading-tight">
                                                {{ $movimento['movimentoLocal']['descricao'] ?? 'Movimento sem descrição' }}
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                {{ \Carbon\Carbon::parse($movimento['dataHora'])->format('d/m/Y H:i') }}
                                                @if($hasDocuments)
                                                    <span class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                        {{ count($movimento['documentos']) }} doc{{ count($movimento['documentos']) > 1 ? 's' : '' }}
                                                    </span>
                                                @endif
                                            </p>

                                            @if(isset($movimento['complemento']) && !empty($movimento['complemento']))
                                                <details class="mt-1.5">
                                                    <summary class="text-xs text-blue-600 dark:text-blue-400 cursor-pointer hover:underline">
                                                        Ver detalhes
                                                    </summary>
                                                    <div class="mt-1 text-xs text-gray-600 dark:text-gray-400 space-y-0.5 pl-2 border-l-2 border-gray-200 dark:border-gray-700">
                                                        @if(is_array($movimento['complemento']))
                                                            @foreach($movimento['complemento'] as $comp)
                                                                <p>{{ $comp }}</p>
                                                            @endforeach
                                                        @else
                                                            <p>{{ $movimento['complemento'] }}</p>
                                                        @endif
                                                    </div>
                                                </details>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Documentos -->
                                    @if($hasDocuments)
                                        <div class="px-3 pb-2 pt-1 border-t border-blue-100 dark:border-blue-900/30 bg-white dark:bg-neutral-900">
                                            <div class="space-y-1.5">
                                                @foreach($movimento['documentos'] as $documento)
                                                    <div class="flex items-center gap-2 p-2 rounded hover:bg-gray-50 dark:hover:bg-neutral-800 transition group">
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-medium text-gray-900 dark:text-white truncate">
                                                                {{ $documento['descricao'] ?? 'Documento' }}
                                                            </p>
                                                            <div class="flex items-center gap-2 mt-0.5">
                                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                                    {{ \Carbon\Carbon::parse($documento['dataHora'])->format('d/m/Y H:i') }}
                                                                </span>
                                                                @if(isset($documento['tamanhoConteudo']))
                                                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                                                        • {{ number_format($documento['tamanhoConteudo'] / 1024, 0) }} KB
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <button
                                                            onclick="visualizarDocumento('{{ $numeroProcesso }}', '{{ $documento["idDocumento"] }}')"
                                                            class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 rounded transition"
                                                        >
                                                            Visualizar
                                                        </button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="bg-white dark:bg-neutral-900 rounded-lg border border-neutral-200 dark:border-neutral-700 p-8 text-center">
                        <p class="text-gray-500 dark:text-gray-400">Nenhum movimento disponível para este processo.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Modal para visualização de documento -->
    <div id="documentModal" class="fixed inset-0 z-50 hidden" style="padding: 16px;">
        <div class="absolute inset-0 bg-black bg-opacity-50" style="margin: -16px;"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-lg overflow-hidden flex flex-col shadow-2xl" style="width: 100%; height: 100%;">
            <div class="flex items-center justify-between px-6 py-4 border-b dark:border-gray-700 bg-gray-50 dark:bg-gray-900 flex-shrink-0">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Visualização de Documento</h3>
                <button onclick="fecharModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div id="documentContent" class="flex-1 min-h-0 overflow-hidden flex flex-col">
                <div class="flex items-center justify-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle para ocultar/mostrar movimentos sem documentos
        function toggleEmptyMovements(hide) {
            const items = document.querySelectorAll('.movimento-item');
            const visibleCount = document.getElementById('visibleCount');
            let visible = 0;

            items.forEach(item => {
                const hasDocs = item.dataset.hasDocs === 'true';
                if (hide && !hasDocs) {
                    item.style.display = 'none';
                } else {
                    item.style.display = 'block';
                    visible++;
                }
            });

            visibleCount.textContent = `Exibindo ${visible} de ${items.length}`;
        }

        // Aplica o filtro ao carregar a página (checkbox vem marcado por padrão)
        document.addEventListener('DOMContentLoaded', function() {
            toggleEmptyMovements(true);
        });

        // Função para converter base64 em Blob
        function base64ToBlob(base64, contentType = '') {
            const byteCharacters = atob(base64);
            const byteArrays = [];

            for (let offset = 0; offset < byteCharacters.length; offset += 512) {
                const slice = byteCharacters.slice(offset, offset + 512);
                const byteNumbers = new Array(slice.length);

                for (let i = 0; i < slice.length; i++) {
                    byteNumbers[i] = slice.charCodeAt(i);
                }

                const byteArray = new Uint8Array(byteNumbers);
                byteArrays.push(byteArray);
            }

            return new Blob(byteArrays, { type: contentType });
        }

        function visualizarDocumento(numeroProcesso, idDocumento) {
            const modal = document.getElementById('documentModal');
            const content = document.getElementById('documentContent');

            modal.classList.remove('hidden');
            modal.classList.add('block');
            content.innerHTML = '<div class="flex items-center justify-center py-12"><div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div></div>';

            fetch('{{ route("eproc.visualizar") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    numero_processo: numeroProcesso,
                    id_documento: idDocumento
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const doc = data.documento;
                    const tamanho = doc.tamanhoConteudo ? (doc.tamanhoConteudo / 1024).toFixed(2) : 'N/A';
                    const mimetype = doc.conteudo?.mimetype || 'Não especificado';
                    const hash = doc.conteudo?.hash?.hash || 'Não disponível';
                    const algoritmo = doc.conteudo?.hash?.algoritmo || '';

                    // Verifica se tem conteúdo base64 para renderizar o PDF
                    if (data.temConteudo && data.conteudoBase64) {
                        const pdfBase64 = data.conteudoBase64;
                        const pdfBlob = base64ToBlob(pdfBase64, 'application/pdf');
                        const pdfUrl = URL.createObjectURL(pdfBlob);

                        content.innerHTML = `
                            <div class="flex items-center justify-between px-6 py-3 bg-blue-50 dark:bg-blue-900/20 border-b border-blue-200 dark:border-blue-800 flex-shrink-0">
                                <h3 class="text-base font-semibold text-blue-900 dark:text-blue-100">
                                    ${doc.descricao || 'Documento'}
                                </h3>
                                <a href="${pdfUrl}" download="${doc.descricao || 'documento'}.pdf"
                                   class="px-3 py-1.5 text-sm font-medium text-white bg-green-600 hover:bg-green-700 dark:bg-green-500 dark:hover:bg-green-600 rounded transition flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                    Download
                                </a>
                            </div>
                            <div class="flex-1 min-h-0 bg-white dark:bg-gray-800">
                                <iframe
                                    src="${pdfUrl}"
                                    class="w-full h-full"
                                    frameborder="0"
                                    title="Visualização do PDF"
                                ></iframe>
                            </div>
                        `;
                    } else {
                        // Mostra apenas metadados se não tiver conteúdo
                        content.innerHTML = `
                            <div class="space-y-4">
                                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                    <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">
                                        ${doc.descricao || 'Documento'}
                                    </h3>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">ID do Documento:</span>
                                            <p class="text-gray-600 dark:text-gray-400 font-mono text-xs mt-1">${doc.idDocumento}</p>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">Tipo:</span>
                                            <p class="text-gray-600 dark:text-gray-400 mt-1">${mimetype}</p>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">Tamanho:</span>
                                            <p class="text-gray-600 dark:text-gray-400 mt-1">${tamanho} KB</p>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700 dark:text-gray-300">Nível de Sigilo:</span>
                                            <p class="text-gray-600 dark:text-gray-400 mt-1">${doc.nivelSigilo || '0'}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-4">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Hash ${algoritmo}</h4>
                                    <p class="text-xs font-mono text-gray-600 dark:text-gray-400 break-all">${hash}</p>
                                </div>

                                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                                    <div class="flex items-start gap-3">
                                        <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <div class="text-sm text-amber-800 dark:text-amber-200">
                                            <p class="font-medium mb-1">Conteúdo não disponível</p>
                                            <p>O conteúdo completo do documento não foi retornado pela API. Apenas os metadados estão disponíveis.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                } else {
                    content.innerHTML = `
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-4">
                            <p class="text-red-800 dark:text-red-200">Erro ao carregar documento: ${data.error}</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                content.innerHTML = `
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded p-4">
                        <p class="text-red-800 dark:text-red-200">Erro ao carregar documento: ${error.message}</p>
                    </div>
                `;
            });
        }

        function fecharModal() {
            const modal = document.getElementById('documentModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Fechar modal ao clicar fora
        document.getElementById('documentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });

        // Atalho ESC para fechar modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                fecharModal();
            }
        });
    </script>
</x-layouts.app>
