<?php

namespace App\Filament\Analises\Pages;

use App\Jobs\AnalyzeContractJob;
use App\Jobs\GenerateLegalOpinionJob;
use App\Models\ContractAnalysis as ContractAnalysisModel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use UnitEnum;

class ContractAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';
    protected string $view = 'filament.pages.contract-analysis';
    protected static ?string $navigationLabel = 'Análise de Contratos';
    protected static ?string $title = 'Análise de Contratos';
    protected static UnitEnum|string|null $navigationGroup = 'Contratos';
    protected static ?int $navigationSort = 1;

    // Propriedades do upload
    public ?string $uploadedFilePath = null;
    public ?string $uploadedFileName = null;
    public ?int $uploadedFileSize = null;
    public bool $isAnalyzing = false;

    // Nome da parte interessada
    public ?string $interestedPartyName = null;

    // Última análise
    public ?ContractAnalysisModel $latestAnalysis = null;

    // Flag para indicar geração de parecer em andamento
    public bool $isGeneratingLegalOpinion = false;

    /**
     * Controle de acesso - apenas Admin, Manager ou Analista de Contrato
     */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->hasRole(['Admin', 'Manager', 'Analista de Contrato']);
    }

    /**
     * Mount - carrega última análise do usuário
     */
    public function mount(): void
    {
        $this->loadLatestAnalysis();
    }

    /**
     * Carrega a última análise do usuário
     */
    public function loadLatestAnalysis(): void
    {
        $this->latestAnalysis = ContractAnalysisModel::where('user_id', Auth::id())
            ->latest()
            ->first();
    }

    /**
     * Recebe evento de upload completo do FilePond
     */
    #[On('contract-uploaded')]
    public function handleUploadComplete(string $filePath, string $fileName, int $fileSize): void
    {
        $this->uploadedFilePath = $filePath;
        $this->uploadedFileName = $fileName;
        $this->uploadedFileSize = $fileSize;

        Log::info('Upload de contrato recebido na página', [
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize
        ]);
    }

    /**
     * Recebe evento de remoção do arquivo
     */
    #[On('contract-removed')]
    public function handleUploadRemoved(): void
    {
        // Remove o arquivo se existir
        if ($this->uploadedFilePath && Storage::exists($this->uploadedFilePath)) {
            Storage::delete($this->uploadedFilePath);
        }

        $this->uploadedFilePath = null;
        $this->uploadedFileName = null;
        $this->uploadedFileSize = null;
    }

    /**
     * Ações do header da página
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('analyzeContract')
                ->label('Analisar Contrato')
                ->icon('heroicon-o-sparkles')
                ->size('lg')
                ->disabled(fn () => !$this->uploadedFilePath || $this->isAnalyzing)
                ->form([
                    TextInput::make('interested_party_name')
                        ->label('Nome da Parte Interessada')
                        ->placeholder('Informe o nome da parte interessada (conforme consta no contrato)')
                        ->helperText('Opcional: Informe o nome da parte que está solicitando a análise, caso esteja definido no contrato.')
                        ->maxLength(255),
                ])
                ->modalHeading('Análise de Contrato')
                ->modalDescription('Informe os dados para iniciar a análise do contrato.')
                ->modalSubmitActionLabel('Iniciar Análise')
                ->action(function (array $data): void {
                    $this->executeAnalysis($data['interested_party_name'] ?? null);
                }),
        ];
    }

    /**
     * Executa a análise do contrato
     */
    public function executeAnalysis(?string $interestedPartyName = null): void
    {
        if (!$this->uploadedFilePath) {
            Notification::make()
                ->title('Nenhum arquivo selecionado')
                ->body('Por favor, faça upload de um contrato PDF antes de iniciar a análise.')
                ->warning()
                ->send();
            return;
        }

        // Verifica se o arquivo existe
        if (!Storage::exists($this->uploadedFilePath)) {
            Notification::make()
                ->title('Arquivo não encontrado')
                ->body('O arquivo enviado não foi encontrado. Por favor, faça o upload novamente.')
                ->danger()
                ->send();

            $this->uploadedFilePath = null;
            $this->uploadedFileName = null;
            $this->uploadedFileSize = null;
            return;
        }

        // Verifica se já existe análise em andamento
        $existingProcessing = ContractAnalysisModel::where('user_id', Auth::id())
            ->where('status', 'processing')
            ->exists();

        if ($existingProcessing) {
            Notification::make()
                ->title('Análise em andamento')
                ->body('Você já possui uma análise de contrato em processamento. Aguarde a conclusão.')
                ->warning()
                ->send();
            return;
        }

        try {
            // Cria registro da análise
            $analysis = ContractAnalysisModel::create([
                'user_id' => Auth::id(),
                'file_name' => $this->uploadedFileName,
                'file_path' => $this->uploadedFilePath,
                'file_size' => $this->uploadedFileSize ?? 0,
                'interested_party_name' => $interestedPartyName,
                'status' => ContractAnalysisModel::STATUS_PENDING,
            ]);

            // Dispara o job de análise
            AnalyzeContractJob::dispatch($analysis->id);

            $this->isAnalyzing = true;

            Notification::make()
                ->title('Análise iniciada')
                ->body("O contrato '{$this->uploadedFileName}' foi enviado para análise. Você será notificado quando concluir.")
                ->success()
                ->send();

            // Limpa os dados do upload
            $this->uploadedFilePath = null;
            $this->uploadedFileName = null;
            $this->uploadedFileSize = null;

            // Recarrega última análise
            $this->loadLatestAnalysis();

            Log::info('Análise de contrato iniciada', [
                'analysis_id' => $analysis->id,
                'user_id' => Auth::id(),
                'interested_party_name' => $interestedPartyName
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao iniciar análise de contrato', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Notification::make()
                ->title('Erro ao iniciar análise')
                ->body('Ocorreu um erro ao processar sua solicitação. Tente novamente.')
                ->danger()
                ->send();
        }
    }

    /**
     * Atualiza status da análise (polling)
     */
    public function refreshAnalysisStatus(): void
    {
        $this->loadLatestAnalysis();

        if ($this->latestAnalysis) {
            if ($this->latestAnalysis->isCompleted() || $this->latestAnalysis->isCancelled()) {
                $this->isAnalyzing = false;
            }

            if ($this->latestAnalysis->isLegalOpinionCompleted() || $this->latestAnalysis->isLegalOpinionFailed() || $this->latestAnalysis->isLegalOpinionCancelled()) {
                $this->isGeneratingLegalOpinion = false;
            }
        }
    }

    /**
     * Cancela a análise em andamento
     */
    public function cancelAnalysis(): void
    {
        if (!$this->latestAnalysis) {
            Notification::make()
                ->title('Nenhuma análise encontrada')
                ->body('Não há análise para cancelar.')
                ->warning()
                ->send();
            return;
        }

        if (!$this->latestAnalysis->canBeCancelled()) {
            Notification::make()
                ->title('Não é possível cancelar')
                ->body('A análise já foi concluída ou já foi cancelada.')
                ->warning()
                ->send();
            return;
        }

        try {
            // Remove o arquivo se existir
            if ($this->latestAnalysis->file_path && Storage::exists($this->latestAnalysis->file_path)) {
                Storage::delete($this->latestAnalysis->file_path);
            }

            $this->latestAnalysis->markAsCancelled();
            $this->isAnalyzing = false;

            Notification::make()
                ->title('Análise cancelada')
                ->body('A análise foi cancelada com sucesso.')
                ->success()
                ->send();

            $this->loadLatestAnalysis();

            Log::info('Análise de contrato cancelada', [
                'analysis_id' => $this->latestAnalysis->id,
                'user_id' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar análise', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Notification::make()
                ->title('Erro ao cancelar')
                ->body('Ocorreu um erro ao cancelar a análise.')
                ->danger()
                ->send();
        }
    }

    /**
     * Cancela a geração do parecer jurídico em andamento
     */
    public function cancelLegalOpinion(): void
    {
        if (!$this->latestAnalysis) {
            Notification::make()
                ->title('Nenhuma análise encontrada')
                ->body('Não há parecer para cancelar.')
                ->warning()
                ->send();
            return;
        }

        if (!$this->latestAnalysis->canLegalOpinionBeCancelled()) {
            Notification::make()
                ->title('Não é possível cancelar')
                ->body('O parecer já foi concluído ou já foi cancelado.')
                ->warning()
                ->send();
            return;
        }

        try {
            $this->latestAnalysis->markLegalOpinionAsCancelled();
            $this->isGeneratingLegalOpinion = false;

            Notification::make()
                ->title('Geração de parecer cancelada')
                ->body('A geração do parecer jurídico foi cancelada.')
                ->success()
                ->send();

            $this->loadLatestAnalysis();

            Log::info('Geração de parecer jurídico cancelada', [
                'analysis_id' => $this->latestAnalysis->id,
                'user_id' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar geração de parecer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Notification::make()
                ->title('Erro ao cancelar')
                ->body('Ocorreu um erro ao cancelar a geração do parecer.')
                ->danger()
                ->send();
        }
    }

    /**
     * Gera o parecer jurídico a partir da análise
     */
    public function generateLegalOpinion(): void
    {
        if (!$this->latestAnalysis) {
            Notification::make()
                ->title('Nenhuma análise encontrada')
                ->body('É necessário ter uma análise concluída para gerar o parecer jurídico.')
                ->warning()
                ->send();
            return;
        }

        if (!$this->latestAnalysis->canGenerateLegalOpinion()) {
            Notification::make()
                ->title('Não é possível gerar parecer')
                ->body('A análise precisa estar concluída e não pode haver outro parecer em processamento.')
                ->warning()
                ->send();
            return;
        }

        try {
            // Dispara o job de geração do parecer
            GenerateLegalOpinionJob::dispatch($this->latestAnalysis->id);

            $this->isGeneratingLegalOpinion = true;

            Notification::make()
                ->title('Gerando Parecer Jurídico')
                ->body('O parecer jurídico está sendo gerado. Você será notificado quando concluir.')
                ->success()
                ->send();

            // Recarrega última análise
            $this->loadLatestAnalysis();

            Log::info('Geração de parecer jurídico iniciada', [
                'analysis_id' => $this->latestAnalysis->id,
                'user_id' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao iniciar geração de parecer jurídico', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Notification::make()
                ->title('Erro ao gerar parecer')
                ->body('Ocorreu um erro ao processar sua solicitação. Tente novamente.')
                ->danger()
                ->send();
        }
    }
}
