<?php

namespace App\Filament\Pages;

use App\Jobs\AnalyzeContractJob;
use App\Models\ContractAnalysis as ContractAnalysisModel;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;

class ContractAnalysis extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';
    protected string $view = 'filament.pages.contract-analysis';
    protected static ?string $navigationLabel = 'Análise de Contratos';
    protected static ?string $title = 'Análise de Contratos';

    // Propriedades do upload
    public ?string $uploadedFilePath = null;
    public ?string $uploadedFileName = null;
    public ?int $uploadedFileSize = null;
    public bool $isAnalyzing = false;

    // Última análise
    public ?ContractAnalysisModel $latestAnalysis = null;

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
     * Inicia a análise do contrato
     */
    public function analyzeContract(): void
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
                'user_id' => Auth::id()
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

        if ($this->latestAnalysis && $this->latestAnalysis->isCompleted()) {
            $this->isAnalyzing = false;
        }
    }
}
