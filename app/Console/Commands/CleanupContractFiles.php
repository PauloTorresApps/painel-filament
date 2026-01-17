<?php

namespace App\Console\Commands;

use App\Models\ContractAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupContractFiles extends Command
{
    protected $signature = 'contracts:cleanup
                            {--dry-run : Apenas mostra o que seria deletado, sem remover}';

    protected $description = 'Remove arquivos de contratos de análises já concluídas ou com falha';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Modo dry-run: nenhum arquivo será removido.');
        }

        // Busca análises concluídas ou com falha que ainda têm arquivo
        $analyses = ContractAnalysis::whereIn('status', ['completed', 'failed'])
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->get();

        $this->info("Encontradas {$analyses->count()} análises com arquivos para limpar.");

        $deleted = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($analyses as $analysis) {
            $this->line("- [{$analysis->id}] {$analysis->file_name}");

            if (!Storage::exists($analysis->file_path)) {
                $this->warn("  Arquivo não encontrado no storage");

                if (!$dryRun) {
                    $analysis->update(['file_path' => null]);
                }

                $notFound++;
                continue;
            }

            if ($dryRun) {
                $this->info("  Seria deletado: {$analysis->file_path}");
                $deleted++;
                continue;
            }

            try {
                Storage::delete($analysis->file_path);
                $analysis->update(['file_path' => null]);
                $this->info("  Arquivo removido com sucesso");
                $deleted++;
            } catch (\Exception $e) {
                $this->error("  Erro ao remover: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info("Resumo:");
        $this->line("  - Arquivos removidos: {$deleted}");
        $this->line("  - Arquivos não encontrados: {$notFound}");
        $this->line("  - Erros: {$errors}");

        // Limpa também arquivos órfãos na pasta contracts que não têm registro
        $this->newLine();
        $this->info("Verificando arquivos órfãos na pasta contracts...");

        $orphanFiles = $this->findOrphanFiles();

        if (count($orphanFiles) > 0) {
            $this->warn("Encontrados " . count($orphanFiles) . " arquivos órfãos:");

            foreach ($orphanFiles as $file) {
                $this->line("  - {$file}");

                if (!$dryRun) {
                    try {
                        Storage::delete($file);
                        $this->info("    Removido");
                    } catch (\Exception $e) {
                        $this->error("    Erro: {$e->getMessage()}");
                    }
                }
            }
        } else {
            $this->info("Nenhum arquivo órfão encontrado.");
        }

        return Command::SUCCESS;
    }

    /**
     * Encontra arquivos na pasta contracts que não têm registro correspondente
     */
    private function findOrphanFiles(): array
    {
        $orphans = [];
        $files = Storage::files('contracts');

        foreach ($files as $file) {
            $hasRecord = ContractAnalysis::where('file_path', $file)->exists();

            if (!$hasRecord) {
                $orphans[] = $file;
            }
        }

        return $orphans;
    }
}
