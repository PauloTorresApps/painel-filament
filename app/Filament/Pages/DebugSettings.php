<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DebugSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected string $view = 'filament.pages.debug-settings';

    protected static ?string $title = 'Configurações de Debug';

    protected static ?string $navigationLabel = 'Debug';

    protected static string|\UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 100;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'debug_save_analysis_files' => Setting::isDebugAnalysisFilesEnabled(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Arquivos de Debug de Análises')
                    ->description('Controle a geração de arquivos de debug durante o processamento de análises de documentos.')
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Placeholder::make('info')
                            ->label('')
                            ->content('Quando ativado, o sistema salva arquivos .md com os detalhes de cada etapa do processamento de análises de documentos. Estes arquivos são úteis para:')
                            ->columnSpanFull(),

                        Placeholder::make('benefits')
                            ->label('')
                            ->content(fn () => new \Illuminate\Support\HtmlString('
                                <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-400 space-y-1 ml-4">
                                    <li><strong>Fase MAP:</strong> Salva a análise individual de cada documento do processo</li>
                                    <li><strong>Fase REDUCE:</strong> Salva as consolidações em batches das micro-análises</li>
                                    <li><strong>Parecer Final:</strong> Salva o parecer final gerado pela IA</li>
                                </ul>
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-500">
                                    <strong>Local dos arquivos:</strong> <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">storage/app/private/analises-debug/{processo}/analysis_{id}/</code>
                                </p>
                            '))
                            ->columnSpanFull(),

                        Toggle::make('debug_save_analysis_files')
                            ->label('Salvar arquivos de debug das análises')
                            ->helperText('Ative para gerar arquivos .md com prompts, respostas e metadados de cada etapa da análise.')
                            ->onColor('success')
                            ->offColor('danger')
                            ->inline(false),

                        Placeholder::make('warning')
                            ->label('')
                            ->content(fn () => new \Illuminate\Support\HtmlString('
                                <div class="flex items-start gap-2 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg border border-amber-200 dark:border-amber-800">
                                    <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                    <div class="text-sm text-amber-700 dark:text-amber-300">
                                        <strong>Atenção:</strong> Manter esta opção ativada em produção pode consumir espaço em disco significativo ao longo do tempo. Recomenda-se ativar apenas para diagnóstico e depuração.
                                    </div>
                                </div>
                            '))
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set(
            'debug_save_analysis_files',
            $data['debug_save_analysis_files'] ?? false,
            'boolean',
            'debug',
            'Quando ativado, salva arquivos de debug (.md) com os resultados das análises de documentos (MAP), consolidações (REDUCE) e parecer final no diretório storage/app/private/analises-debug/'
        );

        Notification::make()
            ->title('Configurações salvas')
            ->body('As configurações de debug foram atualizadas com sucesso.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('Salvar Configurações')
                ->submit('save'),
        ];
    }
}
