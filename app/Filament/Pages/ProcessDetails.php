<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;

class ProcessDetails extends Page
{
    protected static string|BackedEnum|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Detalhes do Processo';

    protected string $view = 'filament.pages.process-details';

    public array $dadosBasicos = [];
    public array $movimentos = [];
    public array $documentos = [];
    public string $numeroProcesso = '';
    public ?int $judicialUserId = null;
    public ?string $senha = null;

    public function mount(): void
    {
        // Tenta pegar a chave do cache da query string
        $cacheKey = request()->query('key');

        if ($cacheKey && cache()->has($cacheKey)) {
            $data = cache()->get($cacheKey);
            $this->dadosBasicos = $data['dadosBasicos'] ?? [];
            $this->movimentos = $data['movimentos'] ?? [];
            $this->documentos = $data['documentos'] ?? [];
            $this->numeroProcesso = $data['numeroProcesso'] ?? '';
            $this->judicialUserId = $data['judicial_user_id'] ?? null;
            $this->senha = $data['senha'] ?? null;
        } else {
            // Fallback para sessão (compatibilidade)
            $this->dadosBasicos = session('dadosBasicos', []);
            $this->movimentos = session('movimentos', []);
            $this->documentos = session('documentos', []);
            $this->numeroProcesso = session('numeroProcesso', '');

            session()->forget(['dadosBasicos', 'movimentos', 'documentos', 'numeroProcesso']);
        }
    }

    public function getTitle(): string
    {
        return $this->numeroProcesso ?: 'Detalhes do Processo';
    }

    public function getHeading(): string
    {
        return $this->numeroProcesso ?: 'Detalhes do Processo';
    }

    public function getSubheading(): ?string
    {
        if (!empty($this->dadosBasicos['dataAjuizamento'])) {
            return 'Ajuizado em ' . \Carbon\Carbon::parse($this->dadosBasicos['dataAjuizamento'])->format('d/m/Y');
        }
        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('voltar')
                ->label('Voltar')
                ->color('gray')
                ->icon('heroicon-m-arrow-left')
                ->url(route('filament.admin.pages.process-analysis')),
        ];
    }
}
