<x-layouts.app title="Consulta de Processos E-Proc">
    <div class="flex flex-col gap-6 max-w-4xl">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Consulta de Processos E-Proc</h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Digite o número do processo para consultar informações, movimentos e documentos.</p>
        </div>

        @if($errors->any())
            <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <div class="space-y-2">
                        @foreach($errors->all() as $error)
                            <p class="text-sm text-red-800 dark:text-red-200">{{ $error }}</p>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-red-600 dark:text-red-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <p class="text-sm text-red-800 dark:text-red-200">{{ session('error') }}</p>
                </div>
            </div>
        @endif

        @if(session('success'))
            <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-green-600 dark:text-green-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <p class="text-sm text-green-800 dark:text-green-200">{{ session('success') }}</p>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 p-6 bg-white dark:bg-neutral-900">
            <form action="{{ route('eproc.consultar') }}" method="POST" class="space-y-6">
                @csrf

                <div>
                    <flux:input
                        name="numero_processo"
                        label="Número do Processo"
                        placeholder="0000000-00.0000.0.00.0000"
                        value="{{ old('numero_processo') }}"
                        required
                    />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Digite o número completo do processo</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <flux:input
                        type="date"
                        name="data_inicial"
                        label="Data Inicial (Opcional)"
                        value="{{ old('data_inicial') }}"
                    />

                    <flux:input
                        type="date"
                        name="data_final"
                        label="Data Final (Opcional)"
                        value="{{ old('data_final') }}"
                    />
                </div>

                <div class="flex justify-end pt-4">
                    <flux:button type="submit" variant="primary">
                        Consultar Processo
                    </flux:button>
                </div>
            </form>
        </div>

        <div class="rounded-xl border border-neutral-200 dark:border-neutral-700 p-6 bg-white dark:bg-neutral-900">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Informações</h2>
            <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                <p>Este sistema permite consultar processos judiciais via webservice SOAP.</p>
                <p class="font-medium text-gray-700 dark:text-gray-300 mt-3">Funcionalidades:</p>
                <ul class="list-disc list-inside ml-4 space-y-1">
                    <li>Consulta de dados do processo</li>
                    <li>Visualização de movimentos processuais</li>
                    <li>Lista de documentos vinculados</li>
                    <li>Visualização da íntegra dos documentos</li>
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
